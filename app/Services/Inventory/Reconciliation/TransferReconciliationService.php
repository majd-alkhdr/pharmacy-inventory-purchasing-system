<?php

declare(strict_types=1);

namespace App\Services\Inventory\Reconciliation;

use App\Enums\AuditAction;
use App\Enums\ImportFileType;
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
use App\Services\Inventory\Import\Validation\CrossFileCodeValidator;
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
        private readonly CrossFileCodeValidator $crossFileValidator,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @throws InvalidImportFileException رؤوس غير مطابقة أو ملف تالف — قبل أي كتابة
     */
    public function reconcile(Invoice $invoice, string $absolutePath, ?ImportLog $log = null): ReconciliationOutcome
    {
        $diagnostics = new ImportDiagnostics();
        [$preparedByBarcode, $firstRowByBarcode, $rowsInFile] = $this->readPrepared($absolutePath, $diagnostics);

        $invoiced = InvoiceItem::where('invoice_id', $invoice->id)->with('product:id,barcode')->get()
            ->groupBy(fn (InvoiceItem $i) => (string) $i->product->barcode)
            ->map(fn ($items) => ['product_id' => (int) $items->first()->product_id, 'qty' => (int) $items->sum('supplied_qty')]);

        // (أ.4) رمز بالتحضير مجهول للنظام كلياً — تحذير بأرقام صفوفه، ولا يدخل المطابقة.
        $unknown = $this->crossFileValidator->recordUnknownProducts(
            array_diff_key($firstRowByBarcode, $invoiced->all()),
            $diagnostics,
            ImportFileType::TransferPreparation,
        );
        $productIdsByBarcode = DB::table('products')
            ->whereIn('barcode', array_map('strval', array_diff(array_keys($preparedByBarcode), $unknown, $invoiced->keys()->all())))
            ->pluck('id', 'barcode');

        $lines = [];
        foreach ($invoiced as $barcode => $row) {
            $lines[$row['product_id']] = ['invoiced' => $row['qty'], 'prepared' => $preparedByBarcode[$barcode] ?? 0];
        }
        foreach ($productIdsByBarcode as $barcode => $productId) {
            // مادة محضَّرة ليست بالفاتورة (تبديل بالخطأ غالباً) — فرق موجب كامل، وتحذير مرئي.
            $diagnostics->recordProductNotInDocument($firstRowByBarcode[$barcode] ?? 0, (string) $barcode);
            $lines[(int) $productId] = ['invoiced' => 0, 'prepared' => $preparedByBarcode[$barcode]];
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
     * يجمع الكميات المحضّرة حسب الرمز (رمز مكرر = مجموع، بتحذير) — Generator أحادي المرور،
     * فالعدّ والتجميع بنفس الاستهلاك الوحيد.
     *
     * @return array{0: array<string, int>, 1: array<string, int>, 2: int}
     */
    private function readPrepared(string $absolutePath, ImportDiagnostics $diagnostics): array
    {
        $prepared = [];
        $firstRow = [];
        $rows = 0;

        foreach ($this->parser->parse($absolutePath, $diagnostics) as $row) {
            $rows++;
            if (isset($prepared[$row->barcode])) {
                $diagnostics->recordDuplicateBarcode($row->sourceRow, $row->barcode);
            }
            $prepared[$row->barcode] = ($prepared[$row->barcode] ?? 0) + $row->preparedQty;
            $firstRow[$row->barcode] ??= $row->sourceRow;
        }

        return [$prepared, $firstRow, $rows];
    }
}
