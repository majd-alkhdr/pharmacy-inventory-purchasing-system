<?php

declare(strict_types=1);

namespace App\Services\Inventory\Transfers;

use App\Enums\AuditAction;
use App\Enums\PharmacyTransferStatus;
use App\Enums\ReverseTransferOrigin;
use App\Enums\ReverseTransferStatus;
use App\Enums\TransferItemExecutionStatus;
use App\Models\PharmacyMinimumStock;
use App\Models\PharmacyTransfer;
use App\Models\PharmacyTransferItem;
use App\Models\ReverseTransfer;
use App\Models\ReverseTransferItem;
use App\Services\Inventory\Surplus\InventoryCycle;
use App\Services\Inventory\Surplus\SurplusEngine;
use App\Services\Shared\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 🎯 (ب.6 + ب.7 + ج.3) مسار التنفيذ **الوحيد** لكل مناقلة تستهلك فائض صيدلية:
 *   - مناقلة عكسية (صيدلية ← المستودع) — مقترحة من محرك الترميم، أو منشأة من زر "مناقلات؟".
 *   - مناقلة بين صيدليتين — مقترحة من InterPharmacyTransferPlanner.
 *
 * 🎓 لماذا خدمة واحدة للنوعين لا خدمة لكل نوع؟ لأن جوهر "التنفيذ" واحد: احجز فائض
 * (الصيدلية المصدر، المادة) لكل بند عبر SurplusEngine، وما لم يُحجز يُلغى. لو كان لكل نوع
 * مسار تنفيذ خاص، لأمكن أن يحجز أحدهما الفائض بطريقة لا يراها الآخر — وهي بالضبط مشكلة
 * "نفس الكمية تُقترح مرتين" التي وُجدت الخدمة لحلها. الفرق بين النوعين (أي عمود هو المصدر،
 * أي Enum للحالة، أي إجراء بسجل النشاط) معزول بدوال صغيرة أدناه.
 *
 * 🎓 الإلغاء المتبادل بلا استعلام إضافي بأي شاشة: تنفيذ عكسية لـ(مادة، صيدلية) يحجز فائضها؛
 * أي مناقلة بين صيدليات تعتمد على نفس الفائض تصبح — عند أي قراءة لاحقة — "غير مغطاة"
 * بلقطة SurplusEngine فتختفي من قائمة المعلَّق، ولو حاول أحد تنفيذها يُلغى بندها هنا. والعكس.
 *
 * ⚖️ لا تنفيذ جزئي (أ.3): البند يُنفَّذ بكامل كميته أو يُلغى (Superseded). ومقترحة من دورة رفع
 * سابقة لا تُنفَّذ إطلاقاً — أرشيف قراءة فقط (الفائض الذي بُنيت عليه لم يعد قائماً بلقطة اليوم).
 *
 * ⚖️ المخزون (pharmacy_inventories/central_inventories) لا يُعدَّل هنا عمداً: اللقطة "حقيقة
 * مرفوعة" للدورة، والحجز بجدول الالتزامات يمنع إعادة اقتراح الكمية — والرفعة التالية تعكس
 * الحركة الفعلية. (راجع SurplusEngine للسبب الكامل.)
 *
 * كل تنفيذ = معاملة واحدة: تحديث حالة المستند والبنود + الالتزامات + سجل النشاط (قاعدة 3).
 */
final class TransferExecutionService
{
    public function __construct(
        private readonly SurplusEngine $surplusEngine,
        private readonly AuditLogger $audit,
    ) {
    }

    public function executeReverseTransfer(int $transferId): TransferExecutionOutcome
    {
        return DB::transaction(function () use ($transferId): TransferExecutionOutcome {
            $transfer = ReverseTransfer::whereKey($transferId)->lockForUpdate()->first()
                ?? throw new RuntimeException("المناقلة العكسية #{$transferId} غير موجودة.");

            if (!$transfer->isSuggested()) {
                throw new RuntimeException("المناقلة العكسية #{$transferId} ليست بانتظار التنفيذ (حالتها: {$transfer->status->label()}).");
            }

            $cycle = $this->assertCurrentCycle($transfer->cycle_import_log_id, "المناقلة العكسية #{$transferId}");
            $items = ReverseTransferItem::where('reverse_transfer_id', $transferId)->get();

            [$executed, $superseded] = $this->commitItems($cycle, (int) $transfer->pharmacy_id, $items);

            $transfer->update(['status' => ReverseTransferStatus::Executed, 'executed_at' => now(), 'executed_by' => auth()->id()]);
            $this->auditExecution(AuditAction::ReverseTransferExecuted, $transfer, $executed, $superseded);

            return new TransferExecutionOutcome($transfer->id, $executed, $superseded);
        });
    }

