<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\CentralInventory;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Pharmacy;
use App\Models\Product;
use App\Services\Inventory\InvoiceEditing\InvoiceEditingService;
use App\Services\Inventory\InvoiceEditing\InvoiceExportLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/** 🎯 (ب.2) تعديل فاتورة المناقلة قبل التصدير: كميات/حذف/إضافة + المركزي + سجل النشاط. */
final class InvoiceEditingTest extends TestCase
{
    use RefreshDatabase;

    private Invoice $invoice;
    private Product $a;
    private Product $b;
    private InvoiceItem $itemA;

    protected function setUp(): void
    {
        parent::setUp();

        $pharmacy = Pharmacy::create(['name' => 'صيدلية أ']);
        $this->a = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'ش']);
        $this->b = Product::create(['barcode' => '222', 'name' => 'أموكسيل', 'company' => 'ش']);
        CentralInventory::create(['product_id' => $this->a->id, 'quantity' => 50]);
        CentralInventory::create(['product_id' => $this->b->id, 'quantity' => 5]);

        $this->invoice = Invoice::create(['pharmacy_id' => $pharmacy->id, 'date' => now()->toDateString(), 'status' => 'processed']);
        $this->itemA = InvoiceItem::create(['invoice_id' => $this->invoice->id, 'product_id' => $this->a->id, 'requested_qty' => 10, 'supplied_qty' => 10, 'shortage_qty' => 0]);
    }

    private function service(): InvoiceEditingService
    {
        return $this->app->make(InvoiceEditingService::class);
    }

    private function central(Product $p): int
    {
        return (int) CentralInventory::where('product_id', $p->id)->value('quantity');
    }

    public function test_lowering_a_quantity_returns_the_difference_to_central_and_logs_suggested_vs_final(): void
    {
        $summary = $this->service()->apply($this->invoice, [$this->itemA->id => '6.4'], [], []);

        $this->assertSame(1, $summary->changed);
        $this->itemA->refresh();
        $this->assertSame(6, (int) $this->itemA->supplied_qty);              // تدوير مرة واحدة (أ.5)
        $this->assertSame(10, $this->itemA->systemQuantity());               // اقتراح النظام محفوظ
        $this->assertSame(54, $this->central($this->a));                     // 50 + (10 − 6)

        $log = AuditLog::firstOrFail();
        $this->assertSame(AuditAction::InvoiceItemQuantityChanged, $log->action);
        $this->assertSame(['supplied_qty' => 10], $log->old_values);
        $this->assertSame(['supplied_qty' => 6], $log->new_values);
        $this->assertSame(10, $log->context['system_suggested_qty']);
    }

    public function test_second_edit_keeps_the_original_system_suggestion(): void
    {
        $this->service()->apply($this->invoice, [$this->itemA->id => 6], [], []);
        $this->service()->apply($this->invoice, [$this->itemA->id => 8], [], []);

        $this->assertSame(10, $this->itemA->fresh()->systemQuantity());
        $this->assertSame(2, AuditLog::count());
    }

    public function test_removing_and_adding_items_adjusts_central_and_keeps_a_snapshot_of_the_removed_line(): void
    {
        $this->service()->apply($this->invoice, [], [$this->itemA->id], [['product_id' => $this->b->id, 'quantity' => 3]]);

        $this->assertNull(InvoiceItem::find($this->itemA->id));
        $this->assertSame(60, $this->central($this->a));
        $added = InvoiceItem::where('product_id', $this->b->id)->firstOrFail();
        $this->assertTrue($added->is_manually_added);
        $this->assertSame(0, $added->systemQuantity());
        $this->assertSame(2, $this->central($this->b));

        $removedLog = AuditLog::where('action', AuditAction::InvoiceItemRemoved)->firstOrFail();
        $this->assertSame(10, $removedLog->old_values['supplied_qty']);
        $this->assertSame(1, AuditLog::whereContext('invoice_id', $this->invoice->id)->where('action', AuditAction::InvoiceItemAdded)->count());
    }

    public function test_adding_a_product_already_on_the_invoice_increases_its_line_instead_of_duplicating(): void
    {
        $this->service()->apply($this->invoice, [], [], [['product_id' => $this->a->id, 'quantity' => 5]]);

        $this->assertSame(1, InvoiceItem::where('invoice_id', $this->invoice->id)->count());
        $this->assertSame(15, (int) $this->itemA->fresh()->supplied_qty);
        $this->assertSame(45, $this->central($this->a));
    }

    public function test_increase_beyond_central_stock_is_refused_and_nothing_is_written(): void
    {
        try {
            $this->service()->apply($this->invoice, [$this->itemA->id => 7], [], [['product_id' => $this->b->id, 'quantity' => 6]]);
            $this->fail('كان يجب رفض زيادة تتجاوز رصيد المستودع.');
        } catch (RuntimeException) {
        }

        $this->assertSame(10, (int) $this->itemA->fresh()->supplied_qty);
        $this->assertSame(50, $this->central($this->a));
        $this->assertSame(0, AuditLog::count());
    }

    public function test_an_exported_invoice_is_locked(): void
    {
        $this->app->make(InvoiceExportLock::class)->lockOne($this->invoice);

        $this->expectException(RuntimeException::class);
        $this->service()->apply($this->invoice->fresh(), [$this->itemA->id => 3], [], []);
    }
}
