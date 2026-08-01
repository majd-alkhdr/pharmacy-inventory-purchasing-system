<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 يطبّق قرار المستخدم: "بونص وخصم كحقلين مستقلين لكل عرض، وليس نوعاً
 * واحداً حصرياً" — بدل BonusType Enum ('free_quantity' | 'percent_discount')
 * القديم الذي كان يفرض اختيار نوع واحد فقط لكل عرض.
 *
 * مثال حقيقي أصبح ممكناً الآن: عرض "10+1 مع خصم 9%" — كلا الحقلين
 * بنفس السطر، بدل الاضطرار لعرضين منفصلين. راجع SupplierOffer::
 * effectiveUnitCost() لصيغة الحساب الموحّدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_offers', function (Blueprint $table) {
            $table->unsignedInteger('bonus_quantity')->default(0)->after('min_quantity');
            $table->decimal('discount_percent', 5, 2)->default(0)->after('bonus_quantity');
        });

        // نقل البيانات القديمة (إن وُجدت) للحقلين الجديدين قبل حذف القديم
        \Illuminate\Support\Facades\DB::table('supplier_offers')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $update = [];
                if ($row->bonus_type === 'free_quantity') {
                    $update['bonus_quantity'] = (int) $row->bonus_value;
                } elseif ($row->bonus_type === 'percent_discount') {
                    $update['discount_percent'] = (float) $row->bonus_value;
                }
                if (!empty($update)) {
                    \Illuminate\Support\Facades\DB::table('supplier_offers')->where('id', $row->id)->update($update);
                }
            }
        });

        Schema::table('supplier_offers', function (Blueprint $table) {
            $table->dropColumn(['bonus_type', 'bonus_value']);
        });
    }

    public function down(): void
    {
        Schema::table('supplier_offers', function (Blueprint $table) {
            $table->string('bonus_type')->default('free_quantity')->after('min_quantity');
            $table->decimal('bonus_value', 10, 2)->default(0)->after('bonus_type');
            $table->dropColumn(['bonus_quantity', 'discount_percent']);
        });
    }
};
