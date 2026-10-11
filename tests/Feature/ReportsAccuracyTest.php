<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PharmacyTransferStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseScenario;
use App\Enums\ReverseTransferStatus;
use App\Enums\TransferItemExecutionStatus;
use App\Exports\PharmacyTransferExport;
use App\Exports\ReverseTransferExport;
use App\Exports\ReverseTransfersExport;
use App\Models\CentralInventory;
use App\Models\CentralInventoryPrice;
use App\Models\Pharmacy;
use App\Models\PharmacyTransfer;
use App\Models\PharmacyTransferItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\ReverseTransfer;
use App\Models\ReverseTransferItem;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WatchedProduct;
use App\Services\Inventory\Reports\InventoryValueReportService;
use App\Services\Inventory\Reports\ProductMovementReportService;
use App\Services\Inventory\Reports\TransfersValueReportService;
use App\Services\Purchasing\PurchaseOrderEditingService;
use App\Services\Purchasing\Reports\PurchaseValueReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 🎯 تدقيق التقارير: كل اختبار يثبّت رقماً معروضاً مقابل البيانات الفعلية التي يجب أن يعكسها.
 */
final class ReportsAccuracyTest extends TestCase
{
    use RefreshDatabase;

    private Pharmacy $a;
    private Pharmacy $b;
    private Product $p1;
    private Product $p2;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create([
            'key' => 'all_reports', 'name' => 'كل الصلاحيات',
            'can_view_transfers_operations' => true, 'can_view_transfers_reports' => true,
            'can_view_purchasing_operations' => true, 'can_view_purchasing_reports' => true,
        ]);
        $this->actingAs(User::create(['name' => 'مدقق', 'email' => 'audit@test.local', 'password' => 'secret123', 'role_id' => $role->id, 'is_active' => true]));