    public function executePharmacyTransfer(int $transferId): TransferExecutionOutcome
    {
        return DB::transaction(function () use ($transferId): TransferExecutionOutcome {
            $transfer = PharmacyTransfer::whereKey($transferId)->lockForUpdate()->first()
                ?? throw new RuntimeException("مناقلة الصيدليات #{$transferId} غير موجودة.");

            if ($transfer->status !== PharmacyTransferStatus::Suggested) {
                throw new RuntimeException("مناقلة الصيدليات #{$transferId} ليست بانتظار التنفيذ (حالتها: {$transfer->status->label()}).");
            }

            $cycle = $this->assertCurrentCycle($transfer->cycle_import_log_id, "مناقلة الصيدليات #{$transferId}");
            $items = PharmacyTransferItem::where('pharmacy_transfer_id', $transferId)->get();

            [$executed, $superseded] = $this->commitItems($cycle, (int) $transfer->from_pharmacy_id, $items);

            $transfer->update(['status' => PharmacyTransferStatus::Completed, 'executed_date' => now()->toDateString(), 'executed_by' => auth()->id()]);
            $this->auditExecution(AuditAction::PharmacyTransferExecuted, $transfer, $executed, $superseded);

            return new TransferExecutionOutcome($transfer->id, $executed, $superseded);
        });
    }

    /**
     * (ج.3) زر "مناقلات؟": يُنشئ مناقلة عكسية **ويُنفّذها** بنفس المعاملة — نفس الكيان ونفس
     * مسار الحجز بالضبط (لا نوع موازٍ). لا شيء يُحفظ قبل هذه اللحظة: "تجاهل" بالواجهة لا يصل
     * للخادم إطلاقاً، فلا سجل ولا أثر — بالضبط ما طُلب.
     *
     * ⚠️ إن لم يعد الفائض يغطي الكمية (موظف آخر حجزه بين فتح النافذة والضغط) يُرمى استثناء
     * وتتراجع المعاملة كلها — فلا يبقى مستند عكسية فارغ.
     */
    public function createAndExecuteReverseTransfer(int $pharmacyId, int $productId, int $quantity): TransferExecutionOutcome
    {
        if ($quantity <= 0) {
            throw new RuntimeException('الكمية المطلوب نقلها يجب أن تكون أكبر من صفر.');
        }

        return DB::transaction(function () use ($pharmacyId, $productId, $quantity): TransferExecutionOutcome {
            $cycle = $this->surplusEngine->currentCycle();

            $standard = PharmacyMinimumStock::where('pharmacy_id', $pharmacyId)->where('product_id', $productId)->first();
            $entry = $this->surplusEngine->snapshot([$productId])->entry($pharmacyId, $productId);

            $transfer = ReverseTransfer::create([
                'pharmacy_id' => $pharmacyId,
                'date' => now()->toDateString(),
                'status' => ReverseTransferStatus::Suggested,
                'origin' => ReverseTransferOrigin::Purchasing,
                'cycle_import_log_id' => $cycle->id,
            ]);

            $item = ReverseTransferItem::create([
                'reverse_transfer_id' => $transfer->id,
                'product_id' => $productId,
                'quantity' => $quantity,
                'standard_quantity_at_time' => (int) ($standard?->minimum_quantity ?? 0),
                'pharmacy_quantity_at_time' => $entry?->currentStock ?? 0,
            ]);

            if (!$this->surplusEngine->commitIfAvailable($cycle, $pharmacyId, $productId, $quantity, $item)) {
                throw new RuntimeException('لم يعد فائض هذه الصيدلية يغطي الكمية المطلوبة (حُجز لمناقلة أخرى للتو) — أعد فتح قائمة المناقلات.');
            }

            $item->update(['execution_status' => TransferItemExecutionStatus::Executed]);
            $transfer->update(['status' => ReverseTransferStatus::Executed, 'executed_at' => now(), 'executed_by' => auth()->id()]);
            $this->auditExecution(AuditAction::ReverseTransferExecuted, $transfer, 1, 0, ['origin' => ReverseTransferOrigin::Purchasing->value]);

            return new TransferExecutionOutcome($transfer->id, 1, 0);
        });
    }

