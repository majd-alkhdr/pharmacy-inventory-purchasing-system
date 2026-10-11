<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory\Import;

use App\Enums\ShortageSource;
use App\Models\CentralInventory;
use App\Models\Invoice;
use App\Models\Pharmacy;
use App\Models\PharmacyInventory;
use App\Models\PharmacyMinimumStock;
use App\Models\Product;
use App\Services\Inventory\ParLevelReplenishmentService;
use App\Services\Inventory\ReverseTransferRecorderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * حماية إصلاح Invoice::$fillable — PreparationExport يفصل كميات "من المبيعات" و"من المخزون القياسي"
 * و"قديم" بالاعتماد على invoices.source، وكان محفوظاً NULL دائماً فتسقط كل فاتورة بخانة "قديم".
 */
final class InvoiceSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_standard_inventory_invoice_records_its_source(): void
    {
        $a = Pharmacy::create(['name' => 'صيدلية أ']);
        $p = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'ش', 'pack_size' => 0]);
        CentralInventory::create(['product_id' => $p->id, 'quantity' => 100]);
        PharmacyInventory::create(['pharmacy_id' => $a->id, 'product_id' => $p->id, 'quantity' => 10]);
        PharmacyMinimumStock::create(['pharmacy_id' => $a->id, 'product_id' => $p->id, 'minimum_quantity' => 50]);

        (new ParLevelReplenishmentService(new ReverseTransferRecorderService()))->replenish([$a->id]);

        $this->assertSame('standard_inventory', DB::table('invoices')->value('source'));
        $this->assertSame(ShortageSource::StandardInventory, Invoice::first()->source);
    }

    public function test_legacy_invoices_without_a_source_still_load(): void
    {
        $a = Pharmacy::create(['name' => 'صيدلية أ']);
        DB::table('invoices')->insert(['pharmacy_id' => $a->id, 'date' => '2026-08-01', 'status' => 'processed', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertNull(Invoice::first()->source);
    }
}
