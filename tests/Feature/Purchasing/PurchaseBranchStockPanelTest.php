<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Enums\ImportFileType;
use App\Enums\ImportLogStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseScenario;
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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 🎯 (ج.3) لوحة "الفروع": كل الفروع بحالاتها، الفائض أولاً، والسحب للمسودات فقط. */
final class PurchaseBranchStockPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Supplier $supplier;
    private Product $product;
    private PurchaseOrder $order;
    private PurchaseOrderItem $item;

    /** @var array<string, Pharmacy> */
    private array $branches = [];

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['key' => 'purchasing_test', 'name' => 'مشتريات', 'can_view_purchasing_operations' => true]);
        $this->user = User::create(['name' => 'موظف مشتريات', 'email' => 'p@test.local', 'password' => 'secret123', 'role_id' => $role->id, 'is_active' => true]);
        ImportLog::create(['file_type' => ImportFileType::WarehouseMatrix, 'original_filename' => 'm.xlsx', 'status' => ImportLogStatus::Succeeded, 'started_at' => now(), 'finished_at' => now()]);

        $this->product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'ش']);
        $this->branch('A', stock: 50, standard: 20);              // فائض 30
        $this->branch('B', stock: 35, standard: 20);              // فائض 15
        $this->branch('C', stock: 23, standard: 20, gap: 5);      // +3 ضمن التسامح ⇒ ok
        $this->branch('D', stock: 5, standard: 20);               // دون القياسي -15
        $this->branch('E', stock: null, standard: 20);            // لا رصيد مرفوع
        $this->branch('F', stock: 7, standard: null);             // بلا قياسي

        $this->supplier = Supplier::create(['name' => 'مورد', 'is_active' => true]);
        [$this->order, $this->item] = $this->orderWith(PurchaseOrderStatus::Draft, [$this->product]);
    }

    private function branch(string $name, ?int $stock, ?int $standard, ?int $gap = null): void
    {
        $pharmacy = Pharmacy::create(['name' => "فرع {$name}"]);
        if ($stock !== null) {
            PharmacyInventory::create(['pharmacy_id' => $pharmacy->id, 'product_id' => $this->product->id, 'quantity' => $stock]);
        }
        if ($standard !== null) {
            PharmacyMinimumStock::create(['pharmacy_id' => $pharmacy->id, 'product_id' => $this->product->id, 'minimum_quantity' => $standard, 'min_reorder_gap' => $gap]);
        }
        $this->branches[$name] = $pharmacy;
    }

    /**
     * @param list<Product> $products
     * @return array{0: PurchaseOrder, 1: PurchaseOrderItem}
     */
    private function orderWith(PurchaseOrderStatus $status, array $products): array
    {
        $order = PurchaseOrder::create(['supplier_id' => $this->supplier->id, 'status' => $status, 'scenario' => PurchaseScenario::PriorityBased, 'forecast_period_days' => 30, 'generated_at' => now(), 'total_estimated_cost' => 0]);
        $items = array_map(fn (Product $p) => PurchaseOrderItem::create(['purchase_order_id' => $order->id, 'product_id' => $p->id, 'suggested_qty' => 20, 'unit_cost' => 0, 'line_total_cost' => 0]), $products);

        return [$order, $items[0]];
    }

    private function branchOptions(?PurchaseOrder $order = null, ?PurchaseOrderItem $item = null)
    {
        return $this->actingAs($this->user)->getJson(route('purchasing.orders.items.transfer-options', [($order ?? $this->order)->id, ($item ?? $this->item)->id]));
    }

    /** @return array<string, string> اسم الفرع ← حالته، بترتيب العرض */
    private function statesInOrder($response): array
    {
        return collect($response->json('branches'))->mapWithKeys(fn ($b) => [$b['name'] => $b['state']])->all();
    }

    public function test_options_list_every_branch_surplus_first_with_a_correct_summary(): void
    {
        $response = $this->branchOptions()->assertOk();

        $this->assertSame([
            'فرع A' => 'surplus', 'فرع B' => 'surplus', 'فرع C' => 'ok',
            'فرع D' => 'below', 'فرع E' => 'unknown', 'فرع F' => 'no_standard',
        ], $this->statesInOrder($response));

        $response->assertJsonPath('summary', ['branches_total' => 6, 'branches_stock_sum' => 120, 'surplus_available' => 45, 'surplus_branches' => 2])
            ->assertJsonPath('branches.0.available', 30)
            ->assertJsonPath('branches.0.suggested_qty', 20)
            ->assertJsonPath('branches.2.suggested_qty', null)
            ->assertJsonPath('branches.3.difference', -15)
            ->assertJsonPath('can_execute', true)
            // المفتاح القديم كما هو: الفائض المتاح فقط.
            ->assertJsonCount(2, 'pharmacies')
            ->assertJsonPath('pharmacies.0.pharmacy_id', $this->branches['A']->id);
    }

    public function test_a_branch_with_a_standard_but_no_stock_row_is_unknown_not_zero(): void
    {
        $e = collect($this->branchOptions()->json('branches'))->firstWhere('name', 'فرع E');

        $this->assertSame('unknown', $e['state']);
        $this->assertNull($e['current_stock']);
        $this->assertNull($e['difference']);
    }

    public function test_after_pulling_the_branch_turns_reserved_or_its_available_drops(): void
    {
        $pull = fn (string $branch, int $qty) => $this->actingAs($this->user)
            ->post(route('purchasing.orders.items.transfer', [$this->order->id, $this->item->id]), ['pharmacy_id' => $this->branches[$branch]->id, 'quantity' => $qty])
            ->assertSessionHas('success');

        $pull('A', 30);
        $pull('B', 5);

        $branches = collect($this->branchOptions()->json('branches'))->keyBy('name');
        $this->assertSame('surplus_reserved', $branches['فرع A']['state']);
        $this->assertSame(0, $branches['فرع A']['available']);
        $this->assertSame(30, $branches['فرع A']['committed']);
        $this->assertSame('surplus', $branches['فرع B']['state']);
        $this->assertSame(10, $branches['فرع B']['available']);
        $this->assertSame(['فرع B', 'فرع A'], array_slice(array_keys($this->statesInOrder($this->branchOptions())), 0, 2));
    }

    public function test_show_badges_only_products_with_branch_surplus(): void
    {
        $plain = Product::create(['barcode' => '222', 'name' => 'أموكسيل', 'company' => 'ش']);
        [$order] = $this->orderWith(PurchaseOrderStatus::Draft, [$this->product, $plain]);

        $html = $this->actingAs($this->user)->get(route('purchasing.orders.show', $order->id))->assertOk()
            ->assertSee('فائض بالفروع: 45')
            ->getContent();

        $this->assertSame(1, substr_count($html, 'فائض بالفروع:'));
    }

    public function test_show_uses_one_surplus_snapshot_however_many_items(): void
    {
        $surplusQueries = function (PurchaseOrder $order): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->user)->get(route('purchasing.orders.show', $order->id))->assertOk();
            $count = collect(DB::getQueryLog())->filter(fn ($q) => preg_match('/pharmacy_minimum_stocks|surplus_commitments/', $q['query']))->count();
            DB::disableQueryLog();

            return $count;
        };

        $many = [$this->product];
        foreach (range(1, 6) as $i) {
            $p = Product::create(['barcode' => "9{$i}", 'name' => "مادة {$i}", 'company' => 'ش']);
            PharmacyMinimumStock::create(['pharmacy_id' => $this->branches['A']->id, 'product_id' => $p->id, 'minimum_quantity' => 1]);
            PharmacyInventory::create(['pharmacy_id' => $this->branches['A']->id, 'product_id' => $p->id, 'quantity' => 10]);
            $many[] = $p;
        }
        [$big] = $this->orderWith(PurchaseOrderStatus::Draft, $many);

        $this->assertSame($surplusQueries($this->order), $surplusQueries($big));
    }

    public function test_executing_on_a_non_draft_order_is_refused_without_any_trace(): void
    {
        foreach ([PurchaseOrderStatus::Approved, PurchaseOrderStatus::Sent] as $status) {
            [$order, $item] = $this->orderWith($status, [$this->product]);

            $this->actingAs($this->user)
                ->post(route('purchasing.orders.items.transfer', [$order->id, $item->id]), ['pharmacy_id' => $this->branches['A']->id, 'quantity' => 5])
                ->assertSessionHas('error', 'لا يمكن السحب من فاتورة غير مسودة.');
        }

        $this->assertSame(0, ReverseTransfer::count());
        $this->assertSame(0, SurplusCommitment::count());
    }

    public function test_options_on_a_non_draft_order_are_read_only_but_complete(): void
    {
        [$order, $item] = $this->orderWith(PurchaseOrderStatus::Approved, [$this->product]);

        $this->branchOptions($order, $item)->assertOk()
            ->assertJsonPath('can_execute', false)
            ->assertJsonCount(6, 'branches');

        $this->actingAs($this->user)->get(route('purchasing.orders.show', $order->id))->assertOk()
            ->assertSee('الفاتورة غير مسودة — العرض للاطلاع فقط')
            ->assertDontSee('id="transferExecuteForm"', false);
    }
}
