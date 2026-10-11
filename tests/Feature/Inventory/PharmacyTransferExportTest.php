<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\PharmacyTransferStatus;
use App\Exports\PharmacyTransferExport;
use App\Models\Pharmacy;
use App\Models\PharmacyTransfer;
use App\Models\PharmacyTransferItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PharmacyTransferExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_maps_only_this_transfers_items_with_the_full_audit_snapshot(): void
    {
        $a = Pharmacy::create(['name' => 'صيدلية أ']);
        $b = Pharmacy::create(['name' => 'صيدلية ب']);
        $c = Pharmacy::create(['name' => 'صيدلية ج']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ']);

        $transfer = PharmacyTransfer::create(['from_pharmacy_id' => $a->id, 'to_pharmacy_id' => $b->id, 'status' => PharmacyTransferStatus::Completed]);
        PharmacyTransferItem::create(['pharmacy_transfer_id' => $transfer->id, 'product_id' => $product->id, 'quantity' => 30, 'from_pharmacy_excess_at_time' => 40, 'to_pharmacy_gap_at_time' => 35]);

        // مناقلة أخرى منفصلة — يجب ألا تظهر بهذا التصدير
        $other = PharmacyTransfer::create(['from_pharmacy_id' => $a->id, 'to_pharmacy_id' => $c->id, 'status' => PharmacyTransferStatus::Completed]);
        PharmacyTransferItem::create(['pharmacy_transfer_id' => $other->id, 'product_id' => $product->id, 'quantity' => 99, 'from_pharmacy_excess_at_time' => 10, 'to_pharmacy_gap_at_time' => 10]);

        $export = new PharmacyTransferExport($transfer->id);
        $rows = $export->collection();

        $this->assertCount(1, $rows);
        $this->assertSame(
            ['صيدلية أ', 'صيدلية ب', '111', 'شركة أ', 'بنادول', 30, 40, 35],
            $export->map($rows->first()),
        );
    }
}
