<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 يطبّق قرار المستخدم بخصوص معادلة دوران المخزون (قسم 3 من طلبه):
 * "دوران المادة الجديدة يُعالَج بالاستفادة من ملف الجرد الذي يتم رفعه حيث
 * يوجد تاريخ آخر شراء لكل مادة، وفي حال كان غير موجود اعتمد على created_at
 * للمادة."
 *
 * 🎓 لماذا عمود مستقل last_purchase_date على products، وليس الاعتماد
 * فقط على created_at الموجود أصلاً؟
 * ------------------------------------------------------------------
 * created_at يمثّل "متى أُنشئ سجل هذا المنتج بقاعدة البيانات لأول مرة"،
 * وهذا **ليس بالضرورة** نفس معنى "متى آخر مرة اشتُريت هذه المادة فعلياً".
 * منتج قد يكون موجوداً بالنظام منذ سنة (created_at قديم) لكن آخر عملية
 * شراء فعلية له كانت الأسبوع الماضي — وهنا بالضبط تكمن الفائدة العملية:
 * التفريق بين "مادة راكدة فعلاً منذ مدة طويلة" و"مادة جديدة الشراء
 * تحديداً، بيعت بكمية قليلة حتى الآن لأنها لم تُطرَح كفاية، وليس لأنها
 * راكدة" (توضيح المستخدم الصريح بالنقطة 6 من نقاشنا).
 *
 * nullable: لأن هذا الحقل يبقى فارغاً حتى أول رفع لملف جرد المشتريات
 * يحمل قيمة له. المنطق التطبيقي (Product::isNewlyPurchased()) يتراجع
 * تلقائياً لـ created_at كبديل (Fallback) عندما يكون هذا العمود NULL —
 * تماماً كما طلب المستخدم، بدون الحاجة لعمود إضافي منفصل لذلك.
 *
 * تحديث هذا العمود يتم *حصراً* من خدمة رفع ملف جرد المشتريات
 * (PurchaseInventoryUploadService) — لا مكان آخر بالنظام يكتب إليه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->date('last_purchase_date')->nullable()->after('company');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('last_purchase_date');
        });
    }
};
