<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory\Import;

use App\Models\CentralInventory;
use App\Models\Pharmacy;
use App\Models\PharmacyInventory;
use App\Models\PharmacyMinimumStock;
use App\Models\Product;
use App\Services\Inventory\ParLevelReplenishmentService;
use App\Services\Inventory\ReverseTransferRecorderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الافتراضي الجديد على مستوى المادة (products.min_reorder_gap) يُستخدَم فعلياً بمحرك
 * الترميم الحقيقي عند غياب تجاوز الزوج — وتجاوز الزوج يبقى له الأولوية عند وجوده.
 */
final class MinReorderGapDefaultTest extends TestCase
{
    use RefreshDatabase;

    private function engine(): ParLevelReplenishmentService
    {
        return new ParLevelReplenishmentService(new ReverseTransferRecorderService());
    }

    public function test_the_product_level_default_suppresses_a_small_gap(): void
    {
        $a = Pharmacy::create(['name' => 'صيدلية أ']);
        $p = Product::create(['barcode' => '1', 'name' => 'ب', 'company' => 'ش', 'min_reorder_gap' => 5]);
        CentralInventory::create(['product_id' => $p->id, 'quantity' => 100]);
        PharmacyInventory::create(['pharmacy_id' => $a->id, 'product_id' => $p->id, 'quantity' => 47]);
        PharmacyMinimumStock::create(['pharmacy_id' => $a->id, 'product_id' => $p->id, 'minimum_quantity' => 50]); // فجوة خام = 3

        $result = $this->engine()->replenish([$a->id]);

        $this->assertSame(0, $result->invoicesCreated);   // 3 ≤ الافتراضي 5 ← تُتجاهل
    }

    public function test_a_pair_override_takes_priority_over_the_product_default(): void
    {
        $a = Pharmacy::create(['name' => 'صيدلية أ']);
        $p = Product::create(['barcode' => '1', 'name' => 'ب', 'company' => 'ش', 'min_reorder_gap' => 5]);
        CentralInventory::create(['product_id' => $p->id, 'quantity' => 100]);
        PharmacyInventory::create(['pharmacy_id' => $a->id, 'product_id' => $p->id, 'quantity' => 47]);
        // تجاوز صريح لهذا الزوج تحديداً: صفر تسامح، رغم افتراضي المادة 5
        PharmacyMinimumStock::create(['pharmacy_id' => $a->id, 'product_id' => $p->id, 'minimum_quantity' => 50, 'min_reorder_gap' => 0]);

        $result = $this->engine()->replenish([$a->id]);

        $this->assertSame(1, $result->invoicesCreated);   // 0 < 3 ← التجاوز يفوز، تُشحن
    }

    public function test_no_default_and_no_override_behaves_like_zero_tolerance_as_before(): void
    {
        $a = Pharmacy::create(['name' => 'صيدلية أ']);
        $p = Product::create(['barcode' => '1', 'name' => 'ب', 'company' => 'ش']);   // بلا افتراضي
        CentralInventory::create(['product_id' => $p->id, 'quantity' => 100]);
        PharmacyInventory::create(['pharmacy_id' => $a->id, 'product_id' => $p->id, 'quantity' => 49]);
        PharmacyMinimumStock::create(['pharmacy_id' => $a->id, 'product_id' => $p->id, 'minimum_quantity' => 50]); // فجوة = 1

        $result = $this->engine()->replenish([$a->id]);

        $this->assertSame(1, $result->invoicesCreated);   // 1 > 0 ← تُشحن (السلوك القديم بلا تغيير)
    }
}
