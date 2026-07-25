<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎓 ==================== أهم قرار تصميمي بهذه المرحلة كاملة ====================
 * جدول central_inventories الموجود حالياً بمشروعك (Phase A) يخزّن **رقماً
 * واحداً فقط** لكل منتج: الكمية الإجمالية. ثلاث خدمات كاملة تعتمد عليه
 * بمنطقها الداخلي: ShortageFulfillmentService (ترميم FIFO)،
 * InvoiceGenerationService (توليد فواتير)، CentralInventoryStockService
 * (تحديث الجرد). كلها تقرأ/تكتب quantity كرقم بسيط.
 *
 * أنت اخترت تتبع الصلاحية على مستوى "دفعة" (Batch) — وهذا يعني أن رقماً
 * واحداً لم يعد كافياً؛ نحتاج معرفة **كل دفعة توريد على حدة**: كميتها
 * وتاريخ صلاحيتها المستقل.
 *
 * السؤال الهندسي الحقيقي هنا: هل نحذف central_inventories ونستبدله بالكامل
 * بهذا الجدول؟ **لا** — وهذا نفس مبدأ "الترحيل التدريجي" (Strangler Fig)
 * الذي طبّقناه بالمرحلة الأولى:
 *
 *   1) inventory_batches (هذا الجدول) يصبح "مصدر الحقيقة الجديد" لأي
 *      منطق يحتاج تفاصيل الصلاحية (فلتر الاستبعاد، تنبيهات الركود).
 *   2) central_inventories.quantity يبقى موجوداً تماماً كما هو، لكن
 *      يصبح "قيمة مُلخَّصة محسوبة" (Denormalized Cache) = مجموع كل
 *      دفعات المنتج NON-EXPIRED. أي خدمة من نظام Phase A الحالي
 *      (ShortageFulfillmentService، InvoiceGenerationService...)
 *      تستمر بالعمل **دون أي تعديل عليها إطلاقاً**.
 *   3) خدمة جديدة بنظام المشتريات (ستُبنى بالمرحلة القادمة) هي المسؤولة
 *      عن إبقاء central_inventories.quantity متزامناً مع مجموع الدفعات
 *      في كل مرة تتغيّر فيها دفعة (استلام جديد، بيع/صرف، انتهاء صلاحية).
 *
 * هذا يعني: **صفر مخاطرة على النظام الحالي العامل فعلياً بالإنتاج**،
 * وبناء القدرة الجديدة (تتبع الصلاحية) بجانبه دون تصادم.
 * ================================================================================
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('quantity');
            $table->date('expiry_date')->nullable(); // نادراً ما تكون فارغة، لكن نسمح بها لمواد بلا تاريخ صلاحية (مستلزمات مثلاً)
            $table->date('received_at');

            // ربط اختياري بسطر فاتورة الشراء التي أنشأت هذه الدفعة —
            // يُترك NULL للدفعات المُدخَلة يدوياً أو المستوردة من جرد افتتاحي
            // تاريخي بلا فاتورة شراء مرتبطة بالنظام.
            $table->foreignId('purchase_order_item_id')->nullable()
                ->constrained('purchase_order_items')->nullOnDelete();

            $table->string('batch_reference')->nullable(); // رقم التشغيلة (Lot Number) إن وُجد على العبوة
            $table->timestamps();

            $table->index(['product_id', 'expiry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_batches');
    }
};
