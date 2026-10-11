<?php

declare(strict_types=1);

namespace App\Services\Inventory\Surplus;

use App\Models\ExcludedCompany;
use App\Models\PharmacyInventory;
use App\Models\PharmacyMinimumStock;
use App\Models\SurplusCommitment;
use App\Services\Inventory\ParLevelCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * 🎯 (أ.3) خدمة الفوائض المشتركة — **المصدر الوحيد** لسؤال "كم فائضاً متاحاً الآن
 * لدى صيدلية X من المادة Y؟". مستهلكوها الثلاثة:
 *   1) المناقلات العكسية (ب.6): تنفيذ مقترحة صيدلية ← مستودع.
 *   2) المناقلات بين الصيدليات (ب.7): المخطِّط يقترح من الفائض المتاح فقط، والتنفيذ يحجزه.
 *   3) زر "مناقلات؟" بالمشتريات (ج.3): من يملك فائضاً من مادة نوشك على شرائها؟
 *
 * 🎓 لماذا مصدر واحد لا ثلاثة حسابات مستقلة؟ قبل هذه الخدمة كان InterPharmacyTransferPlanner
 * يحسب الفائض بنفسه، ومحرك الترميم يحسبه بنفسه لينشئ العكسيات — فنفس الوحدات الفائضة
 * تُقترح مرتين (عكسية + بين صيدليات) ولا شيء يمنع تنفيذ الاثنين. الآن: الحساب الفيزيائي
 * واحد (ParLevelCalculator — نفس رياضيات الترميم حرفياً)، والالتزامات تُطرح منه بمكان واحد.
 *
 * 🎓 آلية الالتزام (Commitment): تنفيذ أي بند يحجز كميته فوراً بجدول surplus_commitments
 * داخل معاملة التنفيذ نفسها. أي قراءة لاحقة — من أي من المستهلكين الثلاثة، من أي موظف —
 * ترى الفائض منقوصاً فوراً، بلا انتظار رفع ملف جديد. هذا يحل تزامن "موظف المناقلات ↔
 * موظف المشتريات" على نفس الفائض.
 *
 * ⚖️ لماذا لا نُعدِّل pharmacy_inventories عند التنفيذ بدل جدول التزامات؟ راجع مايجريشن
 * create_surplus_commitments_table: اللقطة "حقيقة مرفوعة" تُستبدل كاملة كل دورة؛ خلطها
 * بخطط التحريك يكسر ذلك. والدورة الجديدة تبدأ بلا التزامات تلقائياً لأن كل استعلام هنا
 * مقيَّد بمعرّف الدورة — "لا تنفيذ جزئي عابر للدورات" مضمون بالبنية، لا بمنطق تنظيف.
 */
final class SurplusEngine
{
    public function __construct(
        private readonly InventoryCycleResolver $cycleResolver,
        private readonly ParLevelCalculator $calculator,
    ) {
    }

    public function currentCycle(): InventoryCycle
    {
        return $this->cycleResolver->current();
    }

    /**
     * لقطة الفائض المتاح الآن (بعد التزامات الدورة الحالية).
     *
     * @param list<int>|null $productIds حصر الحساب بمواد معيّنة (زر "مناقلات؟" يحتاج مادة واحدة —
     *                                    لا داعي لتقييم عشرات آلاف الأزواج). null = كل المواد.
     */
    public function snapshot(?array $productIds = null): SurplusSnapshot
    {
        $cycle = $this->currentCycle();

        $physical = $this->physicalExcess($productIds);
        if ($physical === []) {
            return new SurplusSnapshot($cycle, []);
        }

        $committed = $this->committedInCycle($cycle, $productIds);

        $entries = [];
        foreach ($physical as $key => $row) {
            $entries[$key] = new SurplusEntry(
                pharmacyId: $row['pharmacy_id'],
                productId: $row['product_id'],
                standardQuantity: $row['standard'],
                currentStock: $row['current'],
                physicalExcess: $row['excess'],
                committed: $committed[$key] ?? 0,
            );
        }

        return new SurplusSnapshot($cycle, $entries);
    }

