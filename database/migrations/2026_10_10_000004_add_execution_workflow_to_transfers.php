<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 (ب.6/ب.7) سير عمل "اقتراح ← تنفيذ" للمناقلات العكسية، وربط النوعين بدورة الرفع.
 *
 * reverse_transfers.status: كانت المناقلة العكسية تُطبَّق فوراً عند تسجيلها (زيادة
 * المركزي + طرح الصيدلية). أصبحت الآن **مقترحة** تنتظر تنفيذاً صريحاً، مثل المناقلات
 * بين الصيدليات تماماً. ⚠️ الصفوف القديمة طُبِّق أثرها فعلاً بالمخزون وقت تسجيلها —
 * فتُوسَم 'executed' (أرشيف) لا 'suggested'، وإلا ظهرت كاقتراحات قابلة للتنفيذ مرة ثانية.
 *
 * origin: من أين نشأت العكسية — محرك الترميم (تلقائياً) أو زر "مناقلات؟" بالمشتريات (ج.3).
 * نفس الكيان ونفس مسار التنفيذ بالحالتين؛ الحقل للعرض والتدقيق فقط.
 *
 * execution_status (على مستوى البند): عند التنفيذ، بند فقد فائضه لصالح مناقلة أخرى
 * بنفس الدورة يُوسَم 'superseded' بدل تنفيذه جزئياً — لا تنفيذ جزئي لكمية بند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reverse_transfers', function (Blueprint $table) {
            $table->string('status', 20)->default('suggested')->after('date');
            $table->string('origin', 20)->default('restocking')->after('status');
            $table->foreignId('cycle_import_log_id')->nullable()->after('origin')->constrained('import_logs')->nullOnDelete();
            $table->timestamp('executed_at')->nullable()->after('cycle_import_log_id');
            $table->foreignId('executed_by')->nullable()->after('executed_at')->constrained('users')->nullOnDelete();

            $table->index(['status', 'cycle_import_log_id']);
        });

        DB::table('reverse_transfers')->update(['status' => 'executed']);

        Schema::table('reverse_transfer_items', function (Blueprint $table) {
            $table->string('execution_status', 20)->nullable()->after('pharmacy_quantity_at_time');
        });

        Schema::table('pharmacy_transfers', function (Blueprint $table) {
            $table->foreignId('cycle_import_log_id')->nullable()->after('status')->constrained('import_logs')->nullOnDelete();
            $table->foreignId('executed_by')->nullable()->after('executed_date')->constrained('users')->nullOnDelete();
        });

        Schema::table('pharmacy_transfer_items', function (Blueprint $table) {
            $table->string('execution_status', 20)->nullable()->after('to_pharmacy_gap_at_time');
        });
    }

    public function down(): void
    {
        Schema::table('pharmacy_transfer_items', fn (Blueprint $table) => $table->dropColumn('execution_status'));

        Schema::table('pharmacy_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('executed_by');
            $table->dropConstrainedForeignId('cycle_import_log_id');
        });

        Schema::table('reverse_transfer_items', fn (Blueprint $table) => $table->dropColumn('execution_status'));

        Schema::table('reverse_transfers', function (Blueprint $table) {
            $table->dropIndex(['status', 'cycle_import_log_id']);
            $table->dropConstrainedForeignId('executed_by');
            $table->dropConstrainedForeignId('cycle_import_log_id');
            $table->dropColumn(['status', 'origin', 'executed_at']);
        });
    }
};
