<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\ImportWarning;
use App\Models\DiscrepancyReason;
use App\Models\FinanceSettlementItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Pharmacy;
use App\Models\Product;
use App\Models\TransferReconciliationItem;
use App\Services\Inventory\Import\Contracts\TabularFileReader;
use App\Services\Inventory\Import\Exceptions\InvalidImportFileException;
use App\Services\Inventory\Reconciliation\FinanceSettlementExportService;
use App\Services\Inventory\Reconciliation\TransferReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\Inventory\Import\Doubles\ArrayTabularFileReader;

/** 🎯 (ب.3) مطابقة الفاتورة مع التحضير الفعلي + الإحالة للمالية حسب علَم السبب. */
final class TransferReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Invoice $invoice;
    private Product $a;
    private Product $b;
    private Product $c;

    protected function setUp(): void
    {
        parent::setUp();

        $pharmacy = Pharmacy::create(['name' => 'صيدلية أ']);
        $this->a = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'ش']);
        $this->b = Product::create(['barcode' => '222', 'name' => 'أموكسيل', 'company' => 'ش']);
        $this->c = Product::create(['barcode' => '333', 'name' => 'فيتامين', 'company' => 'ش']);

        $this->invoice = Invoice::create(['pharmacy_id' => $pharmacy->id, 'date' => now()->toDateString(), 'status' => 'processed']);
        InvoiceItem::create(['invoice_id' => $this->invoice->id, 'product_id' => $this->a->id, 'requested_qty' => 10, 'supplied_qty' => 10, 'shortage_qty' => 0]);
        InvoiceItem::create(['invoice_id' => $this->invoice->id, 'product_id' => $this->b->id, 'requested_qty' => 4, 'supplied_qty' => 4, 'shortage_qty' => 0]);
    }

    /** @param list<array<int, mixed>|null> $rows */
    private function reconcileWith(array $rows)
    {
        $this->app->instance(TabularFileReader::class, new ArrayTabularFileReader($rows));

        return $this->app->make(TransferReconciliationService::class)->reconcile($this->invoice, 'ignored.xlsx');
    }

    private function item(Product $p): TransferReconciliationItem
    {
        return TransferReconciliationItem::where('product_id', $p->id)->latest('id')->firstOrFail();
    }

    public function test_differences_are_computed_for_invoiced_missing_and_extra_products(): void
    {
        $outcome = $this->reconcileWith([
            ['رمز المادة', 'اسم المادة', 'الكمية المحضّرة'],
            ['111', 'بنادول', 7],
            // 222 غائب عن الملف ⇒ محضَّر 0
            ['333', 'فيتامين', 2],     // ليس بالفاتورة ⇒ فرق موجب + تحذير
            ['999', 'مجهول', 1],       // غير معروف للنظام ⇒ تحذير ولا يدخل المطابقة
        ]);

        $this->assertSame(3, $outcome->reconciliation->items_count);
        $this->assertSame(3, $outcome->reconciliation->mismatched_count);
        $this->assertSame(-3, $this->item($this->a)->difference);
        $this->assertSame(-4, $this->item($this->b)->difference);
        $this->assertSame(2, $this->item($this->c)->difference);
        $this->assertSame(1, $outcome->diagnostics->count(ImportWarning::ProductNotInDocument));
        $this->assertSame(1, $outcome->diagnostics->count(ImportWarning::UnknownProductCode));
    }

    public function test_a_wrong_header_is_rejected_before_any_write(): void
    {
        try {
            $this->reconcileWith([['رمز', 'شيء', 'كمية'], ['111', 'x', 1]]);
            $this->fail('كان يجب رفض رؤوس غير مطابقة.');
        } catch (InvalidImportFileException) {
        }

        $this->assertSame(0, TransferReconciliationItem::count());
    }

    public function test_a_finance_flagged_reason_refers_the_item_and_switching_away_clears_it(): void
    {
        $this->reconcileWith([['رمز المادة', 'اسم المادة', 'الكمية المحضرة'], ['111', 'بنادول', 7], ['222', 'أموكسيل', 4]]);
        $service = $this->app->make(TransferReconciliationService::class);
        $phantom = DiscrepancyReason::where('code', 'phantom_stock')->firstOrFail();
        $counting = DiscrepancyReason::where('code', 'counting_error')->firstOrFail();

        $service->setReason($this->item($this->a), $phantom);
        $settlement = FinanceSettlementItem::firstOrFail();
        $this->assertSame(3, $settlement->quantity);
        $this->assertSame($this->invoice->pharmacy_id, $settlement->pharmacy_id);

        $service->setReason($this->item($this->a), $counting);
        $this->assertSame(0, FinanceSettlementItem::count());
    }

    public function test_a_new_reason_flagged_for_finance_works_without_code_changes(): void
    {
        $this->reconcileWith([['رمز المادة', 'اسم المادة', 'الكمية المحضرة'], ['111', 'بنادول', 7], ['222', 'أموكسيل', 4]]);
        $newReason = DiscrepancyReason::create(['code' => 'expired_unrecorded', 'label' => 'منتهي الصلاحية غير مسجَّل', 'requires_finance_settlement' => true]);

        $this->app->make(TransferReconciliationService::class)->setReason($this->item($this->a), $newReason);

        $this->assertSame(1, FinanceSettlementItem::count());
    }

    public function test_export_takes_only_unexported_items_and_marks_them(): void
    {
        $this->reconcileWith([['رمز المادة', 'اسم المادة', 'الكمية المحضرة'], ['111', 'بنادول', 7], ['222', 'أموكسيل', 1]]);
        $service = $this->app->make(TransferReconciliationService::class);
        $phantom = DiscrepancyReason::where('code', 'phantom_stock')->firstOrFail();
        $service->setReason($this->item($this->a), $phantom);
        $service->setReason($this->item($this->b), $phantom);

        $export = $this->app->make(FinanceSettlementExportService::class);
        $this->assertCount(2, $export->takeForExport(false));
        $this->assertCount(0, $export->takeForExport(false));
        $this->assertCount(2, $export->takeForExport(true));
        $this->assertSame(0, FinanceSettlementItem::whereNull('exported_at')->count());
    }
}
