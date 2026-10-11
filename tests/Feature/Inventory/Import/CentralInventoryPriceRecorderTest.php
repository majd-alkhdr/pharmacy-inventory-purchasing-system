<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory\Import;

use App\Models\CentralInventoryPrice;
use App\Models\Product;
use App\Services\Inventory\CentralInventoryPriceRecorderService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * حماية إصلاح N+1: recordChanges() كانت تستعلم عن "آخر سعر" **لكل منتج على حدة
 * بداخل الحلقة** — بملف جرد مركزي كامل (~20 ألف مادة) يعني عشرات آلاف الاستعلامات
 * لمجرد تسجيل تاريخ الأسعار. هذا يُثبت العدد الثابت (لا يتناسب مع عدد المواد)
 * والسلوك الصحيح نفسه معاً على قاعدة البيانات الحقيقية.
 */
final class CentralInventoryPriceRecorderTest extends TestCase
{
    use RefreshDatabase;

    private function recorder(): CentralInventoryPriceRecorderService
    {
        return $this->app->make(CentralInventoryPriceRecorderService::class);
    }

    private function queryCountFor(callable $callback): int
    {
        $count = 0;
        $listener = function () use (&$count): void { $count++; };
        DB::listen($listener);
        $callback();
        // لا واجهة لإزالة مستمع واحد بهذا الإصدار المصغَّر — كافٍ لأن كل اختبار بجلسة عمليات منفصلة.
        return $count;
    }

    public function test_records_new_prices_and_changed_prices_only(): void
    {
        $p1 = Product::create(['barcode' => '1', 'name' => 'أ', 'company' => 'ش']);
        $p2 = Product::create(['barcode' => '2', 'name' => 'ب', 'company' => 'ش']);
        CentralInventoryPrice::create(['product_id' => $p2->id, 'price' => 10.0, 'effective_from' => '2026-08-01']);

        $recorded = $this->recorder()->recordChanges([$p1->id => 5.0, $p2->id => 10.0]);

        $this->assertSame(1, $recorded);   // p1 جديد؛ p2 بلا تغيير فعلي فلا يُسجَّل
        $this->assertSame(5.0, CentralInventoryPrice::latestFor($p1->id));
        $this->assertSame(1, CentralInventoryPrice::where('product_id', $p2->id)->count());
    }

    public function test_a_real_price_change_adds_a_new_history_row_not_an_update(): void
    {
        $p = Product::create(['barcode' => '1', 'name' => 'أ', 'company' => 'ش']);
        CentralInventoryPrice::create(['product_id' => $p->id, 'price' => 10.0, 'effective_from' => '2026-08-01']);

        $this->recorder()->recordChanges([$p->id => 12.5]);

        $this->assertSame(2, CentralInventoryPrice::where('product_id', $p->id)->count());
        $this->assertSame(12.5, CentralInventoryPrice::latestFor($p->id));
    }

    public function test_query_count_does_not_grow_with_the_number_of_products(): void
    {
        $ids = [];
        for ($i = 1; $i <= 40; $i++) {
            $ids[] = Product::create(['barcode' => (string) $i, 'name' => "م{$i}", 'company' => 'ش'])->id;
        }
        $prices = array_combine($ids, array_fill(0, 40, 9.99));

        $count = $this->queryCountFor(fn () => $this->recorder()->recordChanges($prices));

        // ثابت بصرف النظر عن 40 مادة: استعلام واحد لآخر الأسعار (latestForMany) + دفعة إدراج
        // واحدة (٤٠ صفاً تحت سقف الدُفعة 500) — لا علاقة له بعدد المواد المُمرَّرة.
        $this->assertLessThanOrEqual(3, $count);
    }
}
