<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Console\Commands\DetectStagnantProducts;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 🎯 أضف هذا لملف routes/console.php الموجود عندك (Laravel 12 يستخدم هذا
// الملف للجدولة بدل app/Console/Kernel.php القديم).
// يومياً في الساعة 3 فجراً (وقت هادئ، بعد انتهاء عمليات اليوم) — يكشف
// أي دفعة أصبحت قريبة من انتهاء الصلاحية ويستبعد موادها تلقائياً من
// اقتراحات الشراء القادمة، ويُعيد إدراج أي مادة تجدَّد رصيدها.
Schedule::command(DetectStagnantProducts::class)->dailyAt('03:00');

// 🎁 إضافة اختيارية بسيطة: التوصية المعلَّقة من المرحلة الأولى (تقرير رقم
// 1 بالتوصيات غير المطبَّقة) — جدولة أمر shortages:dedupe الموجود أصلاً
// بمشروعك بدل الاعتماد على تشغيل يدوي. أزل التعليق لو أردت تفعيلها الآن:
// Schedule::command('shortages:dedupe')->dailyAt('03:15');