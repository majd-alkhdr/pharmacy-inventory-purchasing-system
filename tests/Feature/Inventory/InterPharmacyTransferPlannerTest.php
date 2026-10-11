<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\ExcludedCompany;
use App\Models\Pharmacy;
use App\Models\PharmacyInventory;
use App\Models\PharmacyMinimumStock;
use App\Models\Product;
use App\Services\Inventory\InterPharmacyTransferPlanner;
use App\Services\Inventory\TransferSuggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InterPharmacyTransferPlannerTest extends TestCase
{
    use RefreshDatabase;

    private function planner(): InterPharmacyTransferPlanner
    {
        return $this->app->make(InterPharmacyTransferPlanner::class);
    }

    private function pair(Pharmacy $pharmacy, Product $product, int $current, int $standard, int $minReorderGap = 0): void
    {
        PharmacyInventory::create(['pharmacy_id' => $pharmacy->id, 'product_id' => $product->id, 'quantity' => $current]);
        PharmacyMinimumStock::create([
            'pharmacy_id' => $pharmacy->id, 'product_id' => $product->id,
            'minimum_quantity' => $standard, 'min_reorder_gap' => $minReorderGap,
        ]);
    }

    public function test_a_straightforward_excess_and_need_pair_produces_one_suggestion(): void
    {
        $a = Pharmacy::create(['name' => 'صيدلية أ']);
        $b = Pharmacy::create(['name' => 'صيدلية ب']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ']);
        $this->pair($a, $product, current: 100, standard: 20);   // فائض 80
        $this->pair($b, $product, current: 10, standard: 50);    // فجوة 40

        $suggestions = $this->planner()->plan();

        $this->assertCount(1, $suggestions);
        $s = $suggestions[0];
        $this->assertInstanceOf(TransferSuggestion::class, $s);
        $this->assertSame($product->id, $s->productId);
        $this->assertSame($a->id, $s->fromPharmacyId);
        $this->assertSame($b->id, $s->toPharmacyId);
        $this->assertSame(40, $s->quantity);   // مقيَّد بالحاجة (40)، لا الفائض (80)
    }

    public function test_no_suggestion_when_only_excess_exists_with_no_matching_need(): void
    {
        $a = Pharmacy::create(['name' => 'صيدلية أ']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ']);
        $this->pair($a, $product, current: 100, standard: 20);

        $this->assertSame([], $this->planner()->plan());
    }

    public function test_no_suggestion_when_only_need_exists_with_no_matching_excess(): void
    {
        $b = Pharmacy::create(['name' => 'صيدلية ب']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ']);
        $this->pair($b, $product, current: 10, standard: 50);

        $this->assertSame([], $this->planner()->plan());
    }

    public function test_gap_within_tolerance_is_not_a_need_and_excess_within_tolerance_is_not_a_surplus(): void
    {
        $a = Pharmacy::create(['name' => 'صيدلية أ']);
        $b = Pharmacy::create(['name' => 'صيدلية ب']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ']);
        $this->pair($a, $product, current: 55, standard: 50, minReorderGap: 10);   // فائض 5، دون التسامح 10
        $this->pair($b, $product, current: 45, standard: 50, minReorderGap: 10);   // فجوة 5، دون التسامح 10

        $this->assertSame([], $this->planner()->plan());
    }

    public function test_a_single_excess_source_can_cover_two_needy_pharmacies(): void
    {
        $source = Pharmacy::create(['name' => 'مصدر']);
        $b = Pharmacy::create(['name' => 'ب']);
        $c = Pharmacy::create(['name' => 'ج']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ']);
        $this->pair($source, $product, current: 200, standard: 20);   // فائض 180
        $this->pair($b, $product, current: 10, standard: 50);          // فجوة 40 (الأكبر أولاً)
        $this->pair($c, $product, current: 30, standard: 50);          // فجوة 20

        $suggestions = $this->planner()->plan();

        $this->assertCount(2, $suggestions);
        $toB = array_values(array_filter($suggestions, fn ($s) => $s->toPharmacyId === $b->id))[0];
        $toC = array_values(array_filter($suggestions, fn ($s) => $s->toPharmacyId === $c->id))[0];
        $this->assertSame([40, 20], [$toB->quantity, $toC->quantity]);
        $this->assertSame($source->id, $toB->fromPharmacyId);
        $this->assertSame($source->id, $toC->fromPharmacyId);
    }

    public function test_insufficient_total_excess_partially_covers_the_larger_need_first(): void
    {
        $source = Pharmacy::create(['name' => 'مصدر']);
        $big = Pharmacy::create(['name' => 'حاجة كبيرة']);
        $small = Pharmacy::create(['name' => 'حاجة صغيرة']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ']);
        $this->pair($source, $product, current: 50, standard: 20);   // فائض 30 فقط
        $this->pair($big, $product, current: 0, standard: 40);        // فجوة 40
        $this->pair($small, $product, current: 10, standard: 20);     // فجوة 10

        $suggestions = $this->planner()->plan();

        // الحاجة الأكبر (40) تُعالَج أولاً، تستهلك كل الفائض (30) جزئياً — لا شيء يبقى للأصغر.
        $this->assertCount(1, $suggestions);
        $this->assertSame($big->id, $suggestions[0]->toPharmacyId);
        $this->assertSame(30, $suggestions[0]->quantity);
    }

    public function test_two_excess_sources_combine_to_cover_one_larger_need(): void
    {
        $s1 = Pharmacy::create(['name' => 'مصدر 1']);
        $s2 = Pharmacy::create(['name' => 'مصدر 2']);
        $needy = Pharmacy::create(['name' => 'محتاجة']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ']);
        $this->pair($s1, $product, current: 70, standard: 20);   // فائض 50 (الأكبر)
        $this->pair($s2, $product, current: 40, standard: 20);   // فائض 20
        $this->pair($needy, $product, current: 0, standard: 60);  // فجوة 60

        $suggestions = $this->planner()->plan();

        $this->assertCount(2, $suggestions);
        $this->assertEqualsCanonicalizing(
            [[$s1->id, 50], [$s2->id, 10]],   // s1 يغطي 50 من أصل 60، s2 يكمل الباقي 10 فقط (لا فائضه الكامل 20)
            array_map(fn ($s) => [$s->fromPharmacyId, $s->quantity], $suggestions),
        );
    }

    public function test_quantity_rounds_down_to_a_whole_pack_using_the_destination_pack_size(): void
    {
        $a = Pharmacy::create(['name' => 'صيدلية أ']);
        $b = Pharmacy::create(['name' => 'صيدلية ب']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ', 'pack_size' => 30]);
        $this->pair($a, $product, current: 100, standard: 20);   // فائض 80 (مضاعف 30: 60)
        $this->pair($b, $product, current: 10, standard: 60);     // فجوة خام 50 (مضاعف 30: 30)

        $suggestions = $this->planner()->plan();

        $this->assertCount(1, $suggestions);
        $this->assertSame(30, $suggestions[0]->quantity);   // min(60, 50) مُقرَّب لأقرب عبوة = 30
    }

    public function test_a_remaining_need_smaller_than_one_pack_produces_no_suggestion_for_it(): void
    {
        $a = Pharmacy::create(['name' => 'صيدلية أ']);
        $b = Pharmacy::create(['name' => 'صيدلية ب']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ', 'pack_size' => 100]);
        $this->pair($a, $product, current: 500, standard: 20);   // فائض ضخم
        $this->pair($b, $product, current: 40, standard: 60);     // فجوة خام 20 — أصغر من عبوة 100

        $this->assertSame([], $this->planner()->plan());
    }

    public function test_a_pair_without_an_existing_inventory_row_is_never_a_source_or_a_need(): void
    {
        $a = Pharmacy::create(['name' => 'صيدلية أ']);
        $b = Pharmacy::create(['name' => 'صيدلية ب']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ']);
        // مخزون قياسي بلا أي صف جرد فعلي لأي منهما
        PharmacyMinimumStock::create(['pharmacy_id' => $a->id, 'product_id' => $product->id, 'minimum_quantity' => 20]);
        PharmacyMinimumStock::create(['pharmacy_id' => $b->id, 'product_id' => $product->id, 'minimum_quantity' => 50]);

        $this->assertSame([], $this->planner()->plan());
    }

    public function test_excluded_company_products_never_produce_suggestions(): void
    {
        $a = Pharmacy::create(['name' => 'صيدلية أ']);
        $b = Pharmacy::create(['name' => 'صيدلية ب']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة مستثناة']);
        ExcludedCompany::create(['company' => 'شركة مستثناة']);
        $this->pair($a, $product, current: 100, standard: 20);
        $this->pair($b, $product, current: 10, standard: 50);

        $this->assertSame([], $this->planner()->plan());
    }

    public function test_different_products_never_match_each_other(): void
    {
        $a = Pharmacy::create(['name' => 'صيدلية أ']);
        $b = Pharmacy::create(['name' => 'صيدلية ب']);
        $productX = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ']);
        $productY = Product::create(['barcode' => '222', 'name' => 'أموكسيل', 'company' => 'شركة أ']);
        $this->pair($a, $productX, current: 100, standard: 20);   // فائض X
        $this->pair($b, $productY, current: 10, standard: 50);     // فجوة Y — منتج مختلف تماماً

        $this->assertSame([], $this->planner()->plan());
    }

    public function test_no_standards_at_all_returns_empty_without_querying_further(): void
    {
        $this->assertSame([], $this->planner()->plan());
    }
}
