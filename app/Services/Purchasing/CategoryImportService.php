<?php

namespace App\Services\Purchasing;

use App\Models\Category;
use App\Models\PendingCategoryLink;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CategoryImportService
{
    /**
     * 🎯 ملف "قائمة التصنيفات الرئيسية" — المصدر الرسمي الوحيد الذي
     * يُنشئ تصنيفات جديدة. أي اسم مكرر بالملف نفسه أو موجود أصلاً
     * يُتجاهَل بصمت (insertOrIgnore) دون خطأ.
     *
     * @param Collection<int,string> $names
     */
    public function importMasterList(Collection $names): int
    {
        $uniqueNames = $names->unique()->values();

        if ($uniqueNames->isEmpty()) {
            return 0;
        }

        $now = now();
        $rows = $uniqueNames->map(fn ($name) => ['name' => $name, 'created_at' => $now, 'updated_at' => $now])->all();

        $before = Category::count();
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('categories')->insertOrIgnore($chunk);
        }

        return Category::count() - $before;
    }

    /**
     * 🎯 ملف "ربط المنتجات بالتصنيفات" — يربط فقط بتصنيفات موجودة أصلاً.
     * - باركود غير معروف بالنظام → "غير مطابَق" (مشكلة بيانات حقيقية، لا حل آلي).
     * - باركود معروف لكن اسم تصنيف غير موجود بقائمة categories → يُتجاهَل
     *   الربط، ويُسجَّل بجدول pending_category_links للمطابقة اليدوية.
     *
     * @param Collection<int, array{barcode:string, category_name:string}> $rows
     * @return array{linked:int, unmatched_products: Collection, pending_links: int}
     */
    public function importLinks(string $sessionId, Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return ['linked' => 0, 'unmatched_products' => collect(), 'pending_links' => 0];
        }

        $barcodes = $rows->pluck('barcode')->unique()->values();
        $productIdsByBarcode = Product::whereIn('barcode', $barcodes)->pluck('id', 'barcode');

        $categoryNames = $rows->pluck('category_name')->unique()->values();
        $categoryIdsByName = Category::whereIn('name', $categoryNames)->pluck('id', 'name');

        $now = now();
        $linkRows = [];
        $pendingRows = [];
        $unmatchedProducts = collect();

        foreach ($rows as $row) {
            $productId = $productIdsByBarcode[$row['barcode']] ?? null;

            if (!$productId) {
                $unmatchedProducts->push("{$row['barcode']} — تصنيف مطلوب: {$row['category_name']}");
                continue;
            }

            $categoryId = $categoryIdsByName[$row['category_name']] ?? null;

            if (!$categoryId) {
                $pendingRows[] = [
                    'session_id'                => $sessionId,
                    'product_id'                  => $productId,
                    'requested_category_name'   => $row['category_name'],
                    'created_at'                  => $now,
                    'updated_at'                  => $now,
                ];
                continue;
            }

            $linkRows[] = [
                'category_id' => $categoryId,
                'product_id'   => $productId,
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
        }

        foreach (array_chunk($linkRows, 500) as $chunk) {
            DB::table('category_product')->insertOrIgnore($chunk);
        }
        foreach (array_chunk($pendingRows, 500) as $chunk) {
            PendingCategoryLink::insert($chunk);
        }

        return [
            'linked'              => count($linkRows),
            'unmatched_products' => $unmatchedProducts->unique()->values(),
            'pending_links'       => count($pendingRows),
        ];
    }

    /**
     * 🎯 يُستدعى من شاشة المطابقة اليدوية: لكل صف اختار المستخدم تصنيفاً
     * موجوداً له (أو "تجاهل"). تصنيف واحد فقط لكل سطر بهذه الشاشة —
     * لربط منتج بعدة تصنيفات، يُعاد استيراد ملف الربط مرة أخرى.
     *
     * @param array<int,string> $decisions [pending_category_link_id => category_id|'skip']
     */
    public function resolvePendingLinks(string $sessionId, array $decisions): int
    {
        $resolved = 0;
        $now = now();
        $rows = [];

        foreach ($decisions as $pendingId => $categoryId) {
            if ($categoryId === 'skip' || !$categoryId) {
                continue;
            }

            $pending = PendingCategoryLink::forSession($sessionId)->find($pendingId);
            if (!$pending) {
                continue;
            }

            $rows[] = ['category_id' => (int) $categoryId, 'product_id' => $pending->product_id, 'created_at' => $now, 'updated_at' => $now];
            $resolved++;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('category_product')->insertOrIgnore($chunk);
        }

        PendingCategoryLink::forSession($sessionId)->delete();

        return $resolved;
    }
}
