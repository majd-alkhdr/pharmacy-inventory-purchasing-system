<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 (ب.3) مطابقة فاتورة المناقلة مع ما حُضِّر فعلياً — خطوة مستقلة تماماً عن تعديل الفاتورة
 * (ب.2): تحدث **بعد** التحضير المادي، بملف إكسل (لا صورة — OCR استُبعد لضعف دقته).
 *
 * discrepancy_reasons — جدول مرجعي لا Enum: إضافة سبب جديد = صف جديد، بلا نشر كود.
 *   requires_finance_settlement: هل اختيار هذا السبب يحيل المادة لشاشة "تحتاج تسوية من المالية"؟
 *   ("رصيد وهمي" نعم؛ "خطأ عدّ" لا). العلَم على الصف نفسه — فسبب جديد من فئة الرصيد الوهمي
 *   يُضاف بنفس الطريقة ويعمل فوراً بلا أي شرط مكتوب باسمه بالكود.
 *
 * transfer_reconciliations / _items — كل رفع ملف تحضير = مطابقة مستقلة (إعادة الرفع لا تمسح
 * السابقة: تاريخ كامل). البند يخزّن الرقمين وقت المطابقة (لا يُعاد حسابهما لو عُدّلت الفاتورة).
 *
 * finance_settlement_items — بند لكل فرق سببه يتطلب تسوية مالية. فريد على بند المطابقة:
 * تغيير السبب ذهاباً وإياباً لا يكرر الإحالة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discrepancy_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('label');
            $table->boolean('requires_finance_settlement')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        DB::table('discrepancy_reasons')->insert([
            ['code' => 'phantom_stock', 'label' => 'رصيد وهمي (المادة غير موجودة فعلياً)', 'requires_finance_settlement' => true, 'is_active' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'counting_error', 'label' => 'خطأ عدّ', 'requires_finance_settlement' => false, 'is_active' => true, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'damaged', 'label' => 'تلف', 'requires_finance_settlement' => false, 'is_active' => true, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'wrong_item', 'label' => 'تبديل مادة بالخطأ', 'requires_finance_settlement' => false, 'is_active' => true, 'sort_order' => 4, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'other', 'label' => 'أخرى', 'requires_finance_settlement' => false, 'is_active' => true, 'sort_order' => 99, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::create('transfer_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('import_log_id')->nullable()->constrained('import_logs')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('items_count')->default(0);
            $table->unsignedInteger('mismatched_count')->default(0);
            $table->timestamps();
        });

        Schema::create('transfer_reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_reconciliation_id')->constrained('transfer_reconciliations')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('invoiced_qty');
            $table->unsignedInteger('prepared_qty');
            // محضَّر − مُفوتَر: سالب = نقص بالتحضير، موجب = زيادة.
            $table->integer('difference');
            $table->foreignId('discrepancy_reason_id')->nullable()->constrained('discrepancy_reasons')->nullOnDelete();
            $table->foreignId('reason_set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reason_set_at')->nullable();
            $table->timestamps();

            $table->unique(['transfer_reconciliation_id', 'product_id'], 'reconciliation_items_unique_product');
        });

        Schema::create('finance_settlement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_reconciliation_item_id')->unique()->constrained('transfer_reconciliation_items')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('pharmacy_id')->constrained('pharmacies')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('discrepancy_reason_id')->constrained('discrepancy_reasons');
            $table->unsignedInteger('quantity');
            $table->timestamp('exported_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_settlement_items');
        Schema::dropIfExists('transfer_reconciliation_items');
        Schema::dropIfExists('transfer_reconciliations');
        Schema::dropIfExists('discrepancy_reasons');
    }
};
