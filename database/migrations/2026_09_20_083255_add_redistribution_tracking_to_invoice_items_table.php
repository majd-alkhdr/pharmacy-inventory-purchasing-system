<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 يسمح بمعرفة وفلترة أي سطر فاتورة "إلحاقية" استفاد من مناقلة عكسية
 * وصلت بنفس دفعة المعالجة، بدل الاكتفاء بملاحظة نصية غير قابلة للفلترة.
 * راجع ShortageFulfillmentService للاستهلاك الفعلي.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->boolean('is_redistributed')->default(false)->after('shortage_qty');
            $table->json('redistribution_sources')->nullable()->after('is_redistributed');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn(['is_redistributed', 'redistribution_sources']);
        });
    }
};
