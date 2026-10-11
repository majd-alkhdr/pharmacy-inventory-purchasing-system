<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseScenario;
use App\Models\CentralInventory;
use App\Models\CentralInventoryPrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Pharmacy;
use App\Models\Product;
use App\Models\ProductManualSalesRate;
use App\Models\ProductPriceChange;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Services\Inventory\Import\MaterialMaster\MaterialMasterImporter;
use App\Services\Purchasing\CostVarianceMonitorService;
use App\Services\Purchasing\ManualReceivingService;
use App\Services\Purchasing\OpenPurchaseOrderQuantityService;
use App\Services\Purchasing\StandardStockRequiredQuantityProvider;
use App\Services\Purchasing\TurnoverCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Tests\TestCase;

/**
 * 🎯 مصادر بيانات المشتريات بعد إلغاء "جرد المشتريات":
 *   الرصيد ← ملف المصفوفة (central_inventories) · السعر وتاريخه ← ملف الأصناف · last_purchase_date ← الاستلام الفعلي.
 */
final class PurchasingDataSourcesTest extends TestCase
{
    use RefreshDatabase;

    private const MASTER_HEADER = ['رمز المادة', 'اسم المادة', 'الشركة', 'السعر', 'حجم العبوة', 'الحد الأدنى للفرق'];

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function materialMaster(array $rows): string
    {
        $path = $this->tempFiles[] = sys_get_temp_dir().'/mm-'.bin2hex(random_bytes(6)).'.xlsx';
        $writer = new XlsxWriter();
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(self::MASTER_HEADER));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return $path;
    }

    public function test_suggested_quantity_and_turnover_use_matrix_central_stock(): void
    {
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'ش']);
        CentralInventory::create(['product_id' => $product->id, 'quantity' => 40]);
        ProductManualSalesRate::create(['product_id' => $product->id, 'sales_quantity' => 300, 'period_days' => 30]); // 10/يوم

        $provider = new StandardStockRequiredQuantityProvider(30, $this->app->make(OpenPurchaseOrderQuantityService::class));
        $result = $provider->bulkCalculate(collect([$product]))[$product->id];

        // احتياج 30 يوماً = 300، ناقص رصيد المصفوفة 40 ⇒ 260.
        $this->assertSame(40, $result->explanationFactors['current_stock_qty']);
        $this->assertSame(260, $result->requiredQty);

        // الدوران: رصيد 40 ÷ مبيعات أسبوعية 20 ÷ 4 = 0.5 شهر — المساران المفرد والمُجمَّع متطابقان.
        $pharmacy = Pharmacy::create(['name' => 'ص']);
        foreach (range(1, 7) as $day) {
            $invoice = Invoice::create(['pharmacy_id' => $pharmacy->id, 'date' => now()->subDays($day)->toDateString(), 'status' => 'processed']);
            InvoiceItem::create(['invoice_id' => $invoice->id, 'product_id' => $product->id, 'requested_qty' => $day <= 6 ? 3 : 2, 'supplied_qty' => 0, 'shortage_qty' => 0]);
        }
        $calculator = $this->app->make(TurnoverCalculator::class);
        $this->assertEqualsWithDelta(0.5, $calculator->bulkCalculate(collect([$product]))[$product->id], 0.0001);
        $this->assertEqualsWithDelta(0.5, $calculator->calculateFor($product), 0.0001);
    }

    public function test_material_master_price_change_is_logged_once_and_drives_cost_variance(): void
    {
        $up = Product::create(['barcode' => '111', 'name' => 'يرتفع', 'company' => 'ش']);
        $down = Product::create(['barcode' => '222', 'name' => 'ينخفض', 'company' => 'ش']);
        $same = Product::create(['barcode' => '333', 'name' => 'ثابت', 'company' => 'ش']);
        $importer = $this->app->make(MaterialMasterImporter::class);

        // أول تسعير: لا قيمة مخزَّنة سابقاً ⇒ ليس "تغيّراً" ⇒ لا سطر.
        $importer->import($this->materialMaster([['111', null, null, 10, null, null], ['222', null, null, 20, null, null], ['333', null, null, 5, null, null]]));
        $this->assertSame(0, ProductPriceChange::count());

        $importer->import($this->materialMaster([['111', null, null, 12.5, null, null], ['222', null, null, 18, null, null], ['333', null, null, 5, null, null]]));

        $this->assertSame(2, ProductPriceChange::count()); // الثابت لم يُسجَّل
        $change = ProductPriceChange::where('product_id', $up->id)->sole();
        $this->assertSame(['10.00', '12.50'], [$change->old_price, $change->new_price]);
        $this->assertSame(12.5, CentralInventoryPrice::latestFor($up->id)); // السعر الحالي كُتب بعد السجل

        // إعادة رفع نفس الملف ⇒ لا أسطر جديدة.
        $importer->import($this->materialMaster([['111', null, null, 12.5, null, null]]));
        $this->assertSame(2, ProductPriceChange::count());

        $variances = $this->app->make(CostVarianceMonitorService::class)->detectVariances();
        $this->assertCount(1, $variances); // الانخفاض لا يُنبَّه عنه
        $row = $variances->first();
        $this->assertSame($up->id, $row->product->id);
        $this->assertSame([10.0, 12.5, 2.5, 25.0], [$row->previous_price, $row->current_price, $row->variance_amount, $row->variance_percent]);

        $this->actingAsPurchasing()->get(route('purchasing.reports.cost-variance'))->assertOk()->assertSee('يرتفع')->assertDontSee('ينخفض');
    }

    public function test_last_purchase_date_comes_from_the_latest_received_purchase_order_only(): void
    {
        $received = Product::create(['barcode' => '111', 'name' => 'استُلم', 'company' => 'ش', 'last_purchase_date' => '2020-01-01']);
        $notReceived = Product::create(['barcode' => '222', 'name' => 'لم يُستلم', 'company' => 'ش']);
        $supplier = Supplier::create(['name' => 'مورد', 'is_active' => true]);
        $order = PurchaseOrder::create(['supplier_id' => $supplier->id, 'status' => PurchaseOrderStatus::Sent, 'scenario' => PurchaseScenario::PriorityBased, 'forecast_period_days' => 30, 'generated_at' => now(), 'total_estimated_cost' => 0]);
        $lineA = PurchaseOrderItem::create(['purchase_order_id' => $order->id, 'product_id' => $received->id, 'suggested_qty' => 10, 'unit_cost' => 0, 'line_total_cost' => 0, 'explanation_summary' => 'x']);
        $lineB = PurchaseOrderItem::create(['purchase_order_id' => $order->id, 'product_id' => $notReceived->id, 'suggested_qty' => 5, 'unit_cost' => 0, 'line_total_cost' => 0, 'explanation_summary' => 'x']);

        $this->travelTo(now()->setDate(2026, 10, 5));
        $this->app->make(ManualReceivingService::class)->process($order, [
            $lineA->id => ['status' => 'partial', 'received_qty' => 4],
            $lineB->id => ['status' => 'none'],
        ]);

        $this->assertSame('2026-10-05', $received->fresh()->last_purchase_date->toDateString()); // القيمة القديمة (ملف) استُبدلت
        $this->assertNull($notReceived->fresh()->last_purchase_date);
        $this->assertNotNull($lineA->fresh()->received_at);
        $this->assertNull($lineB->fresh()->received_at);
    }

    public function test_purchase_inventory_upload_is_gone(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('purchasing.purchase-inventory.form'));
        $this->assertFalse(class_exists(\App\Services\Purchasing\PurchaseInventoryUploadService::class));
        $this->assertFalse(class_exists(\App\Models\ProductPurchasePrice::class));
        $this->actingAsPurchasing()->get(route('purchasing.orders.create'))->assertOk()->assertDontSee('جرد المشتريات');
    }

    private function actingAsPurchasing(): self
    {
        $role = \App\Models\Role::create(['key' => 'p_test', 'name' => 'مشتريات', 'can_view_purchasing_operations' => true, 'can_view_purchasing_reports' => true]);

        return $this->actingAs(\App\Models\User::create(['name' => 'م', 'email' => 'p@test.local', 'password' => 'secret123', 'role_id' => $role->id, 'is_active' => true]));
    }
}
