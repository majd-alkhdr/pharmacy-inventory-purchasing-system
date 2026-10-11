<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Enums\ImportFileType;
use App\Enums\ImportLogStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseScenario;
use App\Enums\ReverseTransferOrigin;
use App\Enums\ReverseTransferStatus;
use App\Models\ImportLog;
use App\Models\Pharmacy;
use App\Models\PharmacyInventory;
use App\Models\PharmacyMinimumStock;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\ReverseTransfer;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\SurplusCommitment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 🎯 (ج.3) زر "مناقلات؟": قراءة الفائض من SurplusEngine، والتنفيذ بنفس مسار ب.6. */
final class PurchaseTransferButtonTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private PurchaseOrder $order;
    private PurchaseOrderItem $item;
    private Pharmacy $pharmacy;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['key' => 'purchasing_test', 'name' => 'مشتريات', 'can_view_purchasing_operations' => true]);
        $this->user = User::create(['name' => 'موظف مشتريات', 'email' => 'p@test.local', 'password' => 'secret123', 'role_id' => $role->id, 'is_active' => true]);

        ImportLog::create(['file_type' => ImportFileType::WarehouseMatrix, 'original_filename' => 'm.xlsx', 'status' => ImportLogStatus::Succeeded, 'started_at' => now(), 'finished_at' => now()]);

        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'ش']);
        $this->pharmacy = Pharmacy::create(['name' => 'صيدلية فائضة']);
        PharmacyInventory::create(['pharmacy_id' => $this->pharmacy->id, 'product_id' => $product->id, 'quantity' => 50]);
        PharmacyMinimumStock::create(['pharmacy_id' => $this->pharmacy->id, 'product_id' => $product->id, 'minimum_quantity' => 20]); // فائض 30

        $supplier = Supplier::create(['name' => 'مورد', 'is_active' => true]);
        $this->order = PurchaseOrder::create(['supplier_id' => $supplier->id, 'status' => PurchaseOrderStatus::Draft, 'scenario' => PurchaseScenario::PriorityBased, 'forecast_period_days' => 30, 'generated_at' => now(), 'total_estimated_cost' => 0]);
        $this->item = PurchaseOrderItem::create(['purchase_order_id' => $this->order->id, 'product_id' => $product->id, 'suggested_qty' => 20, 'unit_cost' => 0, 'line_total_cost' => 0]);
    }

    public function test_options_list_pharmacies_with_available_surplus_and_the_data_timestamp(): void
    {
        $response = $this->actingAs($this->user)->getJson(route('purchasing.orders.items.transfer-options', [$this->order->id, $this->item->id]));

        $response->assertOk()
            ->assertJsonPath('pharmacies.0.pharmacy_id', $this->pharmacy->id)
            ->assertJsonPath('pharmacies.0.available', 30)
            ->assertJsonPath('pharmacies.0.suggested_qty', 20); // لا يُقترح سحب أكثر من حاجة الشراء
        $this->assertNotNull($response->json('last_update'));
    }

    public function test_execute_creates_an_executed_reverse_transfer_through_the_shared_path(): void
    {
        $this->actingAs($this->user)
            ->post(route('purchasing.orders.items.transfer', [$this->order->id, $this->item->id]), ['pharmacy_id' => $this->pharmacy->id, 'quantity' => 20])
            ->assertSessionHas('success');

        $transfer = ReverseTransfer::firstOrFail();
        $this->assertSame(ReverseTransferStatus::Executed, $transfer->status);
        $this->assertSame(ReverseTransferOrigin::Purchasing, $transfer->origin);
        $this->assertSame($this->user->id, $transfer->executed_by);
        $this->assertSame(20, (int) SurplusCommitment::sum('quantity'));

        // الفائض المتبقي الآن 10 — والقراءة التالية تعكسه فوراً (بلا رفع ملف جديد).
        $this->actingAs($this->user)->getJson(route('purchasing.orders.items.transfer-options', [$this->order->id, $this->item->id]))
            ->assertJsonPath('pharmacies.0.available', 10);
    }

    public function test_executing_more_than_the_remaining_surplus_leaves_no_trace(): void
    {
        $this->actingAs($this->user)
            ->post(route('purchasing.orders.items.transfer', [$this->order->id, $this->item->id]), ['pharmacy_id' => $this->pharmacy->id, 'quantity' => 31])
            ->assertSessionHas('error');

        $this->assertSame(0, ReverseTransfer::count());
        $this->assertSame(0, SurplusCommitment::count());
    }

    public function test_an_item_from_another_order_is_rejected(): void
    {
        $other = PurchaseOrder::create(['supplier_id' => $this->order->supplier_id, 'status' => PurchaseOrderStatus::Draft, 'scenario' => PurchaseScenario::PriorityBased, 'forecast_period_days' => 30, 'generated_at' => now(), 'total_estimated_cost' => 0]);

        $this->actingAs($this->user)->getJson(route('purchasing.orders.items.transfer-options', [$other->id, $this->item->id]))->assertNotFound();
    }
}