        $this->a = Pharmacy::create(['name' => 'صيدلية أ']);
        $this->b = Pharmacy::create(['name' => 'صيدلية ب']);
        $this->p1 = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'ش']);
        $this->p2 = Product::create(['barcode' => '222', 'name' => 'سيتامول', 'company' => 'ش']);
        CentralInventoryPrice::create(['product_id' => $this->p1->id, 'price' => 10, 'effective_from' => '2026-01-01']);
        CentralInventoryPrice::create(['product_id' => $this->p2->id, 'price' => 4, 'effective_from' => '2026-01-01']);
    }

    /** مستند عكسي اقتُرح يوم 1 ونُفِّذ يوم 5: بند منفَّذ (6 من بنادول) وبند superseded (9 من سيتامول). */
    private function executedReverse(): ReverseTransfer
    {
        $rt = ReverseTransfer::create(['pharmacy_id' => $this->a->id, 'date' => '2026-03-01', 'status' => ReverseTransferStatus::Executed, 'executed_at' => '2026-03-05 10:00:00']);
        ReverseTransferItem::create(['reverse_transfer_id' => $rt->id, 'product_id' => $this->p1->id, 'quantity' => 6, 'standard_quantity_at_time' => 10, 'pharmacy_quantity_at_time' => 16, 'execution_status' => TransferItemExecutionStatus::Executed]);
        ReverseTransferItem::create(['reverse_transfer_id' => $rt->id, 'product_id' => $this->p2->id, 'quantity' => 9, 'standard_quantity_at_time' => 1, 'pharmacy_quantity_at_time' => 10, 'execution_status' => TransferItemExecutionStatus::Superseded]);

        return $rt;
    }

    public function test_reverse_transfers_screen_counts_only_lines_that_actually_moved(): void
    {
        $this->executedReverse();

        $response = $this->get(route('inventory.reverse-transfers.report'))->assertOk();
        $this->assertSame(6, (int) $response->viewData('stats')['total_quantity']);   // كانت 15 (6 + 9 superseded)
        $this->assertSame(1, $response->viewData('items')->total());
        $response->assertSee('2026-03-05');                                         // يوم التنفيذ يُعرض

        $exported = (new ReverseTransfersExport([]))->collection();
        $this->assertSame([6], $exported->pluck('quantity')->map(fn ($q) => (int) $q)->all()); // الشاشة والتصدير متطابقان
    }

    public function test_a_reverse_transfer_is_dated_on_its_execution_day_everywhere(): void
    {
        $this->executedReverse();
        CentralInventoryPrice::create(['product_id' => $this->p1->id, 'price' => 12, 'effective_from' => '2026-03-03']);

        // فلتر الشاشة/التصدير بيوم الاقتراح لا يلتقطه، وبيوم التنفيذ يلتقطه.
        $this->assertSame(0, (new ReverseTransfersExport(['date_from' => '2026-03-01', 'date_to' => '2026-03-02']))->collection()->count());
        $this->assertSame(1, (new ReverseTransfersExport(['date_from' => '2026-03-05', 'date_to' => '2026-03-05']))->collection()->count());

        // تقرير القيمة: الفترة وسعر ذلك اليوم معاً — 6 × 12 (سعر يوم 5)، لا 6 × 10 (سعر يوم 1).
        $value = $this->app->make(TransfersValueReportService::class);
        $this->assertSame(0.0, $value->build('2026-03-01', '2026-03-02')->totals->reverse_value);
        $this->assertSame(72.0, $value->build('2026-03-01', '2026-03-31')->totals->reverse_value);
    }

    public function test_product_movement_includes_executed_inter_pharmacy_transfers_and_execution_dates(): void
    {
        $this->executedReverse();
        WatchedProduct::create(['product_id' => $this->p1->id]);
        WatchedProduct::create(['product_id' => $this->p2->id]);

        $pt = PharmacyTransfer::create(['from_pharmacy_id' => $this->a->id, 'to_pharmacy_id' => $this->b->id, 'status' => PharmacyTransferStatus::Completed, 'executed_date' => '2026-03-07']);
        PharmacyTransferItem::create(['pharmacy_transfer_id' => $pt->id, 'product_id' => $this->p1->id, 'quantity' => 4, 'from_pharmacy_excess_at_time' => 5, 'to_pharmacy_gap_at_time' => 4, 'execution_status' => TransferItemExecutionStatus::Executed]);
        PharmacyTransferItem::create(['pharmacy_transfer_id' => $pt->id, 'product_id' => $this->p2->id, 'quantity' => 3, 'from_pharmacy_excess_at_time' => 3, 'to_pharmacy_gap_at_time' => 3, 'execution_status' => TransferItemExecutionStatus::Superseded]);
        // مقترحة لم تُنفَّذ — ليست حركة.
        $pending = PharmacyTransfer::create(['from_pharmacy_id' => $this->a->id, 'to_pharmacy_id' => $this->b->id, 'status' => PharmacyTransferStatus::Suggested]);
        PharmacyTransferItem::create(['pharmacy_transfer_id' => $pending->id, 'product_id' => $this->p1->id, 'quantity' => 2, 'from_pharmacy_excess_at_time' => 2, 'to_pharmacy_gap_at_time' => 2]);

        $rows = $this->app->make(ProductMovementReportService::class)->build();

        $this->assertSame(
            [['inter_pharmacy', '2026-03-07', 'صيدلية أ', 'صيدلية ب', 4.0], ['reverse', '2026-03-05', 'صيدلية أ', 'المستودع المركزي', 6.0]],
            $rows->map(fn ($r) => [$r->movement_type, substr((string) $r->date, 0, 10), $r->from_label, $r->to_label, $r->quantity])->all(),
        );

        $this->get(route('inventory.reports.product-movement'))->assertOk()->assertSee('بين صيدليتين');
    }

    public function test_single_document_exports_leave_out_superseded_lines(): void
    {
        $rt = $this->executedReverse();
        $this->assertSame([$this->p1->id], (new ReverseTransferExport($rt->id))->collection()->pluck('product_id')->all());

        $pt = PharmacyTransfer::create(['from_pharmacy_id' => $this->a->id, 'to_pharmacy_id' => $this->b->id, 'status' => PharmacyTransferStatus::Completed, 'executed_date' => '2026-03-07']);
        PharmacyTransferItem::create(['pharmacy_transfer_id' => $pt->id, 'product_id' => $this->p1->id, 'quantity' => 4, 'from_pharmacy_excess_at_time' => 5, 'to_pharmacy_gap_at_time' => 4, 'execution_status' => TransferItemExecutionStatus::Executed]);
        PharmacyTransferItem::create(['pharmacy_transfer_id' => $pt->id, 'product_id' => $this->p2->id, 'quantity' => 3, 'from_pharmacy_excess_at_time' => 3, 'to_pharmacy_gap_at_time' => 3, 'execution_status' => TransferItemExecutionStatus::Superseded]);
        $this->assertSame([$this->p1->id], (new PharmacyTransferExport($pt->id))->collection()->pluck('product_id')->all());

        // مستند لم يُنفَّذ بعد (بلا حالات بنود) يُصدَّر كاملاً.
        $suggested = PharmacyTransfer::create(['from_pharmacy_id' => $this->a->id, 'to_pharmacy_id' => $this->b->id, 'status' => PharmacyTransferStatus::Suggested]);
        PharmacyTransferItem::create(['pharmacy_transfer_id' => $suggested->id, 'product_id' => $this->p1->id, 'quantity' => 2, 'from_pharmacy_excess_at_time' => 2, 'to_pharmacy_gap_at_time' => 2]);
        PharmacyTransferItem::create(['pharmacy_transfer_id' => $suggested->id, 'product_id' => $this->p2->id, 'quantity' => 1, 'from_pharmacy_excess_at_time' => 1, 'to_pharmacy_gap_at_time' => 1]);
        $this->assertCount(2, (new PharmacyTransferExport($suggested->id))->collection());
    }

    private function order(): PurchaseOrder
    {
        $supplier = Supplier::create(['name' => 'مورد', 'is_active' => true]);

        return PurchaseOrder::create(['supplier_id' => $supplier->id, 'status' => PurchaseOrderStatus::Draft, 'scenario' => PurchaseScenario::PriorityBased, 'forecast_period_days' => 30, 'generated_at' => now(), 'total_estimated_cost' => 100]);
    }

    public function test_editing_the_approved_quantity_keeps_line_totals_summing_to_the_order_total(): void
    {
        $order = $this->order();
        $a = PurchaseOrderItem::create(['purchase_order_id' => $order->id, 'product_id' => $this->p1->id, 'suggested_qty' => 5, 'unit_cost' => 10, 'line_total_cost' => 50, 'explanation_summary' => 'x']);
        $b = PurchaseOrderItem::create(['purchase_order_id' => $order->id, 'product_id' => $this->p2->id, 'suggested_qty' => 10, 'unit_cost' => 5, 'line_total_cost' => 50, 'explanation_summary' => 'x']);

        $this->app->make(PurchaseOrderEditingService::class)->updateItems($order, [$a->id => ['approved_qty' => 8]]);

        $this->assertSame('80.00', $a->fresh()->line_total_cost);   // كانت تبقى 50.00
        $this->assertSame('50.00', $b->fresh()->line_total_cost);
        $this->assertSame(130.0, (float) $order->fresh()->total_estimated_cost);
        $this->assertSame(130.0, (float) $order->items()->sum('line_total_cost'));
    }

    public function test_line_total_resync_migration_repairs_previously_edited_lines(): void
    {
        $order = $this->order();
        $stale = PurchaseOrderItem::create(['purchase_order_id' => $order->id, 'product_id' => $this->p1->id, 'suggested_qty' => 5, 'approved_qty' => 8, 'unit_cost' => 10, 'line_total_cost' => 50, 'explanation_summary' => 'x']);
        $untouched = PurchaseOrderItem::create(['purchase_order_id' => $order->id, 'product_id' => $this->p2->id, 'suggested_qty' => 10, 'unit_cost' => 5, 'line_total_cost' => 50, 'explanation_summary' => 'x']);

        (require database_path('migrations/2026_10_11_000005_resync_purchase_order_line_totals.php'))->up();

        $this->assertSame('80.00', $stale->fresh()->line_total_cost);
        $this->assertSame('50.00', $untouched->fresh()->line_total_cost);
    }

    public function test_purchasing_reports_filter_by_receipt_date_not_last_edit(): void
    {
        $order = $this->order();
        $order->update(['status' => PurchaseOrderStatus::Received]);
        $line = PurchaseOrderItem::create(['purchase_order_id' => $order->id, 'product_id' => $this->p1->id, 'suggested_qty' => 5, 'ordered_qty' => 5, 'received_qty' => 5, 'received_at' => '2026-02-10 09:00:00', 'unit_cost' => 10, 'line_total_cost' => 50, 'explanation_summary' => 'x', 'explanation_factors' => ['source' => 'shortages']]);
        DB::table('purchase_order_items')->where('id', $line->id)->update(['updated_at' => '2026-04-20 09:00:00']); // تعديل لاحق

        $report = $this->app->make(PurchaseValueReportService::class);
        $this->assertSame(50.0, $report->build('2026-02-01', '2026-02-28')->totals->total_value);
        $this->assertSame(0.0, $report->build('2026-04-01', '2026-04-30')->totals->total_value);
    }

    public function test_inventory_value_flags_stocked_products_without_a_price(): void
    {
        $unpriced = Product::create(['barcode' => '333', 'name' => 'بلا سعر', 'company' => 'ش']);
        CentralInventory::create(['product_id' => $this->p1->id, 'quantity' => 3]);
        CentralInventory::create(['product_id' => $unpriced->id, 'quantity' => 7]);

        $report = $this->app->make(InventoryValueReportService::class)->build();
        $this->assertSame(30.0, $report->totals->total_value);
        $this->assertSame(1, $report->totals->unpriced_product_count);

        $this->get(route('inventory.reports.inventory-value'))->assertOk()->assertSee('بلا "سعر مادة" مسجَّل', false);
    }

    public function test_report_date_filters_are_validated_with_a_visible_message(): void
    {
        foreach (['inventory.reports.transfers-value', 'inventory.reports.shortage-value', 'purchasing.reports.purchase-value', 'purchasing.reports.supplier-summary', 'purchasing.reports.purchase-shortage-value'] as $route) {
            $this->get(route($route, ['date_from' => '2026-05-10', 'date_to' => '2026-05-01']))
                ->assertRedirect(route($route))
                ->assertSessionHas('error');

            $this->get(route($route, ['date_from' => 'not-a-date']))->assertRedirect(route($route));
            $this->get(route($route, ['date_from' => '2026-05-01', 'date_to' => '2026-05-10']))->assertOk();
        }
    }

    public function test_turnover_note_no_longer_claims_purchasing_uses_a_different_stock_source(): void
    {
        $this->get(route('inventory.reports.inventory-turnover'))->assertOk()
            ->assertDontSee('لا تزامن تلقائي')
            ->assertSee('نفس المصدر');
    }

    public function test_transfers_value_includes_executed_inter_pharmacy_transfers_in_both_directions(): void
    {
        CentralInventoryPrice::create(['product_id' => $this->p1->id, 'price' => 12, 'effective_from' => '2026-03-06']);
        $pt = PharmacyTransfer::create(['from_pharmacy_id' => $this->a->id, 'to_pharmacy_id' => $this->b->id, 'status' => PharmacyTransferStatus::Completed, 'executed_date' => '2026-03-07']);
        PharmacyTransferItem::create(['pharmacy_transfer_id' => $pt->id, 'product_id' => $this->p1->id, 'quantity' => 4, 'from_pharmacy_excess_at_time' => 5, 'to_pharmacy_gap_at_time' => 4, 'execution_status' => TransferItemExecutionStatus::Executed]);
        PharmacyTransferItem::create(['pharmacy_transfer_id' => $pt->id, 'product_id' => $this->p2->id, 'quantity' => 3, 'from_pharmacy_excess_at_time' => 3, 'to_pharmacy_gap_at_time' => 3, 'execution_status' => TransferItemExecutionStatus::Superseded]);
        $this->executedReverse(); // صيدلية أ تُعيد 6 × 10... بسعر يوم 5 (=10) إلى المستودع

        $report = $this->app->make(TransfersValueReportService::class)->build('2026-03-01', '2026-03-31');
        $byName = $report->rows->keyBy('pharmacy_name');

        // 4 × 12 (سعر يوم التنفيذ)، البند superseded مستبعد.
        $this->assertSame([0.0, 0.0, 60.0, 48.0, -108.0], [$byName['صيدلية أ']->forward_value, $byName['صيدلية أ']->inter_in_value, $byName['صيدلية أ']->reverse_value, $byName['صيدلية أ']->inter_out_value, $byName['صيدلية أ']->net_value]);
        $this->assertSame([0.0, 48.0, 0.0, 0.0, 48.0], [$byName['صيدلية ب']->forward_value, $byName['صيدلية ب']->inter_in_value, $byName['صيدلية ب']->reverse_value, $byName['صيدلية ب']->inter_out_value, $byName['صيدلية ب']->net_value]);
        $this->assertSame($report->totals->inter_in_value, $report->totals->inter_out_value); // نقل داخلي يتوازن
        $this->assertSame(-60.0, $report->totals->net_value);                                // = 0 من المستودع − 60 عكسية

        // فلتر صيدلية: ساقها فقط.
        $onlyB = $this->app->make(TransfersValueReportService::class)->build('2026-03-01', '2026-03-31', $this->b->id);
        $this->assertSame(['صيدلية ب'], $onlyB->rows->pluck('pharmacy_name')->all());
        $this->assertSame([48.0, 0.0], [$onlyB->totals->inter_in_value, $onlyB->totals->inter_out_value]);

        $this->get(route('inventory.reports.transfers-value', ['date_from' => '2026-03-01', 'date_to' => '2026-03-31']))
            ->assertOk()->assertSee('من صيدليات أخرى (إليها)')->assertSee('إلى صيدليات أخرى (منها)')->assertSee('48.00');
    }

    public function test_shortage_and_transfers_value_flag_unpriced_products(): void
    {
        $unpriced = Product::create(['barcode' => '333', 'name' => 'بلا سعر', 'company' => 'ش']);
        \App\Models\Shortage::create(['pharmacy_id' => $this->a->id, 'product_id' => $unpriced->id, 'quantity' => 5, 'status' => \App\Enums\ShortageStatus::Pending, 'source' => 'sales']);
        \App\Models\Shortage::create(['pharmacy_id' => $this->a->id, 'product_id' => $this->p1->id, 'quantity' => 2, 'status' => \App\Enums\ShortageStatus::Pending, 'source' => 'sales']);

        $shortages = $this->app->make(\App\Services\Inventory\Reports\ShortageValueReportService::class)->build();
        $this->assertSame([20.0, 1], [$shortages->totals->total_value, $shortages->totals->unpriced_product_count]);
        $this->get(route('inventory.reports.shortage-value'))->assertOk()->assertSee('مادة ناقصة بلا "سعر مادة"', false);

        $invoice = \App\Models\Invoice::create(['pharmacy_id' => $this->a->id, 'date' => '2026-03-02', 'status' => 'processed']);
        \App\Models\InvoiceItem::create(['invoice_id' => $invoice->id, 'product_id' => $unpriced->id, 'requested_qty' => 3, 'supplied_qty' => 3, 'shortage_qty' => 0]);
        \App\Models\InvoiceItem::create(['invoice_id' => $invoice->id, 'product_id' => $this->p1->id, 'requested_qty' => 1, 'supplied_qty' => 1, 'shortage_qty' => 0]);

        $value = $this->app->make(TransfersValueReportService::class)->build('2026-03-01', '2026-03-31');
        $this->assertSame([10.0, 1], [$value->totals->forward_value, $value->totals->unpriced_product_count]);
        $this->get(route('inventory.reports.transfers-value', ['date_from' => '2026-03-01', 'date_to' => '2026-03-31']))
            ->assertOk()->assertSee('مادة بهذه الحركات بلا "سعر مادة"', false);

        // بلا مواد بلا سعر ⇒ لا شريط.
        $this->get(route('inventory.reports.transfers-value', ['date_from' => '2027-01-01', 'date_to' => '2027-01-31']))
            ->assertOk()->assertDontSee('مادة بهذه الحركات بلا');
    }

    public function test_every_report_screen_renders_with_data(): void
    {
        $this->executedReverse();
        CentralInventory::create(['product_id' => $this->p1->id, 'quantity' => 3]);

        foreach (['inventory.reports.inventory-value', 'inventory.reports.transfers-value', 'inventory.reports.shortage-value', 'inventory.reports.inventory-turnover', 'inventory.reports.product-movement', 'inventory.reverse-transfers.report', 'purchasing.reports.purchase-value', 'purchasing.reports.supplier-summary', 'purchasing.reports.purchase-shortage-value', 'purchasing.reports.cost-variance'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('inventory.reverse-transfers.export'))->assertOk();
    }
}
