<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 يطبّق قرار المستخدم الجديد (المرحلة الثانية من إعادة هيكلة المشتريات):
 * "اختيار المورد يصبح حسب الشركة المصنّعة المفضّلة له، وليس حسب مادة بعينها،
 * لأن عدد المواد كبير جداً ليُربط كل واحدة يدوياً."
 *
 * 🎓 لماذا جدول علائقي حقيقي (وليس عمود JSON كما جُرِّب سابقاً بمايجريشن
 * 2026_07_27_000004 ثم تراجَع عنه بمايجريشن 2026_07_28_000001)؟
 * ------------------------------------------------------------------
 * المحاولة السابقة (`suppliers.distributed_companies` كـ JSON) كانت *محسوبة
 * حياً* من supplier_products، فتراجعنا عنها لأنها لم تكن تحتاج تخزيناً أصلاً.
 * هذه المرة الوضع مختلف جذرياً: هذا الحقل **يُدخله المستخدم يدوياً وصراحة**
 * (هو من يقرر "هذا المورد يوزّع هذه الشركة")، وليس نتيجة استنتاج آلي من
 * جدول آخر. بيانات يُدخلها المستخدم يدوياً ولها معنى تجاري (Business Meaning)
 * تستحق دائماً جدولاً علائقياً حقيقياً بفهرسة صحيحة، لا عمود JSON مبهم.
 *
 * unique(['supplier_id', 'company_name']): يمنع تكرار نفس الشركة لنفس
 * المورد بصفين منفصلين بالخطأ.
 *
 * index('company_name'): يخدم الاستعلام الأهم بهذا الجدول بالكامل — عند
 * توليد فاتورة شراء لمنتج معيّن، السؤال الذي يُطرح آلاف المرات هو:
 * "من هم الموردون الذين يفضّلون شركة X تحديداً؟" — بدون هذا الفهرس، هذا
 * الاستعلام يصبح Full Table Scan مع كل منتج يُفحص.
 *
 * ⚠️ ملاحظة مهمة: supplier_products وsupplier_offers **لا يُحذفان ولا
 * يتأثران بهذا المايجريشن إطلاقاً** — يبقيان موجودين تماماً كما هما، تمهيداً
 * لعودة نظام الأسعار والعروض التفصيلية لاحقاً. هذا الجدول الجديد هو مسار
 * موازٍ (Parallel Path) للاختيار الحالي المبسّط بلا أسعار، وليس بديلاً
 * هدّامياً لما هو موجود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();

            // 🎯 نص حر يطابق قيمة products.company نصياً (وليس foreignId) —
            // لأن "الشركة" هنا مفهوم وصفي (نفس النمط المستخدم أصلاً بعمود
            // products.company نفسه)، وليس كياناً مستقلاً له جدول خاص به.
            $table->string('company_name');

            $table->timestamps();

            $table->unique(['supplier_id', 'company_name']);
            $table->index('company_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_companies');
    }
};
