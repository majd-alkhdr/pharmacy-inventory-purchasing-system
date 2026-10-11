<?php

declare(strict_types=1);

namespace App\Services\Inventory\Reconciliation;

use App\Enums\AuditAction;
use App\Models\FinanceSettlementItem;
use App\Services\Shared\Audit\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 (ب.3) يجهّز بنود التسوية للتصدير ويعلّمها "صُدِّرت" مع سجل نشاط — بمعاملة واحدة، فلا يمكن
 * أن تُعلَّم بنود مصدَّرة دون أثر بالسجل أو العكس. التصدير الافتراضي = غير المصدَّر بعد فقط
 * (ما يجب إرساله للمالية الآن)؛ "الكل" متاح لإعادة الإرسال عند الحاجة دون تغيير تواريخ التصدير الأولى.
 */
final class FinanceSettlementExportService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return Collection<int, FinanceSettlementItem> */
    public function takeForExport(bool $includeAlreadyExported): Collection
    {
        return DB::transaction(function () use ($includeAlreadyExported): Collection {
            $items = FinanceSettlementItem::query()
                ->with(['invoice:id,date', 'pharmacy:id,name', 'product:id,name,barcode,company', 'reason:id,label'])
                ->when(!$includeAlreadyExported, fn ($q) => $q->pendingExport())
                ->orderBy('pharmacy_id')->orderBy('invoice_id')
                ->lockForUpdate()
                ->get();

            $now = now();
            foreach ($items->whereNull('exported_at') as $item) {
                $item->update(['exported_at' => $now]);
                $this->audit->record(AuditAction::FinanceSettlementExported, $item, null, ['exported_at' => $now->toDateTimeString()], ['invoice_id' => $item->invoice_id]);
            }

            return $items;
        });
    }
}
