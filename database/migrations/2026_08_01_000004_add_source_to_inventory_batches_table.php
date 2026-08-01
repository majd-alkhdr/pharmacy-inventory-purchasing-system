<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 يطبّق قرارين مترابطين من إعادة هيكلة المشتريات:
 *
 *   1) "الاستلام الفعلي لن يُضيف أي شيء لأي جرد بالنظام — جرد النظام هو
 *      الملف الذي أرفعه أنا (المشرف) حصراً." (قرار المستخدم، النقطة 6)
 *   2) نُعيد استخدام جدول inventory_batches *نفسه* الموجود أصلاً (بدل جدول
 *      منفصل جديد) لتخزين دفعات جرد المشتريات — بعد موافقة المستخدم
 *      الصريحة على هذا القرار بالذات أثناء مراجعة وثيقة التصميم.
 *
 * 🎓 لماذا هذا العمود ضروري رغم أننا نعيد استخدام جدول موجود؟
 * ------------------------------------------------------------------
 * inventory_batches كان مصمَّماً أصلاً (مايجريشن 2026_07_17_000010) ليُغذَّى
 * *حصراً* من ReceivingReconciliationService عند تسجيل استلام فعلي — بدليل
 * أن العمود purchase_order_item_id هو الرابط الأساسي لكل دفعة. من الآن
 * فصاعداً، الاستلام الفعلي **يتوقف** عن الكتابة لهذا الجدول (تعديل
 * ReceivingReconciliationService القادم)، ويصبح المصدر الوحيد للتغذية هو
 * ملف جرد المشتريات (PurchaseInventoryUploadService).
 *
 * عمود source يوثّق هذا التحوّل بشكل صريح وقابل للاستعلام — بدل الاعتماد
 * على استنتاج غير مباشر (مثلاً: "إن كان purchase_order_item_id فارغاً إذاً
 * هو من الجرد")، والذي كان يفشل مع الدفعات القديمة المُدخَلة يدوياً أصلاً
 * قبل هذا التغيير (راجع PurchasingScenariosSeeder — دفعات بلا
 * purchase_order_item_id لأسباب اختبارية أخرى تماماً). التوثيق الصريح هنا
 * يمنع أي التباس مستقبلي، ويسهّل جداً أي تقرير تشخيصي لاحق (مثال: "كم
 * دفعة مصدرها الاستلام القديم مقابل الجرد الجديد؟").
 *
 * القيمة الافتراضية 'purchase_inventory_upload': كل دفعة تُنشأ من الآن
 * فصاعداً (سواء من ملف الجرد أو أي مسار آخر مستقبلي) تُصنَّف هكذا ما لم
 * يُحدَّد خلاف ذلك صراحة بالكود وقت الإنشاء.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_batches', function (Blueprint $table) {
            $table->string('source')->default('purchase_inventory_upload')->after('batch_reference');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_batches', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
