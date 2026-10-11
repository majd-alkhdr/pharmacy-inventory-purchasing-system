<?php

namespace App\Providers;

use App\Services\Inventory\Import\Contracts\TabularFileReader;
use App\Services\Inventory\Import\Readers\OpenSpoutTabularFileReader;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 🆕 قارئ الملفات المتدفق لكشف المستودعات. الواجهة (TabularFileReader) هي ما تعتمد عليه
        // الخدمات؛ استبدال المكتبة لاحقاً (أو قارئ وهمي بالاختبارات) تعديل سطر واحد هنا.
        $this->app->bind(TabularFileReader::class, OpenSpoutTabularFileReader::class);
    }

    public function boot(): void
    {
        // 🩹 توليد كل روابط route()/url() ديناميكياً حسب الدومين والبروتوكول
        // الحقيقيين اللي استخدمهما المتصفح فعلياً بكل طلب — بدل الاعتماد على
        // APP_URL الثابت بـ .env. هذا يحل مشكلة "Mixed Content" (https تطلب
        // http) نهائياً، ويشتغل تلقائياً حتى لو دومين التنّل تغيّر بكل مرة
        // تشغيل لـ cloudflared، بدون الحاجة لتحديث .env أو عمل config:clear.
        if (! $this->app->runningInConsole()) {
            $request = request();

            // X-Forwarded-Proto ضروري تحديداً لأن الاتصال الفعلي بين
            // cloudflared والسيرفر المحلي يكون http دائماً، حتى لو المستخدم
            // فاتح الموقع فعلياً عبر https من طرف المتصفح.
            $scheme = $request->header('X-Forwarded-Proto', $request->getScheme());
            $host   = $request->header('X-Forwarded-Host', $request->getHttpHost());

            URL::forceRootUrl($scheme . '://' . $host);
            URL::forceScheme($scheme);
        }
    }
}