    /**
     * يحجز $quantity من فائض الزوج للبند $committable — **إن كانت متاحة بالكامل الآن**.
     *
     * ⚠️ يجب استدعاؤها داخل DB::transaction للمُستدعي (التنفيذ + الالتزام + سجل النشاط
     * ذرّية واحدة — قاعدة 3). القفل على صف pharmacy_inventories للزوج يُسلسِل أي تنفيذين
     * متزامنين على نفس الفائض: الثاني ينتظر الأول، ثم يعيد الحساب فيرى التزامه.
     * (لماذا ذلك الصف تحديداً؟ وجوده شرط لوجود فائض أصلاً — فهو موجود دائماً حين يهم القفل،
     * بخلاف صفوف الالتزامات التي قد لا يوجد منها شيء بعد فلا يُقفل شيء.)
     *
     * @return bool false = الفائض المتاح أقل من الكمية (التُزم بجزء منه لمناقلة أخرى) — لا حجز إطلاقاً.
     */
    public function commitIfAvailable(InventoryCycle $cycle, int $pharmacyId, int $productId, int $quantity, Model $committable): bool
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('SurplusEngine::commitIfAvailable() يجب أن تُستدعى داخل معاملة قاعدة بيانات.');
        }

        if ($quantity <= 0) {
            return false;
        }

        PharmacyInventory::where('pharmacy_id', $pharmacyId)->where('product_id', $productId)->lockForUpdate()->first();

        $entry = $this->snapshotForPair($cycle, $pharmacyId, $productId);
        if ($entry === null || $entry->available() < $quantity) {
            return false;
        }

        SurplusCommitment::create([
            'cycle_import_log_id' => $cycle->id,
            'pharmacy_id' => $pharmacyId,
            'product_id' => $productId,
            'quantity' => $quantity,
            'committable_type' => $committable->getMorphClass(),
            'committable_id' => $committable->getKey(),
            'committed_by' => auth()->id(),
        ]);

        return true;
    }

    /** حساب طازج لزوج واحد (بلا لقطة كاملة) — مسار التنفيذ تحت القفل. */
    private function snapshotForPair(InventoryCycle $cycle, int $pharmacyId, int $productId): ?SurplusEntry
    {
        $row = $this->physicalExcess([$productId], $pharmacyId)[SurplusSnapshot::key($pharmacyId, $productId)] ?? null;
        if ($row === null) {
            return null;
        }

        $committed = (int) SurplusCommitment::query()->inCycle($cycle->id)
            ->where('pharmacy_id', $pharmacyId)->where('product_id', $productId)
            ->sum('quantity');

        return new SurplusEntry($pharmacyId, $productId, $row['standard'], $row['current'], $row['excess'], $committed);
    }

    /**
     * الفائض الفيزيائي الفعّال لكل زوج له مخزون قياسي **وصف رصيد مرفوع** — نفس قواعد
     * الأهلية حرفياً بمحرك الترميم والمخطِّط: لا صف رصيد = "لا نعرف" (لا نفترضه صفراً)،
     * والشركات المستثناة خارج الحساب.
     *
     * @param list<int>|null $productIds
     * @return array<string, array{pharmacy_id: int, product_id: int, standard: int, current: int, excess: int}>
     */
    private function physicalExcess(?array $productIds, ?int $pharmacyId = null): array
    {
        $standards = PharmacyMinimumStock::query()
            ->with('product:id,company,pack_size,min_reorder_gap')
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds))
            ->when($pharmacyId !== null, fn ($q) => $q->where('pharmacy_id', $pharmacyId))
            ->get();

        if ($standards->isEmpty()) {
            return [];
        }

        $stock = PharmacyInventory::query()
            ->whereIn('product_id', $standards->pluck('product_id')->unique()->values())
            ->when($pharmacyId !== null, fn ($q) => $q->where('pharmacy_id', $pharmacyId))
            ->get(['pharmacy_id', 'product_id', 'quantity'])
            ->mapWithKeys(fn (PharmacyInventory $r) => [SurplusSnapshot::key((int) $r->pharmacy_id, (int) $r->product_id) => (int) $r->quantity]);

        $excluded = ExcludedCompany::currentList();
        $result = [];

        foreach ($standards as $standard) {
            $key = SurplusSnapshot::key((int) $standard->pharmacy_id, (int) $standard->product_id);

            if (!$stock->has($key) || in_array($standard->product->company ?? null, $excluded, true)) {
                continue;
            }

            $decision = $this->calculator->evaluate(
                standardQuantity: (int) $standard->minimum_quantity,
                currentStock: $stock[$key],
                minReorderGap: $standard->effectiveMinReorderGap(),
                packSize: $standard->effectivePackSize(),
            );

            if ($decision->effectiveExcess > 0) {
                $result[$key] = [
                    'pharmacy_id' => (int) $standard->pharmacy_id,
                    'product_id' => (int) $standard->product_id,
                    'standard' => (int) $standard->minimum_quantity,
                    'current' => $stock[$key],
                    'excess' => $decision->effectiveExcess,
                ];
            }
        }

        return $result;
    }

    /**
     * @param list<int>|null $productIds
     * @return array<string, int>
     */
    private function committedInCycle(InventoryCycle $cycle, ?array $productIds): array
    {
        return SurplusCommitment::query()->inCycle($cycle->id)
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds))
            ->selectRaw('pharmacy_id, product_id, SUM(quantity) as total')
            ->groupBy('pharmacy_id', 'product_id')
            ->get()
            ->mapWithKeys(fn ($r) => [SurplusSnapshot::key((int) $r->pharmacy_id, (int) $r->product_id) => (int) $r->total])
            ->all();
    }
}
