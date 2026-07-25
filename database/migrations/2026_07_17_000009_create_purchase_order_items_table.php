<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 هذا الجدول هو الأكثر أهمية بكل نظام المشتريات — لأنه يحمل عمودين
 * مخصَّصين بالكامل لميزة "لوحة الثقة" (Explainable AI) الموصوفة بقسم 8
 * من مستندك حرفياً: *"بجانب كل سطر بالفاتورة، يوجد شرح مبسّط يوضح سبب
 * هذا الاقتراح"*.
 *
 * explanation_summary (نص): الجملة الجاهزة للعرض مباشرة بالواجهة، مثال
 * حرفي من مستندك: "تم اقتراح 500 قطعة لتغطية احتياج 3 أشهر، ولأن المورد
 * قدّم عرض بونص، وتم رفع الأولوية لكونه صنف تارجت".
 *
 * explanation_factors (JSON): نفس المعلومة، لكن **مُهيكَلة** (structured)
 * بدل نص حر فقط — مثال: {"consumption_qty": 400, "safety_stock": 100,
 * "pending_orders_deducted": 0, "is_target": true, "has_bonus_offer": true}.
 * لماذا كلاهما معاً وليس واحداً فقط؟ لأن explanation_summary يخدم "اعرض
 * فوراً بالواجهة"، بينما explanation_factors يخدم لاحقاً أي شاشة تفاعلية
 * تريد **تلوين أو تصفية** حسب سبب محدد (مثلاً: "أرني فقط الأسطر التي
 * رُفعت أولويتها بسبب كونها تارجت"). نص حر وحده لا يسمح بهذا الاستعلام.
 *
 * is_target_item / is_market_shortage: هذه "لقطة" (Snapshot) لحالة
 * المنتج **وقت توليد الفاتورة تحديداً** — وليست مرجعاً حياً لجدولَي
 * product_purchase_settings وmarket_shortage_flags. هذا مهم: لو تغيّرت
 * حالة "تارجت" لمنتج ما بعد يومين من توليد الفاتورة، يجب أن تبقى
 * الفاتورة القديمة تعرض الحالة التي **بُنيَ عليها القرار وقتها**، لا
 * الحالة الحالية — وإلا فقدنا مصداقية "لوحة التبرير" بأثر رجعي.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('supplier_product_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedInteger('suggested_qty'); // ما اقترحه المحرك تلقائياً
            $table->unsignedInteger('approved_qty')->nullable(); // ما اعتمده المشرف فعلياً (قد يُعدّله يدوياً قبل الإرسال)

            $table->decimal('unit_cost', 10, 2);
            $table->unsignedInteger('bonus_qty')->default(0);
            $table->decimal('line_total_cost', 12, 2);

            // لقطة حالة وقت التوليد — راجع الشرح أعلاه
            $table->boolean('is_target_item')->default(false);
            $table->boolean('is_market_shortage')->default(false);
            $table->boolean('is_over_budget_exception')->default(false); // true = تجاوز سقف الميزانية بسبب "قنص نواقص السوق"

            $table->unsignedInteger('priority_rank')->nullable(); // ترتيب الأولوية ضمن هذه الفاتورة تحديداً (لحل تعارض الميزانية)

            $table->text('explanation_summary')->nullable();
            $table->json('explanation_factors')->nullable();

            // تُملأ لاحقاً بعد الإرسال الفعلي للمورد والاستلام الفعلي بالمستودع
            $table->unsignedInteger('ordered_qty')->nullable();
            $table->unsignedInteger('received_qty')->default(0);

            $table->timestamps();

            $table->index(['purchase_order_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
