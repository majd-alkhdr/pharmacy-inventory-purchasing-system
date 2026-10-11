<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\ExcludedCompany;
use App\Models\PharmacyInventory;
use App\Models\PharmacyMinimumStock;

/**
 * 🎯 الطلب رقم 4: يقترح مناقلات **مباشرة بين صيدليتين** لنفس المادة — صيدلية
 * لديها فائض حقيقي تتصل بصيدلية لديها فجوة حقيقية، بلا المرور بالمستودع
 * المركزي (توفير خطوة شحن كاملة). لا يكتب لقاعدة البيانات **إطلاقاً** — قراءة
 * فقط، يُعيد قائمة اقتراحات (TransferSuggestion)؛ الكتابة الفعلية والمراجعة
 * البشرية مسؤولية طبقة أعلى (راجع مايجريشن pharmacy_transfers للسبب).
 *
 * 🎓 لماذا يُعيد استخدام ParLevelCalculator بدل حساب فجوة/فائض من جديد؟ لأن
 * "هل هذه الصيدلية فوق أو تحت مخزونها القياسي لهذه المادة، وبأي مقدار بعد
 * التقريب لعبوة كاملة؟" **نفس السؤال بالضبط** الذي يُجيب عنه ذلك الكلاس
 * لمحرك الترميم القياسي وللوضع الرابع كليهما — الفرق الوحيد هنا هو **ما الذي
 * يُفعَل بالإجابة** (مطابقة بين صيدليتين، لا شحن من/إلى المستودع). ثالث
 * استخدام لنفس الكلاس النقي، يؤكد صحة استخراجه الأصلي.
 *
 * ⚖️ قرار تصميم مقصود: **لا يحاول هذا المخطِّط معرفة ما إذا كان المستودع
 * المركزي سيُغطي الفجوة بنفسه بدفعة ترميم عادية لاحقة.** يُقارِن فقط الفائض
 * الخام بالفجوة الخام عبر الصيدليات لنفس المادة، ويترك القرار النهائي (هل
 * تُنفَّذ هذه المناقلة فعلياً، أم يُنتظَر المستودع؟) لموظف العمليات البشري عند
 * المراجعة. محاولة "الذكاء" هنا (استبعاد فجوات "سيُغطيها المستودع على أي حال")
 * تتطلب توقّع ترتيب معالجة محرك لم يُشغَّل بعد — تخمين هش يصعب تدقيقه لاحقاً،
 * مقابل فائدة هامشية لأداة أصلاً بشرية المراجعة قبل أي تنفيذ.
 *
 * ⚖️ خوارزمية مطابقة **جشعة بسيطة، لا تحسيناً شاملاً مُحسَّناً رياضياً**:
 * لكل مادة، الفائض والفجوة يُرتَّبان تنازلياً (الأكبر أولاً)، ثم تُغطَّى كل
 * حاجة بالتتابع من أكبر مصادر الفائض المتاحة. هذا يُنتج مناقلات قليلة العدد
 * وسهلة الفهم والتدقيق البشري — لا بالضرورة التوزيع الأمثل رياضياً (Bipartite
 * Matching الأمثل يتطلب تعقيداً لا يستحقه هذا القرار البشري أصلاً بمراجعته).
 */
final class InterPharmacyTransferPlanner
{
    /**
     * 🆕 (أ.3/ب.7) جانب "الفائض" لم يعد يُحسب هنا — يُقرأ من SurplusEngine (المصدر الوحيد
     * المشترك مع المناقلات العكسية وزر "مناقلات؟"). الفرق الجوهري عن الحساب الذاتي القديم:
     * الفائض المقروء **منقوص منه ما التُزم به بالدورة الحالية** — فمناقلة عكسية نُفِّذت قبل
     * دقيقة لا يُعاد اقتراح فائضها هنا. جانب "الحاجة" يبقى محسوباً هنا كما كان (لا مستهلك آخر له).
     */
    public function __construct(
        private readonly ParLevelCalculator $calculator,
        private readonly \App\Services\Inventory\Surplus\SurplusEngine $surplusEngine,
    ) {
    }

