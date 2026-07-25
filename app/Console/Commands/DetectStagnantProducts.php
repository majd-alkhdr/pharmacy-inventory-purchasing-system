<?php
// app/Console/Commands/DetectStagnantProducts.php

namespace App\Console\Commands;

use App\Services\Purchasing\StagnantProductDetectionService;
use Illuminate\Console\Command;

/**
 * 🎯 بنفس فلسفة DedupePendingShortages بالضبط (المرحلة الأولى): أمر
 * Artisan مستقل بمسؤولية واحدة، قابل للتشغيل يدوياً أو جدولته تلقائياً.
 *
 * php artisan purchasing:detect-stagnant
 * php artisan purchasing:detect-stagnant --months=3   (عتبة مخصَّصة)
 */
class DetectStagnantProducts extends Command
{
    protected $signature = 'purchasing:detect-stagnant {--months=6 : عدد الأشهر — أي دفعة تنتهي صلاحيتها خلال هذه المدة تُعتبر الماد راكدة}';

    protected $description = 'كشف/تحديث الأصناف الراكدة (قريبة الصلاحية) تلقائياً، واستبعادها من خوارزمية الشراء';

    public function handle(StagnantProductDetectionService $service): int
    {
        $months = (int) $this->option('months');

        $this->info("جاري فحص الدفعات القريبة من انتهاء الصلاحية (أقل من {$months} أشهر)...");

        $result = $service->handle($months);

        $this->info("✅ تم وسم {$result['flagged']} صنف جديد كراكد.");
        $this->info("✅ تم إلغاء وسم {$result['unflagged']} صنف لم يعد راكداً (تجدَّد رصيده).");

        return self::SUCCESS;
    }
}
