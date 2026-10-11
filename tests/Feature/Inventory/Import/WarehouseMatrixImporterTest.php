<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory\Import;

use App\Enums\ImportWarning;
use App\Models\CentralInventory;
use App\Models\ExcludedCompany;
use App\Models\Pharmacy;
use App\Models\PharmacyInventory;
use App\Models\PharmacyMapping;
use App\Models\PharmacyMinimumStock;
use App\Models\Product;
use App\Services\Inventory\Import\Exceptions\InvalidImportFileException;
use App\Services\Inventory\Import\Matrix\WarehouseMatrixImporter;
use App\Services\Inventory\ParLevelReplenishmentService;
use App\Services\Inventory\ReverseTransferRecorderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use RuntimeException;
use Tests\TestCase;

/**
 * اختبارات على مخطط قاعدة البيانات الحقيقي ومحرك الترميم الحقيقي — لا محاكاة للجداول.
 */
final class WarehouseMatrixImporterTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $tempFiles = [];

    private static bool $failPharmacyInsert = false;

    protected function tearDown(): void
    {
        self::$failPharmacyInsert = false;
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /** @param list<array<int, string|int|float|null>> $rows */
    private function xlsx(array $rows): string
    {
        $path = $this->tempFiles[] = sys_get_temp_dir() . '/matrix-' . bin2hex(random_bytes(6)) . '.xlsx';
        $writer = new XlsxWriter();
        $writer->openToFile($path);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return $path;
    }

    /** @return list<string> */
    private function header(string ...$pharmacies): array
    {
        return ['رمز المادة', 'الاسم', 'الشركة', 'مستودع الصناعة', ...$pharmacies];
    }

    private function importer(): WarehouseMatrixImporter
    {
        return $this->app->make(WarehouseMatrixImporter::class);
    }

    /** @return array{0: Pharmacy, 1: Pharmacy, 2: Product, 3: Product} */
    private function world(): array
    {
        $a = Pharmacy::create(['name' => 'صيدلية أ']);
        $b = Pharmacy::create(['name' => 'صيدلية ب']);
        $p1 = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ']);
        $p2 = Product::create(['barcode' => '222', 'name' => 'أموكسيل', 'company' => 'شركة ب']);
        CentralInventory::create(['product_id' => $p1->id, 'quantity' => 7]);
        CentralInventory::create(['product_id' => $p2->id, 'quantity' => 9]);

        return [$a, $b, $p1, $p2];
    }

    private function pharmacyStock(int $pharmacyId, int $productId): ?int
    {
        $value = DB::table('pharmacy_inventories')->where('pharmacy_id', $pharmacyId)->where('product_id', $productId)->value('quantity');

        return $value === null ? null : (int) $value;
    }

    private function central(int $productId): int
    {
        return (int) DB::table('central_inventories')->where('product_id', $productId)->value('quantity');
    }

    public function test_replaces_central_and_pharmacy_snapshots_and_leaves_other_pharmacies_alone(): void
    {
        [$a, $b, $p1, $p2] = $this->world();
        $c = Pharmacy::create(['name' => 'صيدلية ج']);          // ليست بالكشف
        $p3 = Product::create(['barcode' => '333', 'name' => 'غائبة', 'company' => 'شركة أ']);
        CentralInventory::create(['product_id' => $p3->id, 'quantity' => 5]);
        PharmacyInventory::create(['pharmacy_id' => $a->id, 'product_id' => $p1->id, 'quantity' => 99]);
        PharmacyInventory::create(['pharmacy_id' => $c->id, 'product_id' => $p1->id, 'quantity' => 5]);

        $outcome = $this->importer()->import($this->xlsx([
            $this->header('صيدلية أ', 'صيدلية ب'),
            ['111', 'بنادول', 'شركة أ', 20, 3, 4],
            ['222', 'أموكسيل', 'شركة ب', null, 1, null],
        ]));

        $this->assertTrue($outcome->applied);
        $this->assertSame([$a->id, $b->id], $outcome->affectedPharmacyIds);
        $this->assertSame(2, $outcome->productsInFile);
        $this->assertSame(3, $outcome->pharmacyRowsWritten);

        $this->assertSame(20, $this->central($p1->id));
        $this->assertSame(0, $this->central($p2->id));           // ذُكرت بفراغ ← صفر
        $this->assertSame(0, $this->central($p3->id));           // غائبة عن الكشف ← صفر (استبدال كامل)

        $this->assertSame(3, $this->pharmacyStock($a->id, $p1->id));   // 99 استُبدلت
        $this->assertSame(4, $this->pharmacyStock($b->id, $p1->id));
        $this->assertSame(1, $this->pharmacyStock($a->id, $p2->id));
        $this->assertNull($this->pharmacyStock($b->id, $p2->id));      // فراغ بلا مخزون قياسي ← لا صف
        $this->assertSame(5, $this->pharmacyStock($c->id, $p1->id));   // خارج الكشف ← لم تُلمس
    }

    public function test_a_blank_cell_becomes_an_explicit_zero_only_where_a_standard_stock_exists(): void
    {
        [$a, $b, $p1, $p2] = $this->world();
        PharmacyMinimumStock::create(['pharmacy_id' => $a->id, 'product_id' => $p1->id, 'minimum_quantity' => 50]);

        $outcome = $this->importer()->import($this->xlsx([
            $this->header('صيدلية أ', 'صيدلية ب'),
            ['111', 'بنادول', 'شركة أ', 20, null, 4],     // A فارغ ولها مخزون قياسي
            ['222', 'أموكسيل', 'شركة ب', 1, null, null],   // فراغ بلا مخزون قياسي
        ]));

        $this->assertSame(1, $outcome->zeroRowsMaterialized);
        $this->assertSame(0, $this->pharmacyStock($a->id, $p1->id));
        $this->assertNull($this->pharmacyStock($a->id, $p2->id));
    }

    public function test_the_real_engine_restocks_a_pair_the_matrix_left_blank(): void
    {
        [$a, , $p1] = $this->world();
        PharmacyMinimumStock::create(['pharmacy_id' => $a->id, 'product_id' => $p1->id, 'minimum_quantity' => 50]);

        $this->importer()->import($this->xlsx([
            $this->header('صيدلية أ'),
            ['111', 'بنادول', 'شركة أ', 20, null],
        ]));

        $result = (new ParLevelReplenishmentService(new ReverseTransferRecorderService()))->replenish([$a->id]);

        // فراغ = صفر ← فجوة 50 ← المركزي عنده 20 فقط ← تُشحن 20 ويبقى عجز 30.
        $this->assertSame(1, $result->invoicesCreated);
        $item = DB::table('invoice_items')->first();
        $this->assertSame([50, 20, 30], [(int) $item->requested_qty, (int) $item->supplied_qty, (int) $item->shortage_qty]);
        $this->assertSame(0, $this->central($p1->id));
    }

    public function test_negatives_and_fractions_reach_the_database_as_clean_integers_and_are_reported(): void
    {
        [$a, $b, $p1] = $this->world();

        $outcome = $this->importer()->import($this->xlsx([
            $this->header('صيدلية أ', 'صيدلية ب'),
            ['111', 'بنادول', 'شركة أ', 2.67, -3, 1.5],
        ]));

        $this->assertSame(2, $this->central($p1->id));
        $this->assertNull($this->pharmacyStock($a->id, $p1->id));   // -3 ← 0 ← لا صف (لا مخزون قياسي)
        $this->assertSame(1, $this->pharmacyStock($b->id, $p1->id));
        $this->assertSame(1, $outcome->diagnostics->count(ImportWarning::NegativeQuantity));
        $this->assertSame(2, $outcome->diagnostics->count(ImportWarning::FractionalQuantity));
    }

    public function test_excluded_company_updates_central_but_ignores_pharmacy_columns(): void
    {
        [$a, , , $p2] = $this->world();
        ExcludedCompany::create(['company' => 'شركة ب']);
        PharmacyMinimumStock::create(['pharmacy_id' => $a->id, 'product_id' => $p2->id, 'minimum_quantity' => 30]);

        $outcome = $this->importer()->import($this->xlsx([
            $this->header('صيدلية أ'),
            ['222', 'أموكسيل', 'شركة ب', 6, 8],
        ]));

        $this->assertSame(6, $this->central($p2->id));                 // المركزي يُحدَّث دائماً
        $this->assertNull($this->pharmacyStock($a->id, $p2->id));       // الصيدلية كأنها غير موجودة
        $this->assertSame(0, $outcome->zeroRowsMaterialized);           // ولا صفر مُثبَّت لها
    }

    public function test_duplicate_barcode_rows_are_summed_and_reported(): void
    {
        [$a, , $p1] = $this->world();

        $outcome = $this->importer()->import($this->xlsx([
            $this->header('صيدلية أ'),
            ['111', 'بنادول', 'شركة أ', 2, 1],
            ['111', 'بنادول', 'شركة أ', 3, 1],
        ]));

        $this->assertSame(5, $this->central($p1->id));
        $this->assertSame(2, $this->pharmacyStock($a->id, $p1->id));
        $this->assertSame(1, $outcome->diagnostics->count(ImportWarning::DuplicateBarcode));
    }

    public function test_unknown_products_with_stock_go_to_pending_and_dead_ones_are_only_counted(): void
    {
        $this->world();
        $before = Product::count();

        $outcome = $this->importer()->import($this->xlsx([
            $this->header('صيدلية أ'),
            ['999', 'مادة جديدة', 'شركة ج', 4, 2],
            ['888', 'مادة ميتة', 'شركة ج', null, null],
        ]));

        $this->assertSame($before, Product::count());                  // لا يُنشأ شيء بلا تأكيدك
        $this->assertCount(1, $outcome->pendingProducts);
        $this->assertSame(
            ['barcode' => '999', 'material_name' => 'مادة جديدة', 'company' => 'شركة ج', 'quantity' => 4, 'pack_size' => null, 'price' => null],
            $outcome->pendingProducts[0],
        );
        $this->assertSame(1, $outcome->unknownWithoutStock);
    }

    public function test_an_unknown_pharmacy_header_writes_nothing_and_asks_for_mapping(): void
    {
        [$a, , $p1] = $this->world();
        PharmacyInventory::create(['pharmacy_id' => $a->id, 'product_id' => $p1->id, 'quantity' => 99]);

        $outcome = $this->importer()->import($this->xlsx([
            $this->header('صيدلية أ', 'صيدلية مجهولة'),
            ['111', 'بنادول', 'شركة أ', 20, 3, 4],
        ]));

        $this->assertTrue($outcome->needsMapping());
        $this->assertSame(['صيدلية مجهولة'], $outcome->headersNeedingMapping);
        $this->assertSame(7, $this->central($p1->id));                       // لم يُلمس شيء
        $this->assertSame(99, $this->pharmacyStock($a->id, $p1->id));
    }

    public function test_a_saved_mapping_resolves_the_header_on_the_next_run(): void
    {
        [$a, , $p1] = $this->world();
        PharmacyMapping::create(['original_name' => 'صيدلية مجهولة', 'pharmacy_id' => $a->id]);

        $outcome = $this->importer()->import($this->xlsx([
            $this->header('صيدلية مجهولة'),
            ['111', 'بنادول', 'شركة أ', 20, 3],
        ]));

        $this->assertTrue($outcome->applied);
        $this->assertSame(3, $this->pharmacyStock($a->id, $p1->id));
    }

    public function test_two_columns_for_the_same_pharmacy_are_rejected(): void
    {
        [$a] = $this->world();
        PharmacyMapping::create(['original_name' => 'اسم بديل', 'pharmacy_id' => $a->id]);

        $this->expectException(InvalidImportFileException::class);
        $this->expectExceptionMessage('صيدلية أ');

        $this->importer()->import($this->xlsx([
            $this->header('صيدلية أ', 'اسم بديل'),
            ['111', 'بنادول', 'شركة أ', 20, 3, 4],
        ]));
    }

    public function test_name_and_company_refresh_without_overwriting_them_with_placeholders(): void
    {
        [, , $p1, $p2] = $this->world();

        $outcome = $this->importer()->import($this->xlsx([
            $this->header('صيدلية أ'),
            ['111', 'بنادول اكسترا', 'شركة جديدة', 1, 1],
            ['222', null, null, 1, 1],                  // اسم فارغ وشركة فارغة = لا معلومة
        ]));

        $this->assertSame(1, $outcome->productsRefreshed);
        $this->assertSame(['بنادول اكسترا', 'شركة جديدة'], [Product::find($p1->id)->name, Product::find($p1->id)->company]);
        $this->assertSame(['أموكسيل', 'شركة ب'], [Product::find($p2->id)->name, Product::find($p2->id)->company]);
    }

    public function test_the_write_is_atomic_a_late_failure_leaves_the_warehouse_untouched(): void
    {
        [$a, , $p1] = $this->world();
        PharmacyInventory::create(['pharmacy_id' => $a->id, 'product_id' => $p1->id, 'quantity' => 99]);

        // يفشل عند إدراج لقطات الصيدليات — بعد أن يكون المركزي قد صُفِّر وأُعيدت كتابته.
        DB::getEventDispatcher()->listen(\Illuminate\Database\Events\QueryExecuted::class, function ($event): void {
            // مطابقة مستقلة عن لهجة SQL: SQLite يقتبس بـ "" وMySQL بـ `` — بدونها لا يُحقَن الفشل على MySQL أصلاً.
            if (self::$failPharmacyInsert && preg_match('/insert into ["`]?pharmacy_inventories["`]?/i', $event->sql) === 1) {
                throw new RuntimeException('انقطاع اتصال مُحاكى');
            }
        });
        self::$failPharmacyInsert = true;

        try {
            $this->importer()->import($this->xlsx([
                $this->header('صيدلية أ'),
                ['111', 'بنادول', 'شركة أ', 20, 3],
            ]));
            $this->fail('كان يجب أن يفشل الاستيراد');
        } catch (RuntimeException $e) {
            $this->assertSame('انقطاع اتصال مُحاكى', $e->getMessage());
        } finally {
            self::$failPharmacyInsert = false;
        }

        $this->assertSame(7, $this->central($p1->id));                       // المركزي كما كان — لا صفر ولا 20
        $this->assertSame(99, $this->pharmacyStock($a->id, $p1->id));
    }
}
