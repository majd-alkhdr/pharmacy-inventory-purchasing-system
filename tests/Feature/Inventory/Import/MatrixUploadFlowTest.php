<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory\Import;

use App\Models\CentralInventory;
use App\Models\ImportSession;
use App\Models\Pharmacy;
use App\Models\PendingInventoryItem;
use App\Models\PharmacyMinimumStock;
use App\Models\Product;
use App\Services\Inventory\Import\Matrix\WarehouseMatrixImporter;
use App\Services\Inventory\InventoryUploadService;
use App\Services\Inventory\PendingProductService;
use App\Services\Inventory\PharmacyMappingRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Tests\TestCase;

/**
 * المسار الكامل عبر InventoryUploadService الحقيقي: الاستيراد ← الأصناف المعلَّقة ← الترميم ← الرسالة ← التنظيف.
 */
final class MatrixUploadFlowTest extends TestCase
{
    use RefreshDatabase;

    private const SESSION = 'session_matrix_test';

    /** @param list<array<int, string|int|float|null>> $rows @return string مسار نسبي كما يخزّنه ->store('imports') */
    private function storeMatrix(array $rows): string
    {
        $relative = 'imports/' . bin2hex(random_bytes(6)) . '.xlsx';
        $writer = new XlsxWriter();
        $writer->openToFile(Storage::path($relative));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return $relative;
    }

    private function createImportSession(string $mode = 'standard_inventory', ?string $matrix = null): ImportSession
    {
        return ImportSession::create([
            'session_id' => self::SESSION,
            'type' => 'sales_and_inventory',
            'status' => 'processing',
            'restocking_mode' => $mode,
            'warehouse_matrix_file_path' => $matrix,
        ]);
    }

    private function run_(string $matrix): ImportSession
    {
        $this->app->make(InventoryUploadService::class)->processInventoryUpload(self::SESSION, null, null, [], $matrix);

        return ImportSession::where('session_id', self::SESSION)->first();
    }

    /** @return array{0: Pharmacy, 1: Product} */
    private function world(): array
    {
        $a = Pharmacy::create(['name' => 'صيدلية أ']);
        $p1 = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ']);
        CentralInventory::create(['product_id' => $p1->id, 'quantity' => 7]);
        PharmacyMinimumStock::create(['pharmacy_id' => $a->id, 'product_id' => $p1->id, 'minimum_quantity' => 50]);

        return [$a, $p1];
    }

    private const HEADER = ['رمز المادة', 'الاسم', 'الشركة', 'مستودع الصناعة', 'صيدلية أ'];

    public function test_standard_mode_completes_restocks_reports_and_deletes_the_file(): void
    {
        [$a, $p1] = $this->world();
        $path = $this->storeMatrix([self::HEADER, ['111', 'بنادول', 'شركة أ', 20, null]]);
        $this->createImportSession(matrix: $path);

        $session = $this->run_($path);

        $this->assertSame('completed', $session->status);
        $this->assertStringContainsString('استُورد كشف المستودعات', (string) $session->message);
        $this->assertSame(1, DB::table('invoices')->count());
        $item = DB::table('invoice_items')->first();
        $this->assertSame([50, 20], [(int) $item->requested_qty, (int) $item->supplied_qty]);
        $this->assertIsArray($session->import_diagnostics);
        $this->assertFalse(Storage::exists($path));            // نُظِّف بعد الاكتمال
        $this->assertSame(0, (int) DB::table('central_inventories')->where('product_id', $p1->id)->value('quantity'));
    }

    public function test_an_unknown_pharmacy_pauses_keeps_the_file_and_resumes_after_mapping(): void
    {
        [$a, $p1] = $this->world();
        $path = $this->storeMatrix([[...self::HEADER, 'صيدلية مجهولة'], ['111', 'بنادول', 'شركة أ', 20, null, 5]]);
        $this->createImportSession(matrix: $path);

        $paused = $this->run_($path);

        $this->assertSame('needs_mapping', $paused->status);
        $this->assertTrue(Storage::exists($path));                                  // يبقى لإعادة التشغيل
        $this->assertSame(0, DB::table('invoices')->count());
        $this->assertSame(7, (int) DB::table('central_inventories')->where('product_id', $p1->id)->value('quantity'));
        $this->assertSame(['صيدلية مجهولة'], $this->app->make(WarehouseMatrixImporter::class)->pharmacyHeadersNeedingMapping(Storage::path($path)));

        // قرارك على الشاشة: "صيدلية مجهولة" = صيدلية جديدة
        $this->app->make(PharmacyMappingRecorder::class)->record([['excel_name' => 'صيدلية مجهولة', 'mapped_id' => 'NEW']]);
        $this->assertSame([], $this->app->make(WarehouseMatrixImporter::class)->pharmacyHeadersNeedingMapping(Storage::path($path)));

        $resumed = $this->run_($path);

        $this->assertSame('completed', $resumed->status);
        $this->assertFalse(Storage::exists($path));
        $this->assertSame(1, DB::table('invoices')->count());
    }

    public function test_new_products_wait_for_confirmation_then_are_created_by_the_existing_flow(): void
    {
        [$a, $p1] = $this->world();
        $path = $this->storeMatrix([
            self::HEADER,
            ['111', 'بنادول', 'شركة أ', 20, 10],
            ['999', 'مادة جديدة', 'شركة ج', 4, 2],
        ]);
        $this->createImportSession(matrix: $path);

        $waiting = $this->run_($path);

        $this->assertSame('needs_pending_products', $waiting->status);
        $this->assertStringContainsString('1 مادة جديدة', (string) $waiting->message);
        $this->assertSame(1, PendingInventoryItem::where('session_id', self::SESSION)->count());
        $this->assertSame([$a->id], $waiting->pharmacy_inventory_pharmacy_ids);      // يُستكمل الترميم للصيدليات المتأثرة
        $this->assertNull(Product::where('barcode', '999')->first());                  // لا إنشاء بلا تأكيد
        $this->assertFalse(Storage::exists($path));

        $result = $this->app->make(PendingProductService::class)->createPendingProducts(self::SESSION);

        $this->assertFalse($result['needs_mapping']);
        $new = Product::where('barcode', '999')->first();
        $this->assertSame(['مادة جديدة', 'شركة ج'], [$new->name, $new->company]);
        $this->assertSame(4, (int) DB::table('central_inventories')->where('product_id', $new->id)->value('quantity'));
        // ورُمِّمت الصيدلية أ (فجوتها 40: 50 - 10) بمخزون مركزي كافٍ (20)
        $this->assertSame(1, DB::table('invoices')->count());
    }
}
