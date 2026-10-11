<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Enums\AuditAction;
use App\Enums\PurchaseGenerationMode;
use App\Enums\PurchaseScenario;
use App\Enums\ShortageStatus;
use App\Enums\SupplierScopeMode;
use App\Models\AuditLog;
use App\Models\Pharmacy;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Shortage;
use App\Models\Supplier;
use App\Models\SupplierCompany;
use App\Services\Purchasing\GeneratePurchaseOrdersParams;
use App\Services\Purchasing\PurchaseOrderEditingService;
use App\Services\Purchasing\PurchaseOrderGenerationService;
use App\Services\Purchasing\SupplierScope\SupplierScope;
use App\Services\Purchasing\SupplierScope\SupplierScopeStrategyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/** 🎯 (ج.1 + ج.2) استراتيجيتا نطاق الموردين ← نفس مسار التوليد؛ والكمية المعتمدة + سجل النشاط. */
final class SupplierScopeGenerationTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $s1;
    private Supplier $s2;
    private Supplier $s3;
    private Product $pA;
    private Product $pB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->s1 = Supplier::create(['name' => 'مورد 1', 'is_active' => true]);
        $this->s2 = Supplier::create(['name' => 'مورد 2', 'is_active' => true]);
        $this->s3 = Supplier::create(['name' => 'مورد 3', 'is_active' => true]);
        SupplierCompany::create(['supplier_id' => $this->s1->id, 'company_name' => 'شركة أ']);
        SupplierCompany::create(['supplier_id' => $this->s3->id, 'company_name' => 'شركة أ']);
        SupplierCompany::create(['supplier_id' => $this->s2->id, 'company_name' => 'شركة ب']);

        $this->pA = Product::create(['barcode' => '111', 'name' => 'مادة أ', 'company' => 'شركة أ']);
        $this->pB = Product::create(['barcode' => '222', 'name' => 'مادة ب', 'company' => 'شركة ب']);

        $pharmacy = Pharmacy::create(['name' => 'صيدلية']);
        foreach ([$this->pA, $this->pB] as $product) {
            Shortage::create(['pharmacy_id' => $pharmacy->id, 'product_id' => $product->id, 'quantity' => 12, 'status' => ShortageStatus::Pending, 'source' => 'sales']);
        }
    }

    private function generate(SupplierScopeMode $mode, array $supplierIds, ?string $company = null)
    {
        $scope = $this->app->make(SupplierScopeStrategyResolver::class)->resolve($mode)->resolve($supplierIds, $company);

        return $this->app->make(PurchaseOrderGenerationService::class)->generate(new GeneratePurchaseOrdersParams(
            forecastPeriodDays: 30,
            scenario: PurchaseScenario::PriorityBased,
            generationMode: PurchaseGenerationMode::Shortages,
            supplierScope: $scope,
        ));
    }

    public function test_direct_strategy_with_several_suppliers_creates_one_invoice_per_supplier(): void
    {
        $result = $this->generate(SupplierScopeMode::DirectSuppliers, [$this->s1->id, $this->s2->id]);

        $this->assertSame(2, $result->createdOrders->count());
        $this->assertSame([$this->pA->id], PurchaseOrder::where('supplier_id', $this->s1->id)->first()->items->pluck('product_id')->all());
        $this->assertSame([$this->pB->id], PurchaseOrder::where('supplier_id', $this->s2->id)->first()->items->pluck('product_id')->all());
        // شركة أ يحملها 1 و3 — لكن النطاق حصرها بالمورد 1 فلا تعارض.
        $this->assertFalse($result->hasSupplierConflicts());
    }

    public function test_company_strategy_limits_products_to_the_company_and_uses_the_same_generation_path(): void
    {
        $result = $this->generate(SupplierScopeMode::ByCompany, [$this->s3->id], 'شركة أ');

        $this->assertSame(1, $result->createdOrders->count());
        $order = $result->createdOrders->first();
        $this->assertSame($this->s3->id, $order->supplier_id);
        $this->assertSame([$this->pA->id], $order->items->pluck('product_id')->all());
    }

    public function test_company_strategy_rejects_a_supplier_that_does_not_carry_the_company(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->generate(SupplierScopeMode::ByCompany, [$this->s2->id], 'شركة أ');
    }

    public function test_company_suggestions_come_from_the_same_source_as_validation(): void
    {
        $suggested = $this->app->make(\App\Services\Purchasing\SupplierScope\CompanyDerivedSupplierScopeStrategy::class)->suggestFor('شركة أ');

        $this->assertEqualsCanonicalizing([$this->s1->id, $this->s3->id], $suggested->pluck('id')->all());
    }

    public function test_all_suppliers_keeps_the_old_conflict_behaviour(): void
    {
        $result = $this->generate(SupplierScopeMode::DirectSuppliers, []);

        $this->assertTrue($result->hasSupplierConflicts());
        $this->assertSame(['شركة أ'], $result->conflictingCompanies->all());
    }

    public function test_legacy_single_supplier_param_still_works(): void
    {
        $result = $this->app->make(PurchaseOrderGenerationService::class)->generate(new GeneratePurchaseOrdersParams(
            forecastPeriodDays: 30,
            scenario: PurchaseScenario::PriorityBased,
            generationMode: PurchaseGenerationMode::Shortages,
            supplierId: $this->s2->id,
        ));

        $this->assertSame(1, $result->createdOrders->count());
        $this->assertEquals(new SupplierScope([$this->s2->id]), (new GeneratePurchaseOrdersParams(30, PurchaseScenario::PriorityBased, PurchaseGenerationMode::Shortages, supplierId: $this->s2->id))->supplierScope());
    }

    public function test_approved_quantity_is_rounded_once_and_audited_against_the_suggestion(): void
    {
        $order = $this->generate(SupplierScopeMode::DirectSuppliers, [$this->s2->id])->createdOrders->first();
        $item = $order->items->first();

        $this->app->make(PurchaseOrderEditingService::class)->updateItems($order, [$item->id => ['approved_qty' => '7.5']]);

        $this->assertSame(12, (int) $item->fresh()->suggested_qty);   // المقترح لا يُكتب فوقه
        $this->assertSame(8, (int) $item->fresh()->approved_qty);     // تدوير واحد Half-Up (أ.5)

        $log = AuditLog::where('action', AuditAction::PurchaseItemApprovedQtyChanged)->firstOrFail();
        $this->assertSame(['approved_qty' => 12], $log->old_values);
        $this->assertSame(['approved_qty' => 8], $log->new_values);
        $this->assertSame(12, $log->context['suggested_qty']);

        // حفظ بلا تغيير لا يضيف ضجيجاً للسجل.
        $this->app->make(PurchaseOrderEditingService::class)->updateItems($order, [$item->id => ['approved_qty' => 8]]);
        $this->assertSame(1, AuditLog::where('action', AuditAction::PurchaseItemApprovedQtyChanged)->count());
    }
}
