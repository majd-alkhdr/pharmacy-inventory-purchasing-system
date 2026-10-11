<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 يضيف "مصدر الفاتورة" — لكنه nullable **عمداً، لا نسياناً لقيمة
 * افتراضية**. الفواتير الأساسية (status=processed) دائماً أحادية
 * المصدر بحكم التصميم: ParLevelReplenishmentService تُنشئ فاتورتها
 * الخاصة بالكامل، وInvoiceGenerationService كذلك — كل واحدة منهما
 * تكتب هذا العمود صراحة عند الإنشاء.
 *
 * ⚠️ الفواتير الإلحاقية (status=supplementary_invoice، من
 * ShortageFulfillmentService) لا تُحدِّث هذا العمود إطلاقاً وتبقى NULL
 * للأبد — بقرار معماري صريح، لا نقص تنفيذ: تلك الخدمة تُرمِّم كل
 * النواقص المعلَّقة معاً (Shortage::pending() بلا أي فلترة على المصدر)
 * ضمن نفس الفاتورة الإلحاقية الواحدة لكل صيدلية/يوم — فقد تحوي فعلياً
 * أسطراً من نواقص مصدرها sales وأخرى مصدرها standard_inventory معاً.
 * فرض قيمة واحدة هنا كان سيكون كذباً معمارياً؛ NULL هو التمثيل الصادق
 * الوحيد لـ"هذه الفاتورة مصادرها متعددة على مستوى السطر، لا الفاتورة".
 *
 * لهذا السبب بالتحديد صُمِّم تقرير "كشف تجهيز الطلبيات" (PreparationExport)
 * ليعتمد على مجموع الكميات مباشرة من invoice_items، لا على هذا العمود —
 * هذا العمود يخدم فقط "التفصيل الاختياري" داخل ذلك التقرير، وليس أساس
 * التجميع نفسه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('source')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
