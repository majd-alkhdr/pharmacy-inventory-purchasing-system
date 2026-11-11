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
use App\Models\TransferReconciliation;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🎯 أداة البحث/الفرز/الفلترة الموحّدة: الخادم يرسم عقد الـHTML (data-tt-*) صحيحاً بالشاشات الثلاث.
 * السلوك نفسه بالمتصفح (public/js/table-tools.js) لا تغطيه PHPUnit.
 */
final class TableToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Pharmacy $pharmacy;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create([
            'key' => 'tt_test', 'name' => 'كل الصلاحيات',
            'can_view_transfers_operations' => true, 'can_view_transfers_reports' => true,
            'can_view_purchasing_operations' => true, 'can_view_purchasing_reports' => true,
        ]);
        $this->user = User::create(['name' => 'مختبر', 'email' => 'tt@test.local', 'password' => 'secret123', 'role_id' => $role->id, 'is_active' => true]);
        ImportLog::create(['file_type' => ImportFileType::WarehouseMatrix, 'original_filename' => 'm.xlsx', 'status' => ImportLogStatus::Succeeded, 'started_at' => now(), 'finished_at' => now()]);
        $this->pharmacy = Pharmacy::create(['name' => 'صيدلية']);
        $this->actingAs($this->user);
    }

    /** @return array<string, DOMElement> data-tt-id ← صف المادة */
    private function rows(string $html, ?DOMXPath &$xpath = null): array
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8">' . $html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $rows = [];
        foreach ($xpath->query('//tr[@data-tt-row]') as $tr) {
            $rows[$tr->getAttribute('data-tt-id')] = $tr;
        }

        return $rows;
    }

    private function assertToolsPresent(string $html, string $table): void
    {
        $this->assertStringContainsString('data-tt-table="' . $table . '"', $html);
        $this->assertStringContainsString('data-tt-toolbar="' . $table . '"', $html);
        $this->assertStringContainsString('js/table-tools.js?v=', $html);
        $this->assertSame(1, substr_count($html, 'js/table-tools.js?v='));
    }

    public function test_purchase_order_rows_carry_sort_filter_values_and_companions(): void
    {
        $surplus = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ']);
        $plain = Product::create(['barcode' => '222', 'name' => 'أموكسيل', 'company' => 'شركة ب']);
        CentralInventory::create(['product_id' => $surplus->id, 'quantity' => 40]);
        PharmacyInventory::create(['pharmacy_id' => $this->pharmacy->id, 'product_id' => $surplus->id, 'quantity' => 50]);
        PharmacyMinimumStock::create(['pharmacy_id' => $this->pharmacy->id, 'product_id' => $surplus->id, 'minimum_quantity' => 20]);

        $supplier = Supplier::create(['name' => 'مورد', 'is_active' => true]);
        $order = PurchaseOrder::create(['supplier_id' => $supplier->id, 'status' => PurchaseOrderStatus::Draft, 'scenario' => PurchaseScenario::PriorityBased, 'forecast_period_days' => 30, 'generated_at' => now(), 'total_estimated_cost' => 0]);
        $a = PurchaseOrderItem::create(['purchase_order_id' => $order->id, 'product_id' => $surplus->id, 'suggested_qty' => 10, 'unit_cost' => 2.5, 'line_total_cost' => 25, 'is_target_item' => true, 'priority_rank' => 1]);
        $b = PurchaseOrderItem::create(['purchase_order_id' => $order->id, 'product_id' => $plain->id, 'suggested_qty' => 8, 'approved_qty' => 5, 'unit_cost' => 1, 'line_total_cost' => 5]);

        $html = $this->get(route('purchasing.orders.show', $order->id))->assertOk()->getContent();
        $this->assertToolsPresent($html, 'po-items');
        $rows = $this->rows($html, $xpath);

        $this->assertCount(2, $rows);
        $ra = $rows[(string) $a->id];
        $this->assertSame('بنادول شركة أ 111', $ra->getAttribute('data-tt-search'));
        $this->assertSame('10', $ra->getAttribute('data-tt-s-approved'));
        $this->assertSame('40', $ra->getAttribute('data-tt-s-industry'));
        $this->assertSame('25.00', $ra->getAttribute('data-tt-s-total'));
        $this->assertSame('3', $ra->getAttribute('data-tt-s-priority'));
        $this->assertSame('target', $ra->getAttribute('data-tt-f-priority'));
        $this->assertSame('in_industry branch_surplus', $ra->getAttribute('data-tt-f-stock'));
        $this->assertSame('', $ra->getAttribute('data-tt-f-edit'));

        $rb = $rows[(string) $b->id];
        $this->assertSame('5', $rb->getAttribute('data-tt-s-approved'));
        $this->assertSame('', $rb->getAttribute('data-tt-s-industry'));
        $this->assertSame('normal', $rb->getAttribute('data-tt-f-priority'));
        $this->assertSame('no_industry', $rb->getAttribute('data-tt-f-stock'));
        $this->assertSame('edited', $rb->getAttribute('data-tt-f-edit'));

        // حقل الكمية المعتمدة: الفرز يقرأ قيمته الحالية، و"معدَّلة" تُحسب منه.
        $input = $xpath->query('.//input[@data-tt-live="approved"]', $rb)->item(0);
        $this->assertSame('8', $input->getAttribute('data-tt-edit-base'));

        // كل بند ثلاثة صفوف: الصفان المرافقان يتبعان أمّهما بالمعرّف.
        foreach ([$a, $b] as $item) {
            $companions = $xpath->query('//tr[@data-tt-companion-of="' . $item->id . '"]');
            $this->assertSame(['why-row-' . $item->id, 'transfer-row-' . $item->id], [$companions->item(0)->getAttribute('id'), $companions->item(1)->getAttribute('id')]);
        }

        // فلتر "التعديل" للمسودة فقط.
        $this->assertStringContainsString('data-tt-filter="edit"', $html);
        $order->update(['status' => PurchaseOrderStatus::Approved]);
        $this->assertStringNotContainsString('data-tt-filter="edit"', $this->get(route('purchasing.orders.show', $order->id))->getContent());
    }

    public function test_priority_badge_follows_the_model_priority_key(): void
    {
        $product = Product::create(['barcode' => '333', 'name' => 'فيتامين', 'company' => 'ش']);
        $supplier = Supplier::create(['name' => 'مورد', 'is_active' => true]);
        $order = PurchaseOrder::create(['supplier_id' => $supplier->id, 'status' => PurchaseOrderStatus::Draft, 'scenario' => PurchaseScenario::PriorityBased, 'forecast_period_days' => 30, 'generated_at' => now(), 'total_estimated_cost' => 0]);
        // تارجت + دوران عالٍ + أولوية عالية ⇒ "تارجت" وحده (الأسبقية).
        PurchaseOrderItem::create(['purchase_order_id' => $order->id, 'product_id' => $product->id, 'suggested_qty' => 1, 'unit_cost' => 0, 'line_total_cost' => 0, 'is_target_item' => true, 'priority_rank' => 1, 'explanation_factors' => ['is_high_turnover' => true]]);

        $html = $this->get(route('purchasing.orders.show', $order->id))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/class="priority-badge"[^>]*>صنف\s+تارجت/u', $html);
        $this->assertDoesNotMatchRegularExpression('/class="priority-badge"[^>]*>دوران/u', $html);
    }

    public function test_transfer_invoice_rows_carry_values_and_keep_the_availability_filter(): void
    {
        $invoice = Invoice::create(['pharmacy_id' => $this->pharmacy->id, 'date' => now()->toDateString(), 'status' => 'processed']);
        $p1 = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ']);
        $p2 = Product::create(['barcode' => '222', 'name' => 'أموكسيل', 'company' => 'شركة ب']);
        $p3 = Product::create(['barcode' => '333', 'name' => 'فيتامين', 'company' => 'شركة ج']);
        $edited = InvoiceItem::create(['invoice_id' => $invoice->id, 'product_id' => $p1->id, 'requested_qty' => 10, 'supplied_qty' => 7, 'system_supplied_qty' => 10, 'shortage_qty' => 0]);
        $manual = InvoiceItem::create(['invoice_id' => $invoice->id, 'product_id' => $p2->id, 'requested_qty' => 0, 'supplied_qty' => 4, 'shortage_qty' => 0, 'is_manually_added' => true]);
        $redistributed = InvoiceItem::create(['invoice_id' => $invoice->id, 'product_id' => $p3->id, 'requested_qty' => 5, 'supplied_qty' => 5, 'shortage_qty' => 0, 'is_redistributed' => true]);

        $html = $this->get(route('inventory.invoice.show', $invoice->id))->assertOk()->getContent();
        $this->assertToolsPresent($html, 'invoice-items');
        $this->assertStringContainsString('name="availability"', $html); // فلتر الخادم باقٍ
        $rows = $this->rows($html, $xpath);

        $this->assertCount(3, $rows);
        $re = $rows[(string) $edited->id];
        $this->assertSame('بنادول شركة أ 111', $re->getAttribute('data-tt-search'));
        $this->assertSame('10', $re->getAttribute('data-tt-s-requested'));
        $this->assertSame('10', $re->getAttribute('data-tt-s-system'));
        $this->assertSame('7', $re->getAttribute('data-tt-s-supplied'));
        $this->assertSame('edited', $re->getAttribute('data-tt-f-edit'));
        $this->assertSame('edited manual', $rows[(string) $manual->id]->getAttribute('data-tt-f-edit'));
        $this->assertSame('redistributed', $rows[(string) $redistributed->id]->getAttribute('data-tt-f-edit'));

        $this->assertSame(3, $xpath->query('//td[@data-tt-index]')->length);
        // حقول الكمية تبقى عادية (غير معطّلة) — الإخفاء بالفلتر لا يمنع إرسالها.
        $this->assertSame(3, $xpath->query('//input[@data-tt-live="supplied" and not(@disabled)]')->length);
    }

    public function test_reconciliation_rows_carry_status_and_reason_values(): void
    {
        $invoice = Invoice::create(['pharmacy_id' => $this->pharmacy->id, 'date' => now()->toDateString(), 'status' => 'processed']);
        $products = collect(['111' => 'بنادول', '222' => 'أموكسيل', '333' => 'فيتامين', '444' => 'زنك'])
            ->map(fn ($name, $barcode) => Product::create(['barcode' => (string) $barcode, 'name' => $name, 'company' => 'ش']))->values();

        $reconciliation = TransferReconciliation::create(['invoice_id' => $invoice->id, 'items_count' => 4, 'mismatched_count' => 3]);
        $finance = $reconciliation->items()->create(['product_id' => $products[0]->id, 'invoiced_qty' => 10, 'prepared_qty' => 6, 'difference' => -4]);
        $notInInvoice = $reconciliation->items()->create(['product_id' => $products[1]->id, 'invoiced_qty' => 0, 'prepared_qty' => 3, 'difference' => 3]);
        $matched = $reconciliation->items()->create(['product_id' => $products[2]->id, 'invoiced_qty' => 5, 'prepared_qty' => 5, 'difference' => 0]);
        $reasoned = $reconciliation->items()->create(['product_id' => $products[3]->id, 'invoiced_qty' => 5, 'prepared_qty' => 7, 'difference' => 2]);

        $this->post(route('inventory.reconciliation-item.reason', $finance->id), ['discrepancy_reason_id' => DiscrepancyReason::where('code', 'phantom_stock')->value('id')])
            ->assertSessionHas('success');
        $plainReason = DiscrepancyReason::where('requires_finance_settlement', false)->value('id');
        $this->post(route('inventory.reconciliation-item.reason', $reasoned->id), ['discrepancy_reason_id' => $plainReason])->assertSessionHas('success');

        $html = $this->get(route('inventory.invoice.reconciliation', $invoice->id))->assertOk()->getContent();
        $this->assertToolsPresent($html, 'reconciliation-items');
        $this->assertStringContainsString('data-tt-keep-scroll', $html);
        $this->assertStringContainsString('التصدير لا يتأثر بالبحث والفلاتر', $html);
        $rows = $this->rows($html);

        $this->assertCount(4, $rows);
        $expect = [
            [$finance, 'mismatched short', 'has_reason finance', '-4'],
            [$notInInvoice, 'mismatched not_in_invoice over', 'none', '3'],
            [$matched, 'matched', '', '0'],
            [$reasoned, 'mismatched over', 'has_reason', '2'],
        ];
        foreach ($expect as [$item, $status, $reason, $difference]) {
            $row = $rows[(string) $item->id];
            $this->assertSame($status, $row->getAttribute('data-tt-f-status'), "status of #{$item->id}");
            $this->assertSame($reason, $row->getAttribute('data-tt-f-reason'), "reason of #{$item->id}");
            $this->assertSame($difference, $row->getAttribute('data-tt-s-difference'));
        }
        $this->assertSame('بنادول 111', $rows[(string) $finance->id]->getAttribute('data-tt-search'));
    }
}
