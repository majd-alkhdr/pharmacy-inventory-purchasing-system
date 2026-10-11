<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\InvoiceGenerationScope;
use App\Enums\ShortageStatus;
use App\Models\CentralInventory;
use App\Models\Invoice;
use App\Models\Pharmacy;
use App\Models\Product;
use App\Models\Shortage;
use App\Services\Inventory\InvoiceScopeGate;
use App\Services\Inventory\RestockingResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 🎯 (ب.4) "أساسية فقط / نواقص فقط / الاثنين" — يُطبَّق بمكان واحد. */
final class InvoiceScopeGateTest extends TestCase
{
    use RefreshDatabase;

    private function seedShortage(): void
    {
        $pharmacy = Pharmacy::create(['name' => 'صيدلية']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'ش']);
        CentralInventory::create(['product_id' => $product->id, 'quantity' => 10]);
        Shortage::create(['pharmacy_id' => $pharmacy->id, 'product_id' => $product->id, 'quantity' => 4, 'status' => ShortageStatus::Pending, 'source' => 'sales']);
    }

    public function test_shortages_only_skips_basic_generation_and_still_fulfils_shortages(): void
    {
        $this->seedShortage();
        $gate = $this->app->make(InvoiceScopeGate::class);
        $basicRan = false;

        $basic = $gate->basic(InvoiceGenerationScope::Shortages, function () use (&$basicRan) {
            $basicRan = true;

            return RestockingResult::empty();
        });
        $shortages = $gate->shortages(InvoiceGenerationScope::Shortages, []);

        $this->assertFalse($basicRan);
        $this->assertSame(0, $basic->invoicesCreated);
        $this->assertSame(1, $shortages['fulfilled_count']);
        $this->assertSame(1, Invoice::where('status', 'supplementary_invoice')->count());
    }

    public function test_basic_only_never_touches_the_shortage_table(): void
    {
        $this->seedShortage();

        $result = $this->app->make(InvoiceScopeGate::class)->shortages(InvoiceGenerationScope::Basic, []);

        $this->assertSame(0, $result['fulfilled_count']);
        $this->assertSame(0, Invoice::count());
        $this->assertSame(ShortageStatus::Pending, Shortage::first()->status);
    }

    public function test_request_translation_of_the_two_checkboxes(): void
    {
        $make = fn (array $types) => tap(new \App\Http\Requests\Inventory\UploadSalesAndInventoryRequest(), function ($r) use ($types) {
            $r->setValidator(validator(['invoice_types' => $types], ['invoice_types' => 'array']));
        });

        $this->assertSame(InvoiceGenerationScope::Both, $make(['basic', 'shortages'])->invoiceScope());
        $this->assertSame(InvoiceGenerationScope::Basic, $make(['basic'])->invoiceScope());
        $this->assertSame(InvoiceGenerationScope::Shortages, $make(['shortages'])->invoiceScope());
    }
}
