<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Exports\ReverseTransferExport;
use App\Models\Pharmacy;
use App\Models\Product;
use App\Models\ReverseTransfer;
use App\Models\ReverseTransferItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🎯 الطلب رقم 3: مستند مستقل لكل مناقلة عكسية (لا سجل مسطَّح فقط). هذا يغطي
 * الجزء القابل للاختبار بلا محرك Blade/Excel كامل بهذا الحرّاس: استعلام البيانات
 * (نفس ما يستهلكه showReverseTransfer()) وتحويل الصفوف للتصدير (ReverseTransferExport).
 */
final class ReverseTransferDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_transfer_loads_with_its_pharmacy_and_items_and_their_products(): void
    {
        $pharmacy = Pharmacy::create(['name' => 'صيدلية أ']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ']);
        $transfer = ReverseTransfer::create(['pharmacy_id' => $pharmacy->id, 'date' => '2026-09-01']);
        ReverseTransferItem::create([
            'reverse_transfer_id' => $transfer->id, 'product_id' => $product->id, 'quantity' => 30,
            'standard_quantity_at_time' => 20, 'pharmacy_quantity_at_time' => 50,
        ]);

        // نفس الاستعلام المستخدَم فعلياً بـ InventoryController::showReverseTransfer()
        $loaded = ReverseTransfer::with(['pharmacy', 'items.product'])->findOrFail($transfer->id);

        $this->assertSame('صيدلية أ', $loaded->pharmacy->name);
        $this->assertCount(1, $loaded->items);
        $this->assertSame('بنادول', $loaded->items->first()->product->name);
    }

    public function test_export_maps_only_this_transfers_items_with_the_full_audit_snapshot(): void
    {
        $pharmacy = Pharmacy::create(['name' => 'صيدلية أ']);
        $otherPharmacy = Pharmacy::create(['name' => 'صيدلية ب']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ']);

        $transfer = ReverseTransfer::create(['pharmacy_id' => $pharmacy->id, 'date' => '2026-09-01']);
        ReverseTransferItem::create([
            'reverse_transfer_id' => $transfer->id, 'product_id' => $product->id, 'quantity' => 30,
            'standard_quantity_at_time' => 20, 'pharmacy_quantity_at_time' => 50,
        ]);

        // مناقلة أخرى منفصلة تماماً — يجب ألا تظهر بتصدير المناقلة الأولى
        $otherTransfer = ReverseTransfer::create(['pharmacy_id' => $otherPharmacy->id, 'date' => '2026-09-02']);
        ReverseTransferItem::create([
            'reverse_transfer_id' => $otherTransfer->id, 'product_id' => $product->id, 'quantity' => 99,
            'standard_quantity_at_time' => 10, 'pharmacy_quantity_at_time' => 40,
        ]);

        $export = new ReverseTransferExport($transfer->id);
        $rows = $export->collection();

        $this->assertCount(1, $rows);
        $this->assertSame(
            ['صيدلية أ', '111', 'شركة أ', 'بنادول', 30, 20, 50],
            $export->map($rows->first()),
        );
    }
}
