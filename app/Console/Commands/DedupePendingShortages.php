<?php
// app/Console/Commands/DedupePendingShortages.php

namespace App\Console\Commands;

use App\Models\Shortage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DedupePendingShortages extends Command
{
    /**
     * php artisan shortages:dedupe
     */
    protected $signature = 'shortages:dedupe {--dry-run : عرض ما سيتم دمجه بدون تنفيذ أي تعديل فعلي}';

    protected $description = 'دمج صفوف النواقص المعلقة (pending) المكررة لنفس الصيدلية والمادة في صف واحد، مع جمع الكميات وأخذ أحدث تاريخ';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        // إيجاد كل تركيبة (صيدلية + مادة) لها أكثر من صف pending واحد
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
            DB::transaction(function () use ($dup, $dryRun, &$mergedGroups, &$deletedRows) {
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
                $notes      = $rows->pluck('notes')->filter()->unique()->implode(' | ');

                $keeper = $rows->first();
                $toDeleteIds = $rows->skip(1)->pluck('id');

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

                $keeper->forceFill([
                    'quantity'   => $totalQty,
                    'created_at' => $latestDate,
                    'notes'      => $notes ?: $keeper->notes,
                ])->save();

                Shortage::whereIn('id', $toDeleteIds)->delete();

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
