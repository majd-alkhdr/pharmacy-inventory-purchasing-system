<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\CentralInventory;
use App\Models\ExcludedCompany;
use App\Models\Pharmacy;
use App\Models\PharmacyInventory;
use App\Models\PharmacyMinimumStock;
use App\Models\Product;
use App\Models\Shortage;
use App\Models\TemporarySale;
use App\Services\Inventory\ParLevelCalculator;
use App\Services\Inventory\ReverseTransferRecorderService;
use App\Services\Inventory\RestockingContext;
use App\Services\Inventory\SalesGatedStandardInventoryRestockingStrategy;
use App\Services\Inventory\SalesRemainderResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SalesGatedStandardInventoryRestockingStrategyTest extends TestCase
{
    use RefreshDatabase;

    private function strategy(): SalesGatedStandardInventoryRestockingStrategy
    {
        return $this->app->make(SalesGatedStandardInventoryRestockingStrategy::class);
    }

    /** @return array{0: Pharmacy, 1: Product} */
    private function world(int $centralStock = 100, int $pharmacyStock = 40, int $standardQty = 50): array
    {
        $pharmacy = Pharmacy::create(['name' => 'صيدلية أ']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ']);
        CentralInventory::create(['product_id' => $product->id, 'quantity' => $centralStock]);
        PharmacyInventory::create(['pharmacy_id' => $pharmacy->id, 'product_id' => $product->id, 'quantity' => $pharmacyStock]);
        PharmacyMinimumStock::create(['pharmacy_id' => $pharmacy->id, 'product_id' => $product->id, 'minimum_quantity' => $standardQty]);

        return [$pharmacy, $product];
    }

    private function sell(Pharmacy $pharmacy, Product $product, string $sessionId, float $quantity): void
    {
        TemporarySale::create([
            'session_id' => $sessionId,
            'pharmacy_name' => $pharmacy->name,
            'company' => $product->company,
            'material_name' => $product->name,
            'quantity' => $quantity,
            'pharmacy_id' => $pharmacy->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_a_sale_that_drops_stock_below_par_triggers_a_restock_sized_to_the_gap_not_the_sale(): void
    {
        // رصيد 40، قياسي 50 (فجوة أصلية 10). بيع 15 → الرصيد الناتج 25 → فجوة 25، لا 15.
        [$pharmacy, $product] = $this->world(centralStock: 100, pharmacyStock: 40, standardQty: 50);
        $this->sell($pharmacy, $product, 's1', 15);

        $result = $this->strategy()->execute(new RestockingContext(mode: \App\Enums\RestockingMode::SalesGatedStandardInventory, sessionId: 's1'));

        $this->assertSame(1, $result->invoicesCreated);
        $item = DB::table('invoice_items')->first();
        $this->assertSame([25, 25, 0], [(int) $item->requested_qty, (int) $item->supplied_qty, (int) $item->shortage_qty]);
        $this->assertSame(25, (int) PharmacyInventory::where('pharmacy_id', $pharmacy->id)->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(75, (int) CentralInventory::where('product_id', $product->id)->value('quantity'));
    }

    public function test_a_sale_that_leaves_stock_at_or_above_par_triggers_no_restock(): void
    {
        // رصيد 60، قياسي 50 (فوق الهدف أصلاً). بيع 5 → الرصيد الناتج 55، ما زال فوق 50 —
        // وهذا فائض حقيقي (5 > حد التسامح 0) يُشغِّل مناقلة عكسية، رغم عدم وجود حاجة للترميم.
        [$pharmacy, $product] = $this->world(centralStock: 100, pharmacyStock: 60, standardQty: 50);
        $this->sell($pharmacy, $product, 's1', 5);

        $result = $this->strategy()->execute(new RestockingContext(mode: \App\Enums\RestockingMode::SalesGatedStandardInventory, sessionId: 's1'));

        $this->assertSame(0, $result->invoicesCreated);
        $this->assertSame(1, $result->reverseTransfersCreated);
        // 🆕 (ب.6) العكسية أصبحت **مقترحة** لا منفَّذة: الرصيد يُخصَم بالبيع فقط (60→55)،
        // والفائض (5) ينتظر "تنفيذ" صريحاً — لا حركة مخزون عند التسجيل.
        $this->assertSame(55, (int) PharmacyInventory::where('pharmacy_id', $pharmacy->id)->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(\App\Enums\ReverseTransferStatus::Suggested, \App\Models\ReverseTransfer::first()->status);
    }

    public function test_a_pair_with_no_standard_quantity_is_never_restocked_but_its_stock_is_still_decremented(): void
    {
        $pharmacy = Pharmacy::create(['name' => 'صيدلية أ']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ']);
        CentralInventory::create(['product_id' => $product->id, 'quantity' => 100]);
        PharmacyInventory::create(['pharmacy_id' => $pharmacy->id, 'product_id' => $product->id, 'quantity' => 40]);
        // لا صف pharmacy_minimum_stocks هنا عمداً

        $this->sell($pharmacy, $product, 's1', 15);
        $result = $this->strategy()->execute(new RestockingContext(mode: \App\Enums\RestockingMode::SalesGatedStandardInventory, sessionId: 's1'));

        $this->assertSame(0, $result->invoicesCreated);
        $this->assertSame(0, $result->pairsEvaluated);
        $this->assertSame(25, (int) PharmacyInventory::where('pharmacy_id', $pharmacy->id)->where('product_id', $product->id)->value('quantity'));
    }

    public function test_a_sale_for_a_pair_with_no_existing_inventory_row_is_skipped_entirely_no_row_is_invented(): void
    {
        $pharmacy = Pharmacy::create(['name' => 'صيدلية أ']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ']);
        CentralInventory::create(['product_id' => $product->id, 'quantity' => 100]);
        PharmacyMinimumStock::create(['pharmacy_id' => $pharmacy->id, 'product_id' => $product->id, 'minimum_quantity' => 50]);
        // لا صف pharmacy_inventories هنا عمداً — لا خط أساس معروف

        $this->sell($pharmacy, $product, 's1', 15);
        $result = $this->strategy()->execute(new RestockingContext(mode: \App\Enums\RestockingMode::SalesGatedStandardInventory, sessionId: 's1'));

        $this->assertSame(0, $result->invoicesCreated);
        $this->assertNull(PharmacyInventory::where('pharmacy_id', $pharmacy->id)->where('product_id', $product->id)->first());
    }

    public function test_resulting_stock_never_goes_negative_even_if_recorded_stock_understates_what_sold(): void
    {
        [$pharmacy, $product] = $this->world(centralStock: 100, pharmacyStock: 10, standardQty: 50);
        $this->sell($pharmacy, $product, 's1', 30); // أكبر من الرصيد المسجَّل

        $this->strategy()->execute(new RestockingContext(mode: \App\Enums\RestockingMode::SalesGatedStandardInventory, sessionId: 's1'));

        $this->assertSame(0, (int) PharmacyInventory::where('pharmacy_id', $pharmacy->id)->where('product_id', $product->id)->value('quantity'));
    }

    public function test_excluded_company_pairs_are_filtered_out_entirely(): void
    {
        [$pharmacy, $product] = $this->world(centralStock: 100, pharmacyStock: 40, standardQty: 50);
        ExcludedCompany::create(['company' => 'شركة أ']);
        $this->sell($pharmacy, $product, 's1', 15);

        $result = $this->strategy()->execute(new RestockingContext(mode: \App\Enums\RestockingMode::SalesGatedStandardInventory, sessionId: 's1'));

        $this->assertSame(0, $result->invoicesCreated);
        $this->assertSame(0, $result->pairsEvaluated);
        // الرصيد لا يزال يُحدَّث (قرار 2 مستقل عن الاستثناء)
        $this->assertSame(25, (int) PharmacyInventory::where('pharmacy_id', $pharmacy->id)->where('product_id', $product->id)->value('quantity'));
    }

    public function test_stock_still_far_above_par_after_the_sale_triggers_a_reverse_transfer(): void
    {
        // رصيد 200، قياسي 50. بيع 5 فقط → الرصيد الناتج 195، فائض ضخم رغم البيع.
        [$pharmacy, $product] = $this->world(centralStock: 100, pharmacyStock: 200, standardQty: 50);
        $this->sell($pharmacy, $product, 's1', 5);

        $result = $this->strategy()->execute(new RestockingContext(mode: \App\Enums\RestockingMode::SalesGatedStandardInventory, sessionId: 's1'));

        $this->assertSame(1, $result->reverseTransfersCreated);
        $this->assertSame(0, $result->invoicesCreated);
        // 🆕 (ب.5/ب.6) لا حركة مخزون عند تسجيل العكسية: الصيدلية 195 (البيع فقط)، والمركزي
        // باقٍ 100 — إضافة الفائض للمركزي فوراً كانت قناة تسرّبه لفواتير النواقص. الكمية المقترحة 145.
        $this->assertSame(195, (int) PharmacyInventory::where('pharmacy_id', $pharmacy->id)->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(100, (int) CentralInventory::where('product_id', $product->id)->value('quantity'));
        $this->assertSame(145, (int) \App\Models\ReverseTransferItem::first()->quantity);
    }

    public function test_a_shortage_is_created_when_central_stock_cannot_cover_the_full_gap(): void
    {
        [$pharmacy, $product] = $this->world(centralStock: 10, pharmacyStock: 40, standardQty: 50);
        $this->sell($pharmacy, $product, 's1', 15);   // فجوة 25، المركزي يملك 10 فقط

        $result = $this->strategy()->execute(new RestockingContext(mode: \App\Enums\RestockingMode::SalesGatedStandardInventory, sessionId: 's1'));

        $this->assertSame(1, $result->shortagesRecorded);
        $shortage = Shortage::first();
        $this->assertSame(15, (int) $shortage->quantity);   // 25 - 10
        $this->assertSame('standard_inventory', $shortage->source->value);
    }

    public function test_fractional_sales_carry_forward_across_batches_via_the_shared_remainder_service(): void
    {
        [$pharmacy, $product] = $this->world(centralStock: 100, pharmacyStock: 40, standardQty: 50);

        $this->sell($pharmacy, $product, 's1', 0.6);
        $result1 = $this->strategy()->execute(new RestockingContext(mode: \App\Enums\RestockingMode::SalesGatedStandardInventory, sessionId: 's1'));
        $this->assertSame(0, $result1->invoicesCreated);   // 0.6 لم يصل بعد لعدد صحيح
        $this->assertSame(40, (int) PharmacyInventory::where('pharmacy_id', $pharmacy->id)->where('product_id', $product->id)->value('quantity'));

        $this->sell($pharmacy, $product, 's2', 0.7);   // 0.6 + 0.7 = 1.3 → وحدة واحدة تُشحن، 0.3 تبقى باقياً
        $result2 = $this->strategy()->execute(new RestockingContext(mode: \App\Enums\RestockingMode::SalesGatedStandardInventory, sessionId: 's2'));

        $this->assertSame(39, (int) PharmacyInventory::where('pharmacy_id', $pharmacy->id)->where('product_id', $product->id)->value('quantity'));
    }

    public function test_two_pharmacies_needing_the_same_product_share_the_running_central_allocation(): void
    {
        $a = Pharmacy::create(['name' => 'صيدلية أ']);
        $b = Pharmacy::create(['name' => 'صيدلية ب']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ']);
        CentralInventory::create(['product_id' => $product->id, 'quantity' => 30]);
        PharmacyInventory::create(['pharmacy_id' => $a->id, 'product_id' => $product->id, 'quantity' => 0]);
        PharmacyInventory::create(['pharmacy_id' => $b->id, 'product_id' => $product->id, 'quantity' => 0]);
        PharmacyMinimumStock::create(['pharmacy_id' => $a->id, 'product_id' => $product->id, 'minimum_quantity' => 20]);
        PharmacyMinimumStock::create(['pharmacy_id' => $b->id, 'product_id' => $product->id, 'minimum_quantity' => 20]);

        $this->sell($a, $product, 's1', 1);
        $this->sell($b, $product, 's1', 1);

        $result = $this->strategy()->execute(new RestockingContext(mode: \App\Enums\RestockingMode::SalesGatedStandardInventory, sessionId: 's1'));

        // كل صيدلية تحتاج 20، المركزي يملك 30 فقط — يُوزَّع بالتتابع لا يُضاعَف
        $this->assertSame(2, $result->invoicesCreated);
        $this->assertSame(0, (int) CentralInventory::where('product_id', $product->id)->value('quantity'));
        $this->assertSame(1, $result->shortagesRecorded);
    }

    public function test_no_session_id_returns_an_empty_result(): void
    {
        $result = $this->strategy()->execute(new RestockingContext(mode: \App\Enums\RestockingMode::SalesGatedStandardInventory));

        $this->assertSame(0, $result->invoicesCreated);
    }

    public function test_an_empty_sales_session_returns_empty_and_cleans_up(): void
    {
        $result = $this->strategy()->execute(new RestockingContext(mode: \App\Enums\RestockingMode::SalesGatedStandardInventory, sessionId: 'empty'));

        $this->assertSame(0, $result->invoicesCreated);
    }

    public function test_source_is_standard_inventory_not_a_new_enum_case(): void
    {
        $this->assertSame(\App\Enums\ShortageSource::StandardInventory, $this->strategy()->source());
    }

    public function test_query_count_does_not_grow_linearly_with_the_number_of_distinct_products_sold(): void
    {
        // 🩹 [حماية إصلاح N+1] كان تحديث central_inventories وpharmacy_inventories
        // استعلاماً منفصلاً لكل منتج مختلف — يثبت هذا أن العدد أصبح ثابتاً تقريباً
        // (دفعات upsert)، لا يتناسب مع عدد المنتجات المختلفة.
        $pharmacy = Pharmacy::create(['name' => 'صيدلية أ']);
        for ($i = 1; $i <= 60; $i++) {
            $product = Product::create(['barcode' => (string) $i, 'name' => "م{$i}", 'company' => 'ش']);
            CentralInventory::create(['product_id' => $product->id, 'quantity' => 100]);
            PharmacyInventory::create(['pharmacy_id' => $pharmacy->id, 'product_id' => $product->id, 'quantity' => 10]);
            PharmacyMinimumStock::create(['pharmacy_id' => $pharmacy->id, 'product_id' => $product->id, 'minimum_quantity' => 50]);
            $this->sell($pharmacy, $product, 's1', 2);   // فجوة فعلية لكل منتج ← يلمس central وpharmacy_inventories لكل واحد
        }

        $count = 0;
        DB::listen(function () use (&$count): void { $count++; });
        $this->strategy()->execute(new RestockingContext(mode: \App\Enums\RestockingMode::SalesGatedStandardInventory, sessionId: 's1'));

        // ثابت تقريباً بصرف النظر عن 60 منتجاً — لا استعلام واحد لكل منتج (كان سيتجاوز 120 بسهولة)
        $this->assertLessThan(30, $count);
    }

    // ══════════ [تشخيص] لماذا زوج باع شيئاً لم يُقيَّم إطلاقاً؟ ══════════

    public function test_diagnostics_count_sold_pairs_skipped_for_lacking_a_pharmacy_inventory_baseline(): void
    {
        $pharmacy = Pharmacy::create(['name' => 'صيدلية أ']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'ش']);
        // عمداً: لا صف PharmacyInventory ولا PharmacyMinimumStock إطلاقاً لهذا الزوج
        $this->sell($pharmacy, $product, 's1', 5);

        $result = $this->strategy()->execute(new RestockingContext(mode: \App\Enums\RestockingMode::SalesGatedStandardInventory, sessionId: 's1'));

        $this->assertSame(1, $result->skippedNoBaseline);
        $this->assertSame(0, $result->skippedNoStandard);
        $this->assertSame(0, $result->pairsEvaluated);
    }

    public function test_diagnostics_count_sold_pairs_skipped_for_lacking_a_standard_quantity(): void
    {
        $pharmacy = Pharmacy::create(['name' => 'صيدلية أ']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'ش']);
        PharmacyInventory::create(['pharmacy_id' => $pharmacy->id, 'product_id' => $product->id, 'quantity' => 40]);
        // عمداً: بلا PharmacyMinimumStock
        $this->sell($pharmacy, $product, 's1', 5);

        $result = $this->strategy()->execute(new RestockingContext(mode: \App\Enums\RestockingMode::SalesGatedStandardInventory, sessionId: 's1'));

        $this->assertSame(0, $result->skippedNoBaseline);
        $this->assertSame(1, $result->skippedNoStandard);
        $this->assertSame(0, $result->pairsEvaluated);
        // الرصيد يُحدَّث رغم غياب المخزون القياسي — القرار 2 بتعليق الكلاس
        $this->assertSame(35, (int) PharmacyInventory::where('pharmacy_id', $pharmacy->id)->where('product_id', $product->id)->value('quantity'));
    }

    public function test_diagnostics_are_zero_when_every_sold_pair_is_fully_evaluated(): void
    {
        [$pharmacy, $product] = $this->world(centralStock: 100, pharmacyStock: 40, standardQty: 50);
        $this->sell($pharmacy, $product, 's1', 5);

        $result = $this->strategy()->execute(new RestockingContext(mode: \App\Enums\RestockingMode::SalesGatedStandardInventory, sessionId: 's1'));

        $this->assertSame(0, $result->skippedNoBaseline);
        $this->assertSame(0, $result->skippedNoStandard);
        $this->assertSame(1, $result->pairsEvaluated);
    }
}
