<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 هذا الجدول يخدم بالضبط الميزة التي طلبتها حرفياً بمستندك:
 * "إمكانية رفع ملف يحوي مسودة المشتريات المستلمة بالمستودع ليتم مطابقتها
 * مع الفاتورة المطلوبة وتسجيل ما تم ترميمه وما لم يتم لاستنتاج الأصناف
 * غير المتوفرة بالسوق حالياً".
 *
 * لاحظ التصميم: **نفس النمط المعماري تماماً** المستخدم بجدولَي
 * temporary_sales وtemporary_shortages بمشروعك الحالي (session_id +
 * بيانات خام من الإكسل + أعمدة ربط nullable تُملأ بعد المطابقة). هذا
 * ليس صدفة — طالما نمط "ارفع ملف → طابق تلقائياً/يدوياً → حوّل لسجلات
 * نهائية" أثبت نجاحه مرتين بمشروعك، نعيد استخدامه هنا بدل اختراع نمط
 * جديد. هذا تطبيق لمبدأ **الاتساق المعماري** (Architectural Consistency)
 * — كل ميزة "استيراد" بالنظام تشعر وكأنها بُنيت بنفس اليد.
 *
 * matched_purchase_order_item_id: الحقل الأهم هنا — بمجرد ملئه، نعرف
 * "هذا السطر بالملف المرفوع يقابل هذا السطر بالضبط بفاتورة الشراء
 * الأصلية"، فيمكن حساب الفرق (المطلوب - المستلم) لتفعيل منطق "قنص
 * نواقص السوق" تلقائياً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temporary_purchase_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('session_id');

            // الصيغة الأبسط: المستخدم يرفع الملف وهو يعرف مسبقاً لأي فاتورة
            // شراء يخص هذا الاستلام (كما تُصمَّم شاشة الرفع لاحقاً).
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();

            $table->string('barcode')->nullable();
            $table->string('material_name');
            $table->string('company')->default('-');
            $table->unsignedInteger('received_qty');
            $table->date('expiry_date')->nullable();

            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            // 🩹 إصلاح خطأ فعلي حصل عند التشغيل الحقيقي (MySQL Error 1059):
            // الاسم الذي كان Laravel يولّده تلقائياً لهذا القيد هو:
            //   temporary_purchase_receipts_matched_purchase_order_item_id_foreign
            // وطوله 68 حرفاً — بينما MySQL يرفض أي معرّف (اسم جدول/عمود/قيد)
            // أطول من 64 حرفاً بالضبط (رسالة الخطأ: "Identifier name ...
            // is too long"). السبب: اسم الجدول نفسه طويل (28 حرفاً) واسم
            // العمود طويل أيضاً (31 حرفاً)، ومجموعهما مع اللاحقة "_foreign"
            // يتجاوز الحد. الحل: فصل تعريف العمود عن تعريف القيد، وتسمية
            // القيد يدوياً باسم مختصر وواضح بدل الاسم التلقائي الطويل.
            $table->unsignedBigInteger('matched_purchase_order_item_id')->nullable();
            $table->foreign('matched_purchase_order_item_id', 'temp_receipts_matched_item_fk')
                ->references('id')->on('purchase_order_items')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['session_id', 'barcode']);
            $table->index(['session_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temporary_purchase_receipts');
    }
};
