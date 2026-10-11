<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🩹 [إصلاح جوهري 2026-09] كانت quantity هنا integer، وSalesImport كان
 * يُطبِّق ceil() على كل سطر خام **قبل** أي تجميع — أي أن بيع 0.5 (ظرف من
 * عبوة ظرفين) يتحوَّل فوراً لـ 1 كاملة، حتى لو بيعت مرة أخرى بدفعة لاحقة
 * (0.5 أخرى) ليصبح المجموع الحقيقي 1 بالضبط، لكن النظام كان بالفعل قد
 * شحن 1+1=2 بدل 1 الصحيحة — ترميم مضاعف حقيقي، مؤكَّد رياضياً.
 *
 * الحل: هذا العمود يحفظ الآن الكمية الكسرية الخام كما هي، والتقريب
 * (عبر SalesRemainderResolverService) يحدث مرة واحدة فقط، بعد تجميع كل
 * كسور نفس الزوج (صيدلية+مادة) — سواء بنفس الدفعة أو عبر دفعات لاحقة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temporary_sales', function (Blueprint $table) {
            $table->decimal('quantity', 10, 4)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('temporary_sales', function (Blueprint $table) {
            $table->integer('quantity')->change();
        });
    }
};
