<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\ImportSession;
use App\Services\Inventory\InventoryUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 🎯 يحمي إصلاحاً احترازياً: كان catch (\Exception $e) فقط — لا يلتقط \Error
 * (TypeError، ValueError، وأخواتها، أو "استدعاء دالة على null"). لو انهار
 * PHP بخطأ من صنف \Error بمنتصف processInventoryUpload()، كان الاستثناء
 * يهرب من هذه الدالة دون تسجيل محلي أو تنظيف (Rollback، حذف الصفوف
 * المؤقتة) — حتى لو التُقط لاحقاً على مستوى الطابور (ProcessInventoryUploadJob::failed()).
 * الآن: \Throwable تلتقط كليهما هنا مباشرة.
 *
 * 🎓 لماذا ValueError تحديداً لمحاكاة العطل؟ لأنه طريقة **طبيعية وواقعية**
 * (لا اختلاق قسري) ليحدث خطأ من صنف \Error حقاً: قيمة عمود restocking_mode
 * الخام بقاعدة البيانات لا تطابق أي حالة من RestockingMode Enum — يحدث هذا
 * فعلياً لو تغيّرت قيم الـ Enum مستقبلاً بلا مايجريشن بيانات مصاحب، أو
 * بفساد بيانات نادر. PHP يرمي ValueError (تمتد من \Error، لا \Exception)
 * عند محاولة قراءة عمود مُلقَّى (Cast) لقيمة Enum غير صالحة.
 */
final class InventoryUploadThrowableSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_genuine_error_class_throw_is_caught_and_the_session_is_marked_failed(): void
    {
        $sessionId = 'throwable_safety_test';

        // قيمة خام لا تطابق أي حالة RestockingMode — تُدرَج مباشرة (تتخطى الـ Cast
        // عند الكتابة)، فيحدث ValueError عند القراءة اللاحقة عبر Eloquent/restockingMode().
        DB::table('import_sessions')->insert([
            'session_id' => $sessionId, 'type' => 'sales_and_inventory', 'status' => 'processing',
            'restocking_mode' => 'this_is_not_a_valid_enum_value',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // لا try/catch هنا عمداً — لو لم يُلتقَط الخطأ داخلياً، PHPUnit سيُبلغ
        // عن استثناء غير مُلتقَط هارب من الاستدعاء، وهذا الاختبار سيفشل تلقائياً.
        $this->app->make(InventoryUploadService::class)->processInventoryUpload($sessionId, null, null, []);

        $session = ImportSession::where('session_id', $sessionId)->first();

        $this->assertSame('failed', $session->status);
        $this->assertStringContainsString('حدث خطأ', (string) $session->message);
    }
}