    /**
     * @return list<TransferSuggestion>
     */
    public function plan(): array
    {
        $standards = PharmacyMinimumStock::with('product:id,company,pack_size,min_reorder_gap')->get();

        if ($standards->isEmpty()) {
            return [];
        }

        $excludedCompanies = ExcludedCompany::currentList();

        $currentStock = PharmacyInventory::query()
            ->get(['pharmacy_id', 'product_id', 'quantity'])
            ->keyBy(fn (PharmacyInventory $r) => "{$r->pharmacy_id}_{$r->product_id}")
            ->map(fn (PharmacyInventory $r) => (int) $r->quantity);

        /** @var array<int, list<array{pharmacyId: int, quantity: int}>> $excessByProduct */
        $excessByProduct = [];
        foreach ($this->surplusEngine->snapshot()->availableByProduct() as $productId => $entries) {
            foreach ($entries as $entry) {
                $excessByProduct[$productId][] = ['pharmacyId' => $entry->pharmacyId, 'quantity' => $entry->available()];
            }
        }

        /** @var array<int, list<array{pharmacyId: int, quantity: int, packSize: int}>> $needByProduct */
        $needByProduct = [];

        foreach ($standards as $standard) {
            $pharmacyId = (int) $standard->pharmacy_id;
            $productId = (int) $standard->product_id;
            $key = "{$pharmacyId}_{$productId}";

            // 🩹 نفس قاعدة الأهلية بالضبط المُتَّبعة بـ ParLevelReplenishmentService:
            // بلا صف جرد فعلي مرفوع لهذا الزوج، "لا نعرف" رصيده — لا نفترضه صفراً.
            if (!isset($currentStock[$key])) {
                continue;
            }

            if (in_array($standard->product->company ?? null, $excludedCompanies, true)) {
                continue;
            }

            $decision = $this->calculator->evaluate(
                standardQuantity: $standard->minimum_quantity,
                currentStock: $currentStock[$key],
                minReorderGap: $standard->effectiveMinReorderGap(),
                packSize: $standard->effectivePackSize(),
            );

            // الفائض يأتي من SurplusEngine أعلاه (بعد الالتزامات) — هنا الحاجة فقط.
            if ($decision->needsRestock()) {
                $needByProduct[$productId][] = [
                    'pharmacyId' => $pharmacyId,
                    'quantity' => $decision->effectiveGap,
                    'packSize' => $standard->effectivePackSize(),
                ];
            }
        }

        $suggestions = [];

        foreach ($needByProduct as $productId => $needyPharmacies) {
            if (!isset($excessByProduct[$productId])) {
                continue;
            }

            $suggestions = [...$suggestions, ...$this->matchOneProduct($productId, $excessByProduct[$productId], $needyPharmacies)];
        }

        return $suggestions;
    }

    /**
     * @param list<array{pharmacyId: int, quantity: int}> $excessSources
     * @param list<array{pharmacyId: int, quantity: int, packSize: int}> $needyPharmacies
     * @return list<TransferSuggestion>
     */
    private function matchOneProduct(int $productId, array $excessSources, array $needyPharmacies): array
    {
        usort($excessSources, fn (array $a, array $b): int => $b['quantity'] <=> $a['quantity']);
        usort($needyPharmacies, fn (array $a, array $b): int => $b['quantity'] <=> $a['quantity']);

        $suggestions = [];
        $excessIndex = 0;

        foreach ($needyPharmacies as $need) {
            $remainingNeed = $need['quantity'];
            $originalNeed = $need['quantity'];

            while ($remainingNeed > 0 && $excessIndex < count($excessSources)) {
                if ($excessSources[$excessIndex]['quantity'] <= 0) {
                    $excessIndex++;
                    continue;
                }

                $rawQty = min($remainingNeed, $excessSources[$excessIndex]['quantity']);
                $qty = $need['packSize'] > 0 ? intdiv($rawQty, $need['packSize']) * $need['packSize'] : $rawQty;

                if ($qty <= 0) {
                    // ما تبقّى من هذه الحاجة أصغر من عبوة كاملة — لا مصدر فائض
                    // (مهما كبر) سيجعلها قابلة للتقريب لأعلى؛ العائق هنا هو
                    // remainingNeed نفسه، لا حجم أي مصدر بعينه. توقف عن هذه الحاجة.
                    break;
                }

                $suggestions[] = new TransferSuggestion(
                    productId: $productId,
                    fromPharmacyId: $excessSources[$excessIndex]['pharmacyId'],
                    toPharmacyId: $need['pharmacyId'],
                    quantity: $qty,
                    fromPharmacyExcessAtTime: $excessSources[$excessIndex]['quantity'],
                    toPharmacyGapAtTime: $originalNeed,
                );

                $remainingNeed -= $qty;
                $excessSources[$excessIndex]['quantity'] -= $qty;
            }
        }

        return $suggestions;
    }
}
