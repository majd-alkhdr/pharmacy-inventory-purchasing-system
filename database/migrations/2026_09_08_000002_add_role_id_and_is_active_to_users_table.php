<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🩹 role_id قابل لأن يكون null على مستوى القاعدة عمداً (أماناً لو وُجدت
 * صفوف مستخدمين قديمة بلا دور مُسنَد بعد)، لكنه **إلزامي فعلياً** بكل
 * نقاط الإنشاء بالتطبيق (Seeder وشاشة "إنشاء مستخدم" كلاهما يفرضانه) —
 * لا يوجد مسار حقيقي بالتطبيق ينتج مستخدماً بلا دور. مستخدم بـ role_id
 * فارغ فعلياً (لو حدث بأي طريقة يدوية خارج التطبيق) يُعامَل كـ"بلا أي
 * صلاحية إطلاقاً" تلقائياً (راجع User::hasModuleAccess())، لا كخطأ.
 *
 * is_active يتيح **تعطيل** حساب دون حذفه — نفس فلسفة عدم الحذف الفعلي
 * المُتَّبعة بكل مكان آخر بالمشروع (الفواتير، النواقص...)، هنا لحفظ
 * الأثر التاريخي لمن أنشأ ماذا، حتى بعد إيقاف وصوله.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
            $table->dropColumn('is_active');
        });
    }
};
