<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Pharmacy;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 يحفظ قرارات المطابقة اليدوية لأسماء الصيدليات: "هذا الاسم = صيدلية X" أو
 * "هذا الاسم صيدلية جديدة". بنفس دلالة (وصيغة مدخلات) شاشة المطابقة الحالية
 * تماماً — الشاشة نفسها تُستخدَم لكشف المستودعات دون أي تعديل عليها.
 *
 * 🎓 لماذا كلاس مستقل بدل استدعاء SalesMappingService؟ ذاك الكلاس يخلط حفظ مطابقة
 * الصيدليات بتحديث temporary_sales وإكمال ترميم المبيعات المؤجَّل — لا علاقة لأيٍّ
 * منهما بكشف المستودعات. هذه الدلالات الثلاث (فرز الخيارات، إنشاء الجديد، حفظ المطابقة)
 * نُسخت هنا حرفياً كي تكون مستقلة. (التوحيد مع SalesMappingService مهمة تنظيف لاحقة
 * آمنة، لا يجوز أن تُلحَق بميزة جديدة بلا اختبارات كافية على المسار القديم.)
 *
 * الحفظ يجعل المطابقة **دائمة**: الرفعة التالية تحل الاسم تلقائياً (PharmacyColumnResolver
 * يعطي المطابقات المحفوظة الأولوية).
 */
final class PharmacyMappingRecorder
{
    /**
     * @param list<array{excel_name?: string|null, mapped_id?: string|int|null}> $choices بصيغة الشاشة: mapped_id = "NEW" أو معرّف صيدلية
     * @return int عدد القرارات التي حُفظت فعلاً (غير المحسومة تُتخطى)
     */
    public function record(array $choices): int
    {
        $newNames = [];
        $links = [];

        foreach ($choices as $choice) {
            $name = trim((string) ($choice['excel_name'] ?? ''));
            $mappedId = $choice['mapped_id'] ?? null;

            if ($name === '' || $mappedId === null || $mappedId === '') {
                continue; // لم يُحسم — لا نخمّن
            }

            if ($mappedId === 'NEW') {
                $newNames[$name] = true;
            } else {
                $links[$name] = (int) $mappedId;
            }
        }

        $newNames = array_keys($newNames);

        DB::transaction(function () use ($newNames, $links): void {
            $now = now();

            // pharmacies.name فريد: insertOrIgnore يجعل "جديدة" آمنة لو وُجدت بنفس الاسم مسبقاً.
            foreach (array_chunk(array_map(
                fn (string $name): array => ['name' => $name, 'created_at' => $now, 'updated_at' => $now],
                $newNames,
            ), 500) as $chunk) {
                DB::table('pharmacies')->insertOrIgnore($chunk);
            }

            $idsByName = $newNames === [] ? [] : Pharmacy::query()->whereIn('name', $newNames)->pluck('id', 'name')->all();

            $rows = [];
            foreach ($newNames as $name) {
                if (isset($idsByName[$name])) {
                    $rows[] = ['original_name' => $name, 'pharmacy_id' => $idsByName[$name], 'created_at' => $now, 'updated_at' => $now];
                }
            }
            foreach ($links as $name => $pharmacyId) {
                $rows[] = ['original_name' => $name, 'pharmacy_id' => $pharmacyId, 'created_at' => $now, 'updated_at' => $now];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('pharmacy_mappings')->upsert($chunk, ['original_name'], ['pharmacy_id', 'updated_at']);
            }
        });

        return count($newNames) + count($links);
    }
}
