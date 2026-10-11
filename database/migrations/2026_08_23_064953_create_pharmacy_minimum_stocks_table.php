<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 الحد الأدنى مضبوط **لكل زوج (صيدلية + مادة) على حدة** — وليس عاماً
 * لكل النظام (بخلاف الحد الأدنى بقسم المشتريات الذي أضفناه سابقاً،
 * والمقصود منه رصيد المستودع المركزي للشراء، مفهوم مختلف تماماً). يبقى
 * محفوظاً حتى يُعدَّله رفع ملف جديد — بنفس فلسفة كل ملفات "الحد الأدنى"
 * بالمشروع (آخر ملف مرفوع = مصدر الحقيقة).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacy_minimum_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('minimum_quantity');
            $table->timestamps();

            $table->unique(['pharmacy_id', 'product_id']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_minimum_stocks');
    }
};
