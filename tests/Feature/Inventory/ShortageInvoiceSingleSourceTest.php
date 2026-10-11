<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\ShortageStatus;
use App\Models\CentralInventory;
use App\Models\Pharmacy;
use App\Models\PharmacyInventory;
use App\Models\PharmacyMinimumStock;
use App\Models\Product;
use App\Models\ReverseTransfer;
use App\Models\ReverseTransferItem;
use App\Models\Shortage;
use App\Services\Inventory\ParLevelReplenishmentService;
use App\Services\Inventory\ReverseTransferRecorderService;
use App\Services\Inventory\ShortageFulfillmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 🎯 (ب.5) يحل محل RedistributionTraceabilityTest — ذاك كان يثبت السلوك الذي أُلغي صراحة:
 * فاتورة ترميم النواقص كانت تستهلك فائض صيدليات أخرى (عبر مناقلات عكسية أُضيفت للمركزي
 * فوراً). هنا نثبت العكس: مصدر الفاتورة الإلحاقية الوحيد = النواقص × المخزون المركزي.
 */
final class ShortageInvoiceSingleSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_another_pharmacys_surplus_never_reaches_the_shortage_invoice(): void
    {
        $overstocked = Pharmacy::create(['name' => 'صيدلية أ (فائض)']);
        $short = Pharmacy::create(['name' => 'صيدلية ب (نقص)']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ']);
        CentralInventory::create(['product_id' => $product->id, 'quantity' => 0]);

        PharmacyInventory::create(['pharmacy_id' => $overstocked->id, 'product_id' => $product->id, 'quantity' => 100]);
        PharmacyMinimumStock::create(['pharmacy_id' => $overstocked->id, 'product_id' => $product->id, 'minimum_quantity' => 20]);

        (new ParLevelReplenishmentService(new ReverseTransferRecorderService()))->replenish([$overstocked->id]);

        // الفائض سُجِّل كمقترحة — والمركزي لم يتغيّر (كان يقفز إلى 80 قبل ب.5).
        $this->assertSame(1, ReverseTransfer::count());
        $this->assertSame(0, (int) CentralInventory::where('product_id', $product->id)->value('quantity'));

        Shortage::create([
            'pharmacy_id' => $short->id, 'product_id' => $product->id, 'quantity' => 30,
            'status' => ShortageStatus::Pending, 'source' => 'standard_inventory',
        ]);

        $result = $this->app->make(ShortageFulfillmentService::class)->fulfillActiveShortages();

        // لا مخزون مركزي ⇒ لا ترميم — رغم وجود فائض 80 لدى صيدلية أخرى.
        $this->assertSame(0, $result['fulfilled_count']);
        $this->assertSame(0, $result['partial_count']);
        $this->assertSame(0, DB::table('invoice_items')->count());
        // والمقترحة العكسية لم تُستهلك ولم تُحذف (كانت تُنقص/تُحذف هنا قبل ب.5).
        $this->assertSame(80, (int) ReverseTransferItem::first()->quantity);
    }

    public function test_shortage_invoice_uses_central_stock_and_never_marks_redistribution(): void
    {
        $pharmacy = Pharmacy::create(['name' => 'صيدلية أ']);
        $short = Pharmacy::create(['name' => 'صيدلية ب']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ']);
        CentralInventory::create(['product_id' => $product->id, 'quantity' => 40]);

        // مستند عكسية قديم موجود — يجب أن يُتجاهل كلياً من الفاتورة الإلحاقية.
        $transfer = ReverseTransfer::create(['pharmacy_id' => $pharmacy->id, 'date' => now()->toDateString()]);
        ReverseTransferItem::create([
            'reverse_transfer_id' => $transfer->id, 'product_id' => $product->id, 'quantity' => 40,
            'standard_quantity_at_time' => 20, 'pharmacy_quantity_at_time' => 60,
        ]);

        Shortage::create([
            'pharmacy_id' => $short->id, 'product_id' => $product->id, 'quantity' => 10,
            'status' => ShortageStatus::Pending, 'source' => 'standard_inventory',
        ]);

        $result = $this->app->make(ShortageFulfillmentService::class)->fulfillActiveShortages([]);

        $this->assertSame(1, $result['fulfilled_count']);
        $item = DB::table('invoice_items')->where('product_id', $product->id)->first();
        $this->assertSame(10, (int) $item->supplied_qty);
        $this->assertSame(0, (int) $item->is_redistributed);
        $this->assertNull($item->redistribution_sources);
        $this->assertSame(30, (int) CentralInventory::where('product_id', $product->id)->value('quantity'));
        $this->assertSame(40, (int) ReverseTransferItem::first()->quantity);
    }
}
