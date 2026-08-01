<?php
// app/Console/Commands/DedupePendingShortages.php

namespace App\Console\Commands;

use App\Models\Shortage;
use App\Services\Inventory\ShortageRecorderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DedupePendingShortages extends Command
{
    /**
     * php artisan shortages:dedupe
     */
    protected $signature = 'shortages:dedupe {--dry-run : عرض ما سيتم دمجه بدون تنفيذ أي تعديل فعلي}';

    protected $description = 'دمج صفوف النواقص المعلقة (pending) المكررة لنفس الصيدلية والمادة في صف واحد، مع جمع الكميات وأخذ أحدث تاريخ';

    /**
     * 🎯 بعد إعادة الهيكلة: هذا الأمر لم يعد "يحسب الدمج بنفسه" — فقط
     * يكتشف التكرار الموجود فعلياً بقاعدة البيانات (من قبل إصلاح مشكلة
     * التكرار عند المصدر)، ثم يُفوّض عملية الدمج الفعلية بالكامل لنفس
     * الخدمة المستخدمة بالرفع اليومي والاستيراد التاريخي.
     *
     * لماذا هذا أفضل؟ لأنه يضمن **اتساقاً تاماً** بين "كيف يُدمَج نقص
     * جديد اليوم" و"كيف نُصحّح تكراراً قديماً حصل قبل الإصلاح" — نفس
     * القاعدة بالضبط، بلا احتمال أن ينحرف أحد المكانين عن الآخر مستقبلاً.
     */
    public function handle(ShortageRecorderService $shortageRecorder): int
    {
        $dryRun = $this->option('dry-run');

        $duplicates = Shortage::where('status', 'pending')
            ->select('pharmacy_id', 'product_id')
            ->groupBy('pharmacy_id', 'product_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isEmpty()) {
            $this->info('✅ لا توجد صفوف مكررة. لا حاجة لأي دمج.');
            return self::SUCCESS;
        }

        $this->info("تم العثور على {$duplicates->count()} تركيبة (صيدلية+مادة) مكررة.");

        $mergedGroups = 0;
        $deletedRows  = 0;

        foreach ($duplicates as $dup) {
            DB::transaction(function () use ($dup, $dryRun, $shortageRecorder, &$mergedGroups, &$deletedRows) {
                $rows = Shortage::where('status', 'pending')
                    ->where('pharmacy_id', $dup->pharmacy_id)
                    ->where('product_id', $dup->product_id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($rows->count() <= 1) {
                    return;
                }

                $totalQty   = $rows->sum('quantity');
                $latestDate = $rows->max('created_at');

                $this->line(sprintf(
                    '  صيدلية #%d / مادة #%d: %d صفوف → كمية إجمالية %d، أحدث تاريخ %s',
                    $dup->pharmacy_id,
                    $dup->product_id,
                    $rows->count(),
                    $totalQty,
                    $latestDate?->format('Y-m-d')
                ));

                if ($dryRun) {
                    return;
                }

                // ══ نُحوّل كل صف مكرر إلى "حدث نقص" (occurrence)، بما فيها
                // ملاحظاته الأصلية (إن وُجدت) — ونُسلّمها كاملة للخدمة
                // المشتركة لتقوم بالجمع + أخذ أحدث تاريخ + دمج الملاحظات. ══
                $occurrences = $rows->map(fn (Shortage $row) => [
                    'pharmacy_id' => $row->pharmacy_id,
                    'product_id'  => $row->product_id,
                    'quantity'    => $row->quantity,
                    'occurred_at' => $row->created_at,
                    'note'        => $row->notes,
                ]);

                $toDeleteIds = $rows->pluck('id');

                // 🩹 نحذف كل الصفوف المكررة أولاً (بلا استثناء)، ثم نترك
                // ShortageRecorderService يُعيد إنشاء صف واحد مُجمَّع من
                // الصفر — بدل "تحديث صف واحد نُبقيه" كما كان بالأصل. النتيجة
                // النهائية بقاعدة البيانات متطابقة، لكن منطق الحساب موحَّد.
                Shortage::whereIn('id', $toDeleteIds)->delete();

                $shortageRecorder->recordMany($occurrences);

                $mergedGroups++;
                $deletedRows += $toDeleteIds->count();
            });
        }

        if ($dryRun) {
            $this->warn('⚠️ هذا عرض تجريبي (dry-run) فقط، لم يتم تعديل أي بيانات فعلياً.');
            $this->info('لتنفيذ الدمج فعلياً، أعد تشغيل الأمر بدون --dry-run');
            return self::SUCCESS;
        }

        $this->info("✅ تم دمج {$mergedGroups} تركيبة، وحذف {$deletedRows} صف مكرر.");
        return self::SUCCESS;
    }
}