<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory\Import;

use App\Models\CentralInventoryPrice;
use App\Models\Product;
use App\Services\Inventory\Import\Exceptions\InvalidImportFileException;
use App\Services\Inventory\Import\MaterialMaster\MaterialMasterImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Tests\TestCase;

final class MaterialMasterImporterTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = ['رمز المادة', 'اسم المادة', 'الشركة', 'السعر', 'حجم العبوة', 'الحد الأدنى للفرق'];

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /** @param list<array<int, string|int|float|null>> $rows */
    private function xlsx(array $rows): string
    {
        $path = $this->tempFiles[] = sys_get_temp_dir() . '/material-master-' . bin2hex(random_bytes(6)) . '.xlsx';
        $writer = new XlsxWriter();
        $writer->openToFile($path);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return $path;
    }

    private function importer(): MaterialMasterImporter
    {
        return $this->app->make(MaterialMasterImporter::class);
    }

    public function test_updates_name_company_pack_size_and_gap_for_an_existing_product(): void
    {
        $p = Product::create(['barcode' => '111', 'name' => 'قديم', 'company' => 'قديمة', 'pack_size' => 1]);

        $outcome = $this->importer()->import($this->xlsx([self::HEADER, ['111', 'بنادول', 'شركة أ', null, 10, 5]]));

        $p->refresh();
        $this->assertSame(['بنادول', 'شركة أ', 10, 5], [$p->name, $p->company, $p->pack_size, $p->min_reorder_gap]);
        $this->assertSame(1, $outcome->productsUpdated);
    }

    public function test_blank_columns_leave_the_existing_values_untouched(): void
    {
        $p = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ', 'pack_size' => 10, 'min_reorder_gap' => 5]);

        $outcome = $this->importer()->import($this->xlsx([self::HEADER, ['111', null, null, null, null, null]]));

        $p->refresh();
        $this->assertSame(['بنادول', 'شركة أ', 10, 5], [$p->name, $p->company, $p->pack_size, $p->min_reorder_gap]);
        $this->assertSame(0, $outcome->productsUpdated);   // لا شيء تغيّر فعلياً ← لا كتابة
    }

    public function test_a_price_change_is_recorded_in_price_history_not_on_the_product_row(): void
    {
        $p = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ']);

        $outcome = $this->importer()->import($this->xlsx([self::HEADER, ['111', null, null, 12.5, null, null]]));

        $this->assertSame(1, $outcome->pricesChanged);
        $this->assertSame(12.5, CentralInventoryPrice::latestFor($p->id));
        $this->assertSame(0, $outcome->productsUpdated);   // السعر لا يُخزَّن على صف المنتج نفسه
    }

    public function test_an_unmatched_barcode_is_reported_but_creates_nothing(): void
    {
        $before = Product::count();

        $outcome = $this->importer()->import($this->xlsx([self::HEADER, ['999', 'مادة جديدة', 'شركة ج', 5, 1, 1]]));

        $this->assertSame($before, Product::count());
        $this->assertSame(['999'], $outcome->unmatchedBarcodes);
    }

    public function test_duplicate_barcode_rows_merge_column_by_column_a_blank_never_erases_an_earlier_real_value(): void
    {
        $p = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ', 'pack_size' => 1]);

        // الصف الأول يذكر حجم العبوة فقط، الثاني يذكر السعر فقط — فراغ الثاني بخانة
        // حجم العبوة يجب ألا يُلغي القيمة التي ذكرها الصف الأول لنفس المادة.
        $outcome = $this->importer()->import($this->xlsx([
            self::HEADER,
            ['111', null, null, null, 20, null],
            ['111', null, null, 9.99, null, null],
        ]));

        $p->refresh();
        $this->assertSame(20, $p->pack_size);
        $this->assertSame(9.99, CentralInventoryPrice::latestFor($p->id));
        $this->assertSame(1, $outcome->productsUpdated);   // مادة واحدة مُحدَّثة، لا اثنتان
    }

    public function test_a_negative_price_is_rejected_and_the_old_price_stands(): void
    {
        $p = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ']);
        CentralInventoryPrice::create(['product_id' => $p->id, 'price' => 10.0, 'effective_from' => '2026-08-01']);

        $outcome = $this->importer()->import($this->xlsx([self::HEADER, ['111', null, null, -5, null, null]]));

        $this->assertSame(0, $outcome->pricesChanged);
        $this->assertSame(10.0, CentralInventoryPrice::latestFor($p->id));
    }

    public function test_bad_headers_are_rejected_before_touching_the_database(): void
    {
        $p = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ']);

        try {
            $this->importer()->import($this->xlsx([
                ['اسم المادة', 'رمز المادة', 'الشركة', 'السعر', 'حجم العبوة', 'الحد الأدنى للفرق'],
                ['111', 'اسم آخر', 'شركة ب', 1, 1, 1],
            ]));
            $this->fail('كان يجب رمي InvalidImportFileException');
        } catch (InvalidImportFileException) {
        }

        $p->refresh();
        $this->assertSame('بنادول', $p->name);
    }
}
