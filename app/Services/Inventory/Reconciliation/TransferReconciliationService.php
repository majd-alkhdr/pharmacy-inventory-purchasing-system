<?php

declare(strict_types=1);

namespace App\Services\Inventory\Reconciliation;

use App\Enums\AuditAction;
use App\Models\DiscrepancyReason;
use App\Models\FinanceSettlementItem;
use App\Models\ImportLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\TransferReconciliation;
use App\Models\TransferReconciliationItem;
use App\Services\Inventory\Import\Exceptions\InvalidImportFileException;
use App\Services\Inventory\Import\Preparation\PreparationRowParser;
use App\Services\Inventory\Import\Support\ImportDiagnostics;
use App\Services\Inventory\Import\Support\MaterialNameKey;
use App\Services\Shared\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 (ب.3) مطابقة فاتورة مناقلة مع ما حُضِّر فعلياً بالمستودع، وتصنيف كل فرق بسبب.
 *
 * 🎓 لماذا خطوة مستقلة عن ب.2 (تعديل الفاتورة) لا امتداداً لها؟ لأنهما سؤالان مختلفان بزمنين
 * مختلفين: ب.2 "ماذا **نريد** أن نرسل؟" (قبل التصدير)، ب.3 "ماذا **أُرسل فعلاً**؟" (بعد التحضير
 * المادي). خلطهما كان سيجعل فرق التحضير يُعدِّل الفاتورة نفسها فيضيع الدليل على الفرق.
 * المطابقة تقرأ الفاتورة النهائية ولا تكتب فيها شيئاً.
 *
 * 🎓 لماذا لقطة الرقمين ببند المطابقة (لا ربط حي بالفاتورة)؟ لو عُدِّلت الفاتورة لاحقاً (قبل
 * تصديرها مثلاً)، يجب أن يبقى ما قيس وقت المطابقة كما هو — هو ما بُني عليه قرار "رصيد وهمي".
 */
