<?php

namespace App\Services\Inventory;

use App\Models\Pharmacy;
use App\Models\PharmacyInventory;
use App\Models\PharmacyMinimumStock;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PharmacyInventoryUploadService
{
    public function process(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return ['matched' => 0, 'unmatched' => collect(), 'pharmacy_ids' => collect(), 'overrides_applied' => 0, 'pack_size_divergences' => []];
        }

        $pharmacyNames = $rows->pluck('pharmacy_name')->unique()->values();
        $this->ensurePharmaciesExist($pharmacyNames);
        $pharmacyIdsByName = Pharmacy::whereIn('name', $pharmacyNames)->pluck('id', 'name');

        $barcodes = $rows->pluck('barcode')->filter()->unique()->values();
        $productIdsByBarcode = Product::whereIn('barcode', $barcodes)->pluck('id', 'barcode');

        $matched = 0;
        $unmatched = collect();
        $insertRows = [];
        // 🆕 (min_threshold, batch_size) اختياريان — نجمعها فقط للصفوف
        // المُطابَقة فعلياً (لا معنى لتجاوز صنف غير معروف بالنظام أصلاً)
        $overrideRows = [];
        $now = now();

        foreach ($rows as $row) {
            $productId = $productIdsByBarcode[$row['barcode']] ?? null;

            if (!$productId) {
                $unmatched->push("{$row['barcode']} — {$row['material_name']} ({$row['pharmacy_name']})");
                continue;
            }

            $pharmacyId = $pharmacyIdsByName[$row['pharmacy_name']];

            $insertRows[] = [
                'pharmacy_id' => $pharmacyId,
                'product_id'   => $productId,
                'quantity'     => $row['quantity'],
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
            $matched++;

            // 🆕 راجع PharmacyInventoryImport للتفاصيل الكاملة — null يعني
            // "لا تجاوز مذكور لهذا الصف"، فلا داعي لأي تحديث إطلاقاً له.
            if (!is_null($row['min_threshold'] ?? null) || !is_null($row['batch_size'] ?? null)) {
                $overrideRows[] = [
                    'pharmacy_id'   => $pharmacyId,
                    'product_id'    => $productId,
                    'min_threshold' => $row['min_threshold'] ?? null,
                    'batch_size'    => $row['batch_size'] ?? null,
                ];
            }
        }

        $overridesApplied = 0;
        $divergences = []; // 🆕 راجع تعليق applyOverrides للسبب الكامل

        DB::transaction(function () use ($pharmacyIdsByName, $insertRows, $overrideRows, &$overridesApplied, &$divergences) {
            PharmacyInventory::whereIn('pharmacy_id', $pharmacyIdsByName->values())->delete();
            foreach (array_chunk($insertRows, 500) as $chunk) {
                DB::table('pharmacy_inventories')->insert($chunk);
            }

            $result = $this->applyOverrides($overrideRows);
            $overridesApplied = $result['applied'];
            $divergences = $result['divergences'];
        });

        return [
            'matched'                => $matched,
            'unmatched'              => $unmatched->unique()->values(),
            'pharmacy_ids'           => $pharmacyIdsByName->values(),
            'overrides_applied'      => $overridesApplied,
            'pack_size_divergences' => $divergences, // 🆕
        ];
    }

    /**
     * 🎯 يُحدِّث min_reorder_gap و/أو pack_size_override فقط لأزواج
     * (صيدلية+مادة) **موجودة أصلاً** بـ pharmacy_minimum_stocks — لا يُنشئ
     * صفاً جديداً هنا مهما كان. السبب: صف بهذا الجدول بلا minimum_quantity
     * حقيقي (القادم حصراً من شاشة "المخزون القياسي للصيدليات" المنفصلة)
     * يكون إعداداً ناقصاً/بلا معنى — تجاوز بلا شيء لتجاوزه.
     *
     * 🆕 (2026-09) كشف "تباعد" غير حاجب: pack_size_override وproducts.pack_size
     * لا يتصادمان أبداً تقنياً (الأول تجاوز خاص بزوج، الثاني افتراضي عام —
     * effectivePackSize() يفضِّل الأول دائماً عند وجوده). لكن هذا بالضبط
     * سبب الخطر: خطأ إملائي بعمود حجم العبوة الاختياري بملف صيدلية واحدة
     * يُطبَّق بصمت **بلا أي تنبيه**، ولا يظهر إلا لاحقاً ككميات شحن غريبة
     * لتلك الصيدلية تحديداً. نجمع كل حالة تجاوز تختلف قيمتها عن افتراضي
     * المنتج هنا، لتُعرَض كتنبيه غير حاجب برسالة الاكتمال — لا نرفض الرفع،
     * فقد يكون التجاوز مقصوداً فعلاً؛ فقط نجعل الاختلاف مرئياً بدل صامت.
     *
     * @param array<int, array{pharmacy_id:int, product_id:int, min_threshold:?int, batch_size:?int}> $overrideRows
     * @return array{applied:int, divergences:array<int,array{pharmacy:string,product:string,override:int,default:int}>}
     */
    private function applyOverrides(array $overrideRows): array
    {
        if (empty($overrideRows)) {
            return ['applied' => 0, 'divergences' => []];
        }

        $pairs = collect($overrideRows);
        $pharmacyIds = $pairs->pluck('pharmacy_id')->unique();
        $productIds  = $pairs->pluck('product_id')->unique();

        // ⚡ استعلام واحد يجلب فقط الأزواج المُعدَّة مسبقاً ضمن نطاق هذه
        // الدفعة، بدل استعلام "هل هذا الزوج موجود؟" منفصل لكل صف. نحمِّل
        // المنتج (للمقارنة + اسمه بالتنبيه) والصيدلية (اسمها بالتنبيه) معاً.
        $existingPairs = PharmacyMinimumStock::whereIn('pharmacy_id', $pharmacyIds)
            ->whereIn('product_id', $productIds)
            ->with(['product:id,name,pack_size', 'pharmacy:id,name'])
            ->get(['id', 'pharmacy_id', 'product_id'])
            ->keyBy(fn(PharmacyMinimumStock $row) => "{$row->pharmacy_id}_{$row->product_id}");

        $applied = 0;
        $divergences = [];

        foreach ($pairs as $row) {
            $existing = $existingPairs->get("{$row['pharmacy_id']}_{$row['product_id']}");
            if (!$existing) {
                // لا معيار مخزون قياسي مُعدّ لهذا الزوج أصلاً — تجاهل بصمت،
                // بلا خطأ ظاهر للمستخدم (هذا سلوك "تجاهل" متعمَّد، لا فشل).
                continue;
            }

            $update = [];
            if (!is_null($row['min_threshold'])) {
                $update['min_reorder_gap'] = $row['min_threshold'];
            }
            if (!is_null($row['batch_size'])) {
                $update['pack_size_override'] = $row['batch_size'];

                // 🆕 تنبيه غير حاجب — راجع تعليق الدالة أعلاه للسبب الكامل
                $defaultPackSize = (int) ($existing->product->pack_size ?? 0);
                if ((int) $row['batch_size'] !== $defaultPackSize) {
                    $divergences[] = [
                        'pharmacy' => $existing->pharmacy->name ?? '?',
                        'product'  => $existing->product->name ?? '?',
                        'override' => (int) $row['batch_size'],
                        'default'  => $defaultPackSize,
                    ];
                }
            }

            if (!empty($update)) {
                $existing->update($update);
                $applied++;
            }
        }

        return ['applied' => $applied, 'divergences' => $divergences];
    }

    private function ensurePharmaciesExist(Collection $names): void
    {
        $existing = Pharmacy::whereIn('name', $names)->pluck('name');
        $missing = $names->diff($existing);

        if ($missing->isEmpty()) {
            return;
        }

        $now = now();
        $rows = $missing->map(fn($name) => ['name' => $name, 'created_at' => $now, 'updated_at' => $now])->all();
        DB::table('pharmacies')->insertOrIgnore($rows);
    }
}
