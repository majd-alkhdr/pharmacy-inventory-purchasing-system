<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 قائمة "شركات مستثناة من الترميم" — **دائمة**، لا مرتبطة بأي دفعة
 * رفع محدَّدة. بقرار صريح من صاحب المشروع: استثناء شركة يعني معاملتها
 * كأنها غير موجودة إطلاقاً بملفات المبيعات/جرد الصيدلية — ليس فقط "لا
 * تشحن لها هذه الدفعة" كما كان الإصدار الأول من هذه الميزة.
 *
 * 🩹 هذا استبدال كامل لعمود excluded_restocking_companies على
 * import_sessions (كان مصفوفة JSON لكل دفعة على حدة) — ذلك العمود يبقى
 * بالجدول بلا حذف (بيانات تاريخية لجلسات سابقة)، لكن لا قراءة أو كتابة
 * جديدة إليه بعد الآن؛ كل قرار استثناء يقرأ من هذا الجدول حصراً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('excluded_companies', function (Blueprint $table) {
            $table->id();
            $table->string('company')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('excluded_companies');
    }
};