    public function dismissReverseTransfer(int $transferId): void
    {
        DB::transaction(function () use ($transferId): void {
            $transfer = ReverseTransfer::whereKey($transferId)->lockForUpdate()->first()
                ?? throw new RuntimeException("المناقلة العكسية #{$transferId} غير موجودة.");

            if (!$transfer->isSuggested()) {
                throw new RuntimeException("المناقلة العكسية #{$transferId} ليست بانتظار التنفيذ (حالتها: {$transfer->status->label()}).");
            }

            $transfer->update(['status' => ReverseTransferStatus::Dismissed]);
            $this->audit->record(AuditAction::ReverseTransferDismissed, $transfer, ['status' => 'suggested'], ['status' => 'dismissed']);
        });
    }

    public function dismissPharmacyTransfer(int $transferId): void
    {
        DB::transaction(function () use ($transferId): void {
            $transfer = PharmacyTransfer::whereKey($transferId)->lockForUpdate()->first()
                ?? throw new RuntimeException("مناقلة الصيدليات #{$transferId} غير موجودة.");

            if ($transfer->status !== PharmacyTransferStatus::Suggested) {
                throw new RuntimeException("مناقلة الصيدليات #{$transferId} ليست بانتظار المراجعة (حالتها الحالية: {$transfer->status->label()}).");
            }

            $transfer->update(['status' => PharmacyTransferStatus::Dismissed]);
            $this->audit->record(AuditAction::PharmacyTransferDismissed, $transfer, ['status' => 'suggested'], ['status' => 'dismissed']);
        });
    }

    /**
     * مقترحة من دورة سابقة = أرشيف. لا "تنفيذ جزئي عابر للدورات": الفائض الذي اقتُرحت عليه
     * قيس بلقطة استُبدلت، وتنفيذها الآن يحجز رقماً لا علاقة له بالواقع الحالي.
     */
    private function assertCurrentCycle(?int $documentCycleId, string $label): InventoryCycle
    {
        $cycle = $this->surplusEngine->currentCycle();

        if (!$cycle->contains($documentCycleId)) {
            throw new RuntimeException("{$label} مقترحة من دورة رفع سابقة — تبقى للأرشيف فقط ولا تُنفَّذ. ارفع/راجع اقتراحات الدورة الحالية.");
        }

        return $cycle;
    }

    /**
     * يحجز فائض كل بند بكامل كميته أو يلغيه. إن لم يُنفَّذ أي بند يُرمى استثناء فتتراجع
     * المعاملة كلها: المستند يبقى "مقترحاً" (وسيختفي من القائمة لأن لا بند فيه مغطى بعد الآن).
     *
     * @param Collection<int, ReverseTransferItem|PharmacyTransferItem> $items
     * @return array{0: int, 1: int} [منفَّذ، مُلغى]
     */
    private function commitItems(InventoryCycle $cycle, int $sourcePharmacyId, Collection $items): array
    {
        $executed = 0;
        $superseded = 0;

        foreach ($items as $item) {
            $ok = $this->surplusEngine->commitIfAvailable($cycle, $sourcePharmacyId, (int) $item->product_id, (int) $item->quantity, $item);

            $item->update(['execution_status' => $ok ? TransferItemExecutionStatus::Executed : TransferItemExecutionStatus::Superseded]);
            $ok ? $executed++ : $superseded++;
        }

        if ($executed === 0) {
            throw new RuntimeException('لا يوجد بند واحد بهذه المناقلة ما زال فائضه متاحاً — التُزم به كله لمناقلات أخرى بنفس الدورة.');
        }

        return [$executed, $superseded];
    }

    /** @param array<string, mixed> $extraContext */
    private function auditExecution(AuditAction $action, Model $document, int $executed, int $superseded, array $extraContext = []): void
    {
        $this->audit->record(
            $action,
            $document,
            ['status' => 'suggested'],
            ['status' => 'executed', 'items_executed' => $executed, 'items_superseded' => $superseded],
            $extraContext,
        );
    }
}
