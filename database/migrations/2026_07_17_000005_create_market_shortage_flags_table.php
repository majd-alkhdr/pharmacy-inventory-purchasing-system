<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 هذا الجدول هو القلب التقني لميزة "قنص نواقص السوق" الموصوفة بقسم 6
 * من مستندك — الميزة التي وصفتها بأنها "الأهم لضمان التميز التنافسي".
 *
 * آلية العمل (سيُبنى منطقها لاحقاً بطبقة الخدمات، لكن الجدول هو الأساس):
 *   1) عند مطابقة استلام فعلي (عبر temporary_purchase_receipts) مع
 *      purchase_order_items ووجود عجز بالكمية المستلمة → يُنشأ صف هنا
 *      بـ resolved_at = NULL (أي "ما زال مقطوعاً من السوق").
 *   2) بأي فاتورة شراء قادمة لنفس المنتج، محرك الأولويات يتحقق: هل
 *      يوجد صف هنا بـ resolved_at = NULL لهذا المنتج؟ إن وجد، يُعطى
 *      أعلى أولوية ممكنة + يُمنح استثناء تجاوز سقف الميزانية (كما وصفت
 *      بالمستند حرفياً: "Budget Override").
 *   3) عند نجاح استلام كمية كافية لاحقاً، resolved_at يُملأ تلقائياً.
 *
 * لماذا جدول منفصل بدل عمود is_market_shortage على جدول products مباشرة؟
 * لأننا نحتاج **تاريخاً كاملاً** لكل مرة انقطع فيها المنتج من السوق
 * (متى بدأ، متى انتهى) — وليس فقط "حالة حالية". هذا بالضبط عكس قرار
 * product_purchase_settings أعلاه (حيث لم نحتج تاريخاً) — والفرق أن
 * "قنص نواقص السوق" ميزة تسويقية/تنافسية أساسية بمستندك تحتاج تقريراً
 * تاريخياً واضحاً ("كم مرة انقطعت هذه المادة من السوق هذا العام؟").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_shortage_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->timestamp('flagged_at');
            $table->timestamp('resolved_at')->nullable();

            $table->text('note')->nullable();
            $table->timestamps();

            // فهرس يخدم أهم استعلام بهذا الجدول: "أعطني كل المواد المقطوعة
            // من السوق حالياً" — أي resolved_at IS NULL.
            $table->index(['product_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_shortage_flags');
    }
};
