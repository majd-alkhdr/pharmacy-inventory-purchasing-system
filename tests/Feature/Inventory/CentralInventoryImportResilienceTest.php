<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Imports\CentralInventoryImport;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * 🎯 يحمي إصلاح الإنتاج: ملف حقيقي بخانة صيغة Excel خارجية غير محسوبة
 * ("=VLOOKUP(B451,[1]Sheet1!$C:$D,2,FALSE)") كان يُسقِط الاستيراد **كله**
 * بخطأ قاعدة بيانات خام (SQLSTATE[22007])، فتُفقَد كل الصفوف السليمة الأخرى
 * معه. الآن: الصف المعطوب يُسجَّل ويُتخطى، والبقية تُطبَّق بنجاح.
 */
final class CentralInventoryImportResilienceTest extends TestCase
{
    use RefreshDatabase;

    private function rows(array $rows): Collection
    {
        return collect($rows);
    }

    public function test_a_formula_string_in_the_price_column_is_safely_ignored(): void
    {
        $p = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ']);

        $import = new CentralInventoryImport();
        $import->collection($this->rows([
            ['111', 'شركة أ', 'بنادول', 20, null, '=VLOOKUP(B451,[1]Sheet1!$C:$D,2,FALSE)'],
        ]));

        $this->assertSame([], $import->prices);           // لم يُسجَّل سعر من صيغة خام
        $this->assertSame(20, $import->matched[$p->id]);   // الكمية طُبِّقت رغم ذلك
    }

    public function test_a_formula_string_in_the_pack_size_column_is_safely_ignored(): void
    {
        $p = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ', 'pack_size' => 10]);

        $import = new CentralInventoryImport();
        $import->collection($this->rows([
            ['111', 'شركة أ', 'بنادول', 20, '=VLOOKUP(B451,[1]Sheet1!$C:$D,2,FALSE)', null],
        ]));

        $p->refresh();
        $this->assertSame(10, $p->pack_size);   // لم يتغيّر — لم تُكتَب الصيغة الخام
    }

    public function test_a_formula_string_in_the_name_column_is_reported_and_does_not_overwrite_the_real_name(): void
    {
        $p = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ']);

        $import = new CentralInventoryImport();
        $import->collection($this->rows([
            ['111', 'شركة أ', '=VLOOKUP(B451,[1]Sheet1!$C:$D,2,FALSE)', 20, null, null],
        ]));

        $p->refresh();
        $this->assertSame('بنادول', $p->name);   // لم يُستبدَل بنص الصيغة
        $this->assertArrayHasKey('111', $import->failedUpdates);
        $this->assertSame(20, $import->matched[$p->id]);   // الكمية طُبِّقت رغم ذلك
    }

    public function test_one_bad_row_never_blocks_the_other_good_rows_in_the_same_batch(): void
    {
        $good1 = Product::create(['barcode' => '111', 'name' => 'قديم1', 'company' => 'ش']);
        $bad = Product::create(['barcode' => '222', 'name' => 'قديم2', 'company' => 'ش']);
        $good2 = Product::create(['barcode' => '333', 'name' => 'قديم3', 'company' => 'ش']);

        $import = new CentralInventoryImport();
        $import->collection($this->rows([
            ['111', 'ش', 'اسم جديد 1', 5, null, null],
            ['222', 'ش', '=VLOOKUP(B451,[1]Sheet1!$C:$D,2,FALSE)', 7, null, null],
            ['333', 'ش', 'اسم جديد 3', 9, null, null],
        ]));

        $good1->refresh();
        $bad->refresh();
        $good2->refresh();
        $this->assertSame('اسم جديد 1', $good1->name);
        $this->assertSame('قديم2', $bad->name);       // لم يُلمَس (السطر المعطوب)
        $this->assertSame('اسم جديد 3', $good2->name);
        $this->assertArrayHasKey('222', $import->failedUpdates);
        $this->assertSame([5, 7, 9], [$import->matched[$good1->id], $import->matched[$bad->id], $import->matched[$good2->id]]);
    }

    public function test_a_normal_file_with_no_formulas_behaves_exactly_as_before(): void
    {
        $p = Product::create(['barcode' => '111', 'name' => 'قديم', 'company' => 'ش قديمة']);

        $import = new CentralInventoryImport();
        $import->collection($this->rows([
            ['111', 'ش جديدة', 'اسم جديد', 20, 10, 15.5],
        ]));

        $p->refresh();
        $this->assertSame(['اسم جديد', 'ش جديدة', 10], [$p->name, $p->company, $p->pack_size]);
        $this->assertSame(15.5, $import->prices[$p->id]);
        $this->assertSame([], $import->failedUpdates);
    }
}
