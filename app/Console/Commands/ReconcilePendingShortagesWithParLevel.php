<?php
// app/Console/Commands/ReconcilePendingShortagesWithParLevel.php

namespace App\Console\Commands;

use App\Enums\ShortageStatus;
use App\Models\PharmacyInventory;
use App\Models\PharmacyMinimumStock;
use App\Models\Shortage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 أمر تصحيح لمرة واحدة (One-Time Reconciliation) — يُطبَّق فور نشر هذا
 * التحديث، ثم لا حاجة له بالتشغيل الدوري (المنطق الجديد بـ
 * ParLevelReplenishmentService يمنع تكرار هذه المشكلة مستقبلاً تلقائياً
 * بكل رفع جرد صيدليات).
 *
 * يفحص كل نقص "pending" حالياً، ويقارنه بالمخزون القياسي + رصيد الصيدلية
 * الفعلي الحاليين (إن وُجدا): لو الرصيد الحالي >= القياسي، يُغلَق النقص
 * فوراً كخطأ قديم (تماماً حالة "سيرنغ 5 مل" التي أوضحتها).
 *
 * php artisan shortages:reconcile-par-level {--dry-run}
 */
class ReconcilePendingShortagesWithParLevel extends Command
{
    protected $signature = 'shortages:reconcile-par-level {--dry-run : عرض ما سيُغلَق بدون تنفيذ}';
    protected $description = 'إغلاق أي نقص معلَّق أصبح غير صحيح لأن رصيد الصيدلية الفعلي بلغ أو تجاوز مخزونها القياسي';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $pending = Shortage::pending()->get();
        if ($pending->isEmpty()) {
            $this->info('✅ لا توجد نواقص معلَّقة إطلاقاً.');
            return self::SUCCESS;
        }

        $pairs = $pending->map(fn(Shortage $s) => ['pharmacy_id' => $s->pharmacy_id, 'product_id' => $s->product_id]);
        $minimumsByPair = PharmacyMinimumStock::bulkForPairs($pairs);

        $pharmacyStockByPair = PharmacyInventory::whereIn('pharmacy_id', $pairs->pluck('pharmacy_id')->unique())
            ->whereIn('product_id', $pairs->pluck('product_id')->unique())
            ->get()
            ->keyBy(fn(PharmacyInventory $r) => "{$r->pharmacy_id}_{$r->product_id}")
            ->map(fn(PharmacyInventory $r) => $r->quantity);

        $toClose = 0;

        foreach ($pending as $shortage) {
            $key = "{$shortage->pharmacy_id}_{$shortage->product_id}";
            $standard = $minimumsByPair[$key] ?? null;
            $current  = $pharmacyStockByPair[$key] ?? null;

            if ($standard === null || $current === null) {
                continue; // لا بيانات كافية بعد — يُترَك للمنطق الجديد عند أول رفع قادم
            }

            if ($current >= $standard) {
                $toClose++;
                $this->line("سيُغلَق: صيدلية #{$shortage->pharmacy_id} / مادة #{$shortage->product_id} — نقص معلَّق {$shortage->quantity}، رصيد فعلي {$current} >= قياسي {$standard}.");

                if (!$dryRun) {
                    $shortage->update([
                        'quantity' => 0,
                        'status'   => ShortageStatus::Fulfilled,
                        'notes'    => $shortage->appendResolutionNote(
                            'أُغلق تلقائياً بأمر التصحيح التاريخي — رصيد الصيدلية الفعلي يساوي أو يتجاوز المخزون القياسي.'
                        ),
                    ]);
                }
            }
        }

        $dryRun
            ? $this->warn("⚠️ عرض تجريبي فقط — {$toClose} نقص سيُغلَق فعلياً عند إزالة --dry-run.")
            : $this->info("✅ تم إغلاق {$toClose} نقص كان غير صحيح.");

        return self::SUCCESS;
    }
}
