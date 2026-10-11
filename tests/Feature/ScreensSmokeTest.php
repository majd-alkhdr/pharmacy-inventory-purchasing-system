<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ImportFileType;
use App\Enums\ImportLogStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseScenario;
use App\Models\CentralInventory;
use App\Models\DiscrepancyReason;
use App\Models\ImportLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Pharmacy;
use App\Models\PharmacyInventory;
use App\Models\PharmacyMinimumStock;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\SupplierCompany;
use App\Models\User;
use App\Services\Inventory\ParLevelReplenishmentService;
use App\Services\Inventory\PharmacyTransferSuggestionService;
use App\Services\Inventory\ReverseTransferRecorderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🎯 فحص تشغيلي لكل شاشة جديدة/معدَّلة بالمراحل أ، ب، ج: تُعرض فعلاً (200) ببيانات حقيقية —
 * يلتقط أخطاء Blade وقت التشغيل (خاصية غير موجودة، مسار باسم خاطئ) التي لا يكشفها view:cache.
 */
final class ScreensSmokeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Invoice $invoice;
    private PurchaseOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create([
            'key' => 'all_test', 'name' => 'كل الصلاحيات',
            'can_view_transfers_operations' => true, 'can_view_transfers_reports' => true,
            'can_view_purchasing_operations' => true, 'can_view_purchasing_reports' => true,
        ]);
        $this->user = User::create(['name' => 'مختبر', 'email' => 'all@test.local', 'password' => 'secret123', 'role_id' => $role->id, 'is_active' => true]);

        $log = ImportLog::create(['file_type' => ImportFileType::WarehouseMatrix, 'original_filename' => 'm.xlsx', 'status' => ImportLogStatus::Succeeded, 'started_at' => now(), 'finished_at' => now(), 'user_id' => $this->user->id, 'rows_succeeded' => 3, 'rows_warned' => 1, 'diagnostics' => ['counts' => ['skipped_row' => 1], 'buckets' => [], 'samples' => ['skipped_row' => [['row' => 4, 'reason' => 'x']]]]]);
        ImportLog::create(['file_type' => ImportFileType::PharmacyMinimumStock, 'original_filename' => 'std.xlsx', 'status' => ImportLogStatus::Succeeded, 'started_at' => now(), 'finished_at' => now()]);

        $rich = Pharmacy::create(['name' => 'صيدلية فائضة']);
        $poor = Pharmacy::create(['name' => 'صيدلية محتاجة']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ']);
        CentralInventory::create(['product_id' => $product->id, 'quantity' => 40]);
        PharmacyInventory::create(['pharmacy_id' => $rich->id, 'product_id' => $product->id, 'quantity' => 100]);
        PharmacyMinimumStock::create(['pharmacy_id' => $rich->id, 'product_id' => $product->id, 'minimum_quantity' => 20]);
        PharmacyInventory::create(['pharmacy_id' => $poor->id, 'product_id' => $product->id, 'quantity' => 0]);
        PharmacyMinimumStock::create(['pharmacy_id' => $poor->id, 'product_id' => $product->id, 'minimum_quantity' => 30]);

        (new ParLevelReplenishmentService(new ReverseTransferRecorderService()))->replenish([$rich->id]);
        $this->app->make(PharmacyTransferSuggestionService::class)->refresh();

        $this->invoice = Invoice::create(['pharmacy_id' => $poor->id, 'date' => now()->toDateString(), 'status' => 'processed']);
        InvoiceItem::create(['invoice_id' => $this->invoice->id, 'product_id' => $product->id, 'requested_qty' => 10, 'supplied_qty' => 10, 'shortage_qty' => 0]);

        $supplier = Supplier::create(['name' => 'مورد', 'is_active' => true]);
        SupplierCompany::create(['supplier_id' => $supplier->id, 'company_name' => 'شركة أ']);
        $this->order = PurchaseOrder::create(['supplier_id' => $supplier->id, 'status' => PurchaseOrderStatus::Draft, 'scenario' => PurchaseScenario::PriorityBased, 'forecast_period_days' => 30, 'generated_at' => now(), 'total_estimated_cost' => 0]);
        PurchaseOrderItem::create(['purchase_order_id' => $this->order->id, 'product_id' => $product->id, 'suggested_qty' => 5, 'unit_cost' => 0, 'line_total_cost' => 0, 'explanation_summary' => 'x']);

        $this->actingAs($this->user);
    }

    public function test_phase_a_and_b_screens_render(): void
    {
        $this->get(route('import-history.index'))->assertOk()->assertSee('m.xlsx');
        $this->get(route('import-history.show', ImportLog::first()->id))->assertOk();
        $this->get(route('inventory.standard-stock.lookup'))->assertOk();
        $this->get(route('inventory.standard-stock.lookup', ['product_id' => Product::first()->id]))->assertOk()->assertSee('صيدلية فائضة');
        $this->get(route('inventory.pharmacy-minimum-stock.form'))->assertOk()->assertSee('std.xlsx');
        $this->get(route('inventory.upload.form'))->assertOk()->assertSee('invoice_types[]', false);
        $this->get(route('inventory.invoice.show', $this->invoice->id))->assertOk()->assertSee('حفظ التعديلات');
        $this->get(route('inventory.reverse-transfers.report'))->assertOk()->assertSee('مقترحة بانتظار التنفيذ');
        $this->get(route('inventory.reverse-transfer.show', \App\Models\ReverseTransfer::first()->id))->assertOk();
        $this->get(route('inventory.pharmacy-transfers.review'))->assertOk();
        $this->get(route('inventory.pharmacy-transfer.show', \App\Models\PharmacyTransfer::first()->id))->assertOk();
        $this->get(route('inventory.invoice.reconciliation', $this->invoice->id))->assertOk();
        $this->get(route('inventory.finance-settlements.index'))->assertOk();
    }

    public function test_invoice_edit_and_reconciliation_flow_over_http(): void
    {
        $item = $this->invoice->items()->first();

        $this->put(route('inventory.invoice.items.update', $this->invoice->id), ['items' => [$item->id => ['supplied_qty' => 7]]])
            ->assertRedirect(route('inventory.invoice.show', $this->invoice->id));
        $this->assertSame(7, (int) $item->fresh()->supplied_qty);
        $this->get(route('inventory.invoice.show', $this->invoice->id))->assertOk()->assertSee('سجل تعديلات هذه الفاتورة');

        // التصدير يقفل التعديل.
        $this->get(route('inventory.invoice.excel', $this->invoice->id))->assertOk();
        $this->assertNotNull($this->invoice->fresh()->exported_at);
        $this->put(route('inventory.invoice.items.update', $this->invoice->id), ['items' => [$item->id => ['supplied_qty' => 1]]])
            ->assertSessionHas('error');

        $this->get(route('inventory.invoice.reconciliation.template', $this->invoice->id))->assertOk();
    }

    public function test_reconciliation_page_with_a_finance_referral_renders(): void
    {
        $reconciliation = \App\Models\TransferReconciliation::create(['invoice_id' => $this->invoice->id, 'items_count' => 1, 'mismatched_count' => 1]);
        $item = $reconciliation->items()->create(['product_id' => Product::first()->id, 'invoiced_qty' => 10, 'prepared_qty' => 6, 'difference' => -4]);

        $this->post(route('inventory.reconciliation-item.reason', $item->id), ['discrepancy_reason_id' => DiscrepancyReason::where('code', 'phantom_stock')->value('id')])
            ->assertSessionHas('success');

        $this->get(route('inventory.invoice.reconciliation', $this->invoice->id))->assertOk()->assertSee('محالة للمالية');
        $this->get(route('inventory.finance-settlements.index'))->assertOk()->assertSee('بنادول');
        $this->get(route('inventory.finance-settlements.export'))->assertOk();
    }

    public function test_phase_c_screens_render(): void
    {
        $this->get(route('purchasing.orders.create'))->assertOk()->assertSee('supplier_scope_mode', false);
        $this->getJson(route('purchasing.orders.suppliers-for-company', ['company' => 'شركة أ']))->assertOk()->assertJsonPath('suppliers.0.name', 'مورد');
        $this->get(route('purchasing.orders.show', $this->order->id))->assertOk()
            ->assertSee('الكمية المتوفرة في الصناعة')
            ->assertSee('مناقلات؟')
            ->assertDontSee('>بونص<', false);
        $this->get(route('purchasing.orders.export-excel', $this->order->id))->assertOk();
        $this->get(route('purchasing.orders.export-all'))->assertOk();
    }
}