final class TransferReconciliationService
{
    public function __construct(
        private readonly PreparationRowParser $parser,
        private readonly PreparationNameMatcher $nameMatcher,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @throws InvalidImportFileException رؤوس غير مطابقة أو ملف تالف — قبل أي كتابة
     */
    public function reconcile(Invoice $invoice, string $absolutePath, ?ImportLog $log = null): ReconciliationOutcome
    {
        $diagnostics = new ImportDiagnostics();
        [$preparedByKey, $rowsInFile] = $this->readPrepared($absolutePath, $diagnostics);

        $invoiced = InvoiceItem::where('invoice_id', $invoice->id)->with('product:id,name')->get()->groupBy('product_id');
        $invoiceProductNames = $invoiced->map(fn ($items) => (string) $items->first()->product->name)->all();

        $lines = [];
        foreach ($invoiced as $productId => $items) {
            $lines[(int) $productId] = ['invoiced' => (int) $items->sum('supplied_qty'), 'prepared' => 0];
        }

        foreach ($this->nameMatcher->match($preparedByKey, $invoiceProductNames) as $key => $match) {
            ['name' => $name, 'row' => $row, 'qty' => $qty] = $preparedByKey[$key];

            switch ($match->kind) {
                case NameMatchKind::Approximate:
                    $diagnostics->recordApproximateProductName($row, $name, $invoiceProductNames[$match->productId]);
                    // no break — تُخصَّص كما التامة، والتحذير يطلب المراجعة.
                case NameMatchKind::Exact:
                    // عدة أسماء قد تطابق نفس المادة (تام + تقريبي) — تُجمع.
                    $lines[$match->productId]['prepared'] += $qty;
                    break;
                case NameMatchKind::NotInDocument:
                    // مادة محضَّرة ليست بالفاتورة (تبديل بالخطأ غالباً) — فرق موجب كامل، وتحذير مرئي.
                    $diagnostics->recordProductNotInDocument($row, $name);
                    $lines[$match->productId] ??= ['invoiced' => 0, 'prepared' => 0];
                    $lines[$match->productId]['prepared'] += $qty;
                    break;
                case NameMatchKind::Ambiguous:
                    // لا تخمين: سطور الفاتورة المعنية تظهر بفرق (محضَّر 0) والتحذير يشرح السبب.
                    $diagnostics->recordAmbiguousProductName($row, $name);
                    break;
                case NameMatchKind::Unknown:
                    $diagnostics->recordUnknownProductName($row, $name);
                    break;
            }
        }

        $reconciliation = DB::transaction(function () use ($invoice, $lines, $log): TransferReconciliation {
            $reconciliation = TransferReconciliation::create([
                'invoice_id' => $invoice->id,
                'import_log_id' => $log?->id,
                'user_id' => auth()->id(),
                'items_count' => count($lines),
                'mismatched_count' => count(array_filter($lines, fn (array $l) => $l['prepared'] !== $l['invoiced'])),
            ]);

            $now = now();
            $rows = [];
            foreach ($lines as $productId => $line) {
                $rows[] = [
                    'transfer_reconciliation_id' => $reconciliation->id,
                    'product_id' => $productId,
                    'invoiced_qty' => $line['invoiced'],
                    'prepared_qty' => $line['prepared'],
                    'difference' => $line['prepared'] - $line['invoiced'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                TransferReconciliationItem::insert($chunk);
            }

            $this->audit->record(AuditAction::ReconciliationUploaded, $reconciliation, null, [
                'items' => $reconciliation->items_count,
                'mismatched' => $reconciliation->mismatched_count,
            ], ['invoice_id' => $invoice->id]);

            return $reconciliation;
        });

        return new ReconciliationOutcome($reconciliation, $rowsInFile, $diagnostics);
    }

    /**
     * يحدد سبب فرق بند — ومعه الإحالة للمالية أو إلغاؤها، بمعاملة واحدة مع سجل النشاط.
     * السلوك كله يُقرأ من requires_finance_settlement على صف السبب: لا شرط باسم سبب بالكود.
     */
    public function setReason(TransferReconciliationItem $item, ?DiscrepancyReason $reason): void
    {
        DB::transaction(function () use ($item, $reason): void {
            $item = TransferReconciliationItem::whereKey($item->id)->lockForUpdate()->with(['reconciliation.invoice', 'reason'])->firstOrFail();

            if (!$item->hasDifference()) {
                throw new \RuntimeException('لا فرق بهذا البند — لا حاجة لسبب.');
            }

            $before = $item->reason?->code;
            $item->update(['discrepancy_reason_id' => $reason?->id, 'reason_set_by' => auth()->id(), 'reason_set_at' => now()]);

            $context = ['invoice_id' => $item->reconciliation->invoice_id, 'product_id' => $item->product_id, 'difference' => $item->difference];
            $this->audit->record(AuditAction::ReconciliationReasonSet, $item, ['reason' => $before], ['reason' => $reason?->code], $context);

            $this->syncFinanceSettlement($item, $reason, $context);
        });
    }

    /** @param array<string, mixed> $context */
    private function syncFinanceSettlement(TransferReconciliationItem $item, ?DiscrepancyReason $reason, array $context): void
    {
        $existing = FinanceSettlementItem::where('transfer_reconciliation_item_id', $item->id)->first();

        if ($reason?->requires_finance_settlement) {
            $settlement = FinanceSettlementItem::updateOrCreate(
                ['transfer_reconciliation_item_id' => $item->id],
                [
                    'invoice_id' => $item->reconciliation->invoice_id,
                    'pharmacy_id' => $item->reconciliation->invoice->pharmacy_id,
                    'product_id' => $item->product_id,
                    'discrepancy_reason_id' => $reason->id,
                    'quantity' => abs($item->difference),
                ],
            );

            if ($existing === null) {
                $this->audit->record(AuditAction::FinanceSettlementFlagged, $settlement, null, ['quantity' => $settlement->quantity, 'reason' => $reason->code], $context);
            }

            return;
        }

        if ($existing !== null) {
            $this->audit->record(AuditAction::FinanceSettlementCleared, $existing, ['quantity' => $existing->quantity], null, $context);
            $existing->delete();
        }
    }

    /**
     * يجمع الكميات المحضّرة حسب المفتاح المحافظ للاسم (اسم مكرر = مجموع، بتحذير) — Generator
     * أحادي المرور، فالعدّ والتجميع بنفس الاستهلاك الوحيد. يُحفظ أول اسم وأول صف لكل مفتاح.
     *
     * @return array{0: array<string, array{name: string, row: int, qty: int}>, 1: int}
     */
    private function readPrepared(string $absolutePath, ImportDiagnostics $diagnostics): array
    {
        $prepared = [];
        $rows = 0;

        foreach ($this->parser->parse($absolutePath, $diagnostics) as $row) {
            $rows++;
            $key = MaterialNameKey::key($row->name);
            if (isset($prepared[$key])) {
                $diagnostics->recordDuplicateProductName($row->sourceRow, $row->name);
                $prepared[$key]['qty'] += $row->preparedQty;
                continue;
            }
            $prepared[$key] = ['name' => $row->name, 'row' => $row->sourceRow, 'qty' => $row->preparedQty];
        }

        return [$prepared, $rows];
    }
}
