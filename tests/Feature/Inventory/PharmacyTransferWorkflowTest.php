<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\PharmacyTransferStatus;
use App\Models\Pharmacy;
use App\Models\PharmacyInventory;
use App\Models\PharmacyMinimumStock;
use App\Models\PharmacyTransfer;
use App\Models\PharmacyTransferItem;
use App\Models\Product;
use App\Services\Inventory\PharmacyTransferExecutionService;
use App\Services\Inventory\PharmacyTransferSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class PharmacyTransferWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function suggestionService(): PharmacyTransferSuggestionService
    {
        return $this->app->make(PharmacyTransferSuggestionService::class);
    }

    private function executionService(): PharmacyTransferExecutionService
    {
        return $this->app->make(PharmacyTransferExecutionService::class);
    }

    private function pair(Pharmacy $pharmacy, Product $product, int $current, int $standard): void
    {
        PharmacyInventory::create(['pharmacy_id' => $pharmacy->id, 'product_id' => $product->id, 'quantity' => $current]);
        PharmacyMinimumStock::create(['pharmacy_id' => $pharmacy->id, 'product_id' => $product->id, 'minimum_quantity' => $standard]);
    }

    // ══════════ PharmacyTransferSuggestionService::refresh() ══════════

    public function test_refresh_persists_one_document_per_pharmacy_pair_grouping_all_their_products(): void
    {
        $a = Pharmacy::create(['name' => 'صيدلية أ']);
        $b = Pharmacy::create(['name' => 'صيدلية ب']);
        $p1 = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'ش']);
        $p2 = Product::create(['barcode' => '222', 'name' => 'أموكسيل', 'company' => 'ش']);
        $this->pair($a, $p1, current: 100, standard: 20);
        $this->pair($b, $p1, current: 10, standard: 50);
        $this->pair($a, $p2, current: 90, standard: 10);
        $this->pair($b, $p2, current: 5, standard: 40);

        $written = $this->suggestionService()->refresh();

        $this->assertSame(2, $written);            // صنفان
        $this->assertSame(1, PharmacyTransfer::count());   // بمستند واحد (نفس زوج الصيدليتين)
        $transfer = PharmacyTransfer::first();
        $this->assertSame(PharmacyTransferStatus::Suggested, $transfer->status);
        $this->assertSame($a->id, $transfer->from_pharmacy_id);
        $this->assertSame($b->id, $transfer->to_pharmacy_id);
        $this->assertCount(2, $transfer->items);
    }

    public function test_refresh_replaces_previous_suggestions_entirely_but_never_touches_decided_ones(): void
    {
        $a = Pharmacy::create(['name' => 'صيدلية أ']);
        $b = Pharmacy::create(['name' => 'صيدلية ب']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'ش']);
        $this->pair($a, $product, current: 100, standard: 20);
        $this->pair($b, $product, current: 10, standard: 50);

        $this->suggestionService()->refresh();
        $firstRoundId = PharmacyTransfer::first()->id;

        // قرار بشري على الاقتراح الأول — يجب أن يبقى محفوظاً للأبد
        $this->executionService()->dismiss($firstRoundId);

        // تغيّر الوضع: صيدلية ب لم تعد بحاجة (ارتفع رصيدها)
        PharmacyInventory::where('pharmacy_id', $b->id)->update(['quantity' => 60]);
        $this->suggestionService()->refresh();

        $this->assertSame(0, PharmacyTransfer::suggested()->count());   // لا شيء جديد يستحق الاقتراح الآن
        $this->assertSame(PharmacyTransferStatus::Dismissed, PharmacyTransfer::find($firstRoundId)->status);   // لم يُمَس
    }

    public function test_refresh_with_no_opportunities_writes_nothing_and_returns_zero(): void
    {
        $this->assertSame(0, $this->suggestionService()->refresh());
        $this->assertSame(0, PharmacyTransfer::count());
    }

    // ══════════ PharmacyTransferExecutionService::approve() ══════════

    public function test_approving_a_fresh_suggestion_moves_the_full_quantity_between_pharmacies(): void
    {
        $a = Pharmacy::create(['name' => 'صيدلية أ']);
        $b = Pharmacy::create(['name' => 'صيدلية ب']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'ش']);
        $this->pair($a, $product, current: 100, standard: 20);   // فائض 80
        $this->pair($b, $product, current: 10, standard: 50);     // فجوة 40

        $this->suggestionService()->refresh();
        $transfer = PharmacyTransfer::first();

        $outcome = $this->executionService()->approve($transfer->id);

        $this->assertTrue($outcome->wasFullyAsSuggested());
        $this->assertSame(1, $outcome->fullyExecutedCount);
        $this->assertSame(60, (int) PharmacyInventory::where('pharmacy_id', $a->id)->where('product_id', $product->id)->value('quantity'));   // 100-40
        $this->assertSame(50, (int) PharmacyInventory::where('pharmacy_id', $b->id)->where('product_id', $product->id)->value('quantity'));   // 10+40
        $transfer->refresh();
        $this->assertSame(PharmacyTransferStatus::Completed, $transfer->status);
        $this->assertNotNull($transfer->executed_date);
    }

    public function test_approving_creates_the_destination_inventory_row_if_it_somehow_does_not_exist(): void
    {
        $a = Pharmacy::create(['name' => 'صيدلية أ']);
        $b = Pharmacy::create(['name' => 'صيدلية ب']);
        $p1 = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'ش']);
        $p2 = Product::create(['barcode' => '222', 'name' => 'أموكسيل', 'company' => 'ش']);
        PharmacyInventory::create(['pharmacy_id' => $a->id, 'product_id' => $p1->id, 'quantity' => 100]);
        PharmacyInventory::create(['pharmacy_id' => $b->id, 'product_id' => $p1->id, 'quantity' => 5]);

        // مناقلة يدوية مباشرة (بلا مرور بالمخطِّط) لصنف لم يُذكَر لصيدلية ب إطلاقاً بجدول الجرد
        $transfer = PharmacyTransfer::create(['from_pharmacy_id' => $a->id, 'to_pharmacy_id' => $b->id, 'status' => PharmacyTransferStatus::Suggested]);
        PharmacyTransferItem::create(['pharmacy_transfer_id' => $transfer->id, 'product_id' => $p2->id, 'quantity' => 10, 'from_pharmacy_excess_at_time' => 10, 'to_pharmacy_gap_at_time' => 10]);
        PharmacyInventory::create(['pharmacy_id' => $a->id, 'product_id' => $p2->id, 'quantity' => 50]);

        $this->executionService()->approve($transfer->id);

        $this->assertSame(10, (int) PharmacyInventory::where('pharmacy_id', $b->id)->where('product_id', $p2->id)->value('quantity'));
    }

    public function test_a_stale_suggestion_with_less_stock_available_now_transfers_only_what_actually_exists(): void
    {
        // 🎯 يثبت نقطة الأمان الجوهرية بتعليق الكلاس: بين الاقتراح والموافقة، انخفض
        // رصيد المصدر (مثلاً استهلكته موافقة أخرى) — يجب ألا يُخلَق رصيد من العدم.
        $a = Pharmacy::create(['name' => 'صيدلية أ']);
        $b = Pharmacy::create(['name' => 'صيدلية ب']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'ش']);
        $this->pair($a, $product, current: 100, standard: 20);
        $this->pair($b, $product, current: 10, standard: 50);

        $this->suggestionService()->refresh();
        $transfer = PharmacyTransfer::first();
        $this->assertSame(40, (int) PharmacyTransferItem::first()->quantity);   // الكمية المقترَحة الأصلية

        // تغيّر الرصيد الفعلي بمصدر آخر (يحاكي: مناقلة أخرى استهلكت الفائض جزئياً) قبل الموافقة
        PharmacyInventory::where('pharmacy_id', $a->id)->where('product_id', $product->id)->update(['quantity' => 15]);

        $outcome = $this->executionService()->approve($transfer->id);

        $this->assertSame(1, $outcome->partiallyExecutedCount);
        $this->assertFalse($outcome->wasFullyAsSuggested());
        // نُقل 15 فقط (المتاح الفعلي)، لا 40 المقترَحة — لا رصيد وُلِد من العدم
        $this->assertSame(0, (int) PharmacyInventory::where('pharmacy_id', $a->id)->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(25, (int) PharmacyInventory::where('pharmacy_id', $b->id)->where('product_id', $product->id)->value('quantity'));   // 10+15
    }

    public function test_a_stale_suggestion_with_zero_stock_available_now_is_skipped_entirely(): void
    {
        $a = Pharmacy::create(['name' => 'صيدلية أ']);
        $b = Pharmacy::create(['name' => 'صيدلية ب']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'ش']);
        $this->pair($a, $product, current: 100, standard: 20);
        $this->pair($b, $product, current: 10, standard: 50);

        $this->suggestionService()->refresh();
        $transfer = PharmacyTransfer::first();

        PharmacyInventory::where('pharmacy_id', $a->id)->where('product_id', $product->id)->update(['quantity' => 0]);

        $outcome = $this->executionService()->approve($transfer->id);

        $this->assertSame(1, $outcome->skippedCount);
        $this->assertSame(10, (int) PharmacyInventory::where('pharmacy_id', $b->id)->where('product_id', $product->id)->value('quantity'));   // بلا تغيير
        // المناقلة تُكتمَل رغم عدم نقل شيء فعلياً — القرار البشري (الموافقة) اتُّخذ، والنتيجة توثِّق أنه لم يُنفَّذ شيء.
        $transfer->refresh();
        $this->assertSame(PharmacyTransferStatus::Completed, $transfer->status);
    }

    public function test_approving_an_already_decided_transfer_throws(): void
    {
        $a = Pharmacy::create(['name' => 'صيدلية أ']);
        $b = Pharmacy::create(['name' => 'صيدلية ب']);
        $transfer = PharmacyTransfer::create(['from_pharmacy_id' => $a->id, 'to_pharmacy_id' => $b->id, 'status' => PharmacyTransferStatus::Dismissed]);

        $this->expectException(RuntimeException::class);

        $this->executionService()->approve($transfer->id);
    }

    public function test_dismissing_a_suggestion_leaves_inventory_completely_untouched(): void
    {
        $a = Pharmacy::create(['name' => 'صيدلية أ']);
        $b = Pharmacy::create(['name' => 'صيدلية ب']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'ش']);
        $this->pair($a, $product, current: 100, standard: 20);
        $this->pair($b, $product, current: 10, standard: 50);

        $this->suggestionService()->refresh();
        $transfer = PharmacyTransfer::first();

        $this->executionService()->dismiss($transfer->id);

        $this->assertSame(100, (int) PharmacyInventory::where('pharmacy_id', $a->id)->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(10, (int) PharmacyInventory::where('pharmacy_id', $b->id)->where('product_id', $product->id)->value('quantity'));
        $transfer->refresh();
        $this->assertSame(PharmacyTransferStatus::Dismissed, $transfer->status);
    }

    public function test_dismissing_an_already_decided_transfer_throws(): void
    {
        $a = Pharmacy::create(['name' => 'صيدلية أ']);
        $b = Pharmacy::create(['name' => 'صيدلية ب']);
        $transfer = PharmacyTransfer::create(['from_pharmacy_id' => $a->id, 'to_pharmacy_id' => $b->id, 'status' => PharmacyTransferStatus::Completed]);

        $this->expectException(RuntimeException::class);

        $this->executionService()->dismiss($transfer->id);
    }

    public function test_approving_a_nonexistent_transfer_throws(): void
    {
        $this->expectException(RuntimeException::class);

        $this->executionService()->approve(999999);
    }

    public function test_a_multi_product_transfer_document_executes_all_its_items_atomically(): void
    {
        $a = Pharmacy::create(['name' => 'صيدلية أ']);
        $b = Pharmacy::create(['name' => 'صيدلية ب']);
        $p1 = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'ش']);
        $p2 = Product::create(['barcode' => '222', 'name' => 'أموكسيل', 'company' => 'ش']);
        $this->pair($a, $p1, current: 100, standard: 20);
        $this->pair($b, $p1, current: 10, standard: 50);
        $this->pair($a, $p2, current: 90, standard: 10);
        $this->pair($b, $p2, current: 5, standard: 40);

        $this->suggestionService()->refresh();
        $transfer = PharmacyTransfer::first();

        $outcome = $this->executionService()->approve($transfer->id);

        $this->assertSame(2, $outcome->fullyExecutedCount);
        $this->assertSame(50, (int) PharmacyInventory::where('pharmacy_id', $b->id)->where('product_id', $p1->id)->value('quantity'));
        $this->assertSame(40, (int) PharmacyInventory::where('pharmacy_id', $b->id)->where('product_id', $p2->id)->value('quantity'));
    }
}
