<?php

namespace App\Enums;

/**
 * حالات النقص (Shortage).
 * 🎯 بديل الـ "magic strings" ('pending', 'fulfilled') التي كانت متناثرة
 * بين: Controller, Service, DedupePendingShortages, ShortagesExport, Views.
 * أي خطأ إملائي بنص حر (مثلاً 'Pending' بدل 'pending') لا يكتشفه PHP إطلاقاً
 * إلا وقت التشغيل. الـ Enum يكتشفه IDE و PHP نفسه وقت الكتابة (Static Analysis).
 */
enum ShortageStatus: string
{
    case Pending   = 'pending';
    case Fulfilled = 'fulfilled';

    public function label(): string
    {
        return match ($this) {
            self::Pending   => 'معلق ⏳',
            self::Fulfilled => 'محلول ✅',
        };
    }
}
