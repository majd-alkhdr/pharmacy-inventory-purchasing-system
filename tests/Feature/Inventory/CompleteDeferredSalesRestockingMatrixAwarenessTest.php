<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\RestockingMode;
use App\Enums\ShortageStatus;
use App\Models\CentralInventory;
use App\Models\ImportSession;
use App\Models\Pharmacy;
use App\Models\PharmacyInventory;
use App\Models\PharmacyMinimumStock;
use App\Models\Product;
use App\Models\Shortage;
use App\Models\TemporarySale;
use App\Services\Inventory\SalesMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

/**
 * 🎯 يحمي إصلاحين حقيقيين أبلغ عنهما المستخدم فعلياً بـ completeDeferredSalesRestocking():
 *
 * 1. كان الفحص `!$session->inventory_file_path` وحده يقرر "هل يوجد جرد مركزي؟" —
 *    يفحص فقط عمود ملف الجرد **القديم المنفصل**، ويتجاهل warehouse_matrix_file_path
 *    كلياً. جلسة رفعت كشف مستودعات فعلياً (يحمل بيانات مركزية حقيقية) كانت
 *    تُصنَّف خطأً "بلا جرد مركزي" فتتحول تسجيلاً تاريخياً فقط بلا أي ترميم حقيقي.
 *
 * 2. نتيجة fulfillActiveShortages() (بآثارها الجانبية الحقيقية: فواتير إلحاقية
 *    فعلية لنواقص قديمة) كانت تُحسَب لكنها **تُهمَل كلياً بالرسالة المُعادة** —
 *    التي كانت تقول "بلا أي ترميم أو فاتورة" حتى لو أُنشئت فاتورة إلحاقية فعلياً.
 *
 * 🎓 لماذا Reflection لاستدعاء completeDeferredSalesRestocking() مباشرة، لا عبر
 * saveMapping() العامة؟ لأن saveMapping() نفسها تستخدم SQL خام بصياغة
 * `UPDATE...JOIN` (MySQL صحيحة، SQLite لا تدعمها نحوياً بصرف النظر عن البيانات)
 * لمطابقة الأسماء — نفس القيد المُوثَّق بجولات سابقة هذه الجلسة. اختبار المنطق
 * الفعلي (بعد افتراض أن المطابقة تمت بنجاح) مباشرة يتفادى هذا القيد بلا مساس
 * بصحة الاختبار — المطابقة نفسها موجودة ومُختبَرة (ضمنياً) عبر resolveSalesComponent().
 */
final class CompleteDeferredSalesRestockingMatrixAwarenessTest extends TestCase
{
    use RefreshDatabase;

    private const SESSION = 'deferred_sales_matrix_test';

    /** يستدعي الدالة الخاصة مباشرة — راجع تعليق الكلاس للسبب. */
    private function callCompleteDeferredSalesRestocking(string $sessionId): array
    {
        $service = $this->app->make(SalesMappingService::class);
        $method = (new ReflectionClass($service))->getMethod('completeDeferredSalesRestocking');
        $method->setAccessible(true);

        return $method->invoke($service, $sessionId);
    }

    public function test_a_matrix_session_actually_restocks_instead_of_falling_back_to_pure_history(): void
    {
        $pharmacy = Pharmacy::create(['name' => 'صيدلية أ']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ']);
        CentralInventory::create(['product_id' => $product->id, 'quantity' => 100]);
        PharmacyInventory::create(['pharmacy_id' => $pharmacy->id, 'product_id' => $product->id, 'quantity' => 40]);
        PharmacyMinimumStock::create(['pharmacy_id' => $pharmacy->id, 'product_id' => $product->id, 'minimum_quantity' => 50]);

        // 🎯 جلسة كشف مستودعات — لا inventory_file_path (العمود القديم) إطلاقاً،
        // فقط warehouse_matrix_file_path. هذا بالضبط ما كان يُخلَط بـ"بلا جرد مركزي".
        ImportSession::create([
            'session_id' => self::SESSION, 'type' => 'sales_and_inventory', 'status' => 'processing',
            'restocking_mode' => RestockingMode::SalesGatedStandardInventory,
            'inventory_file_path' => null,
            'warehouse_matrix_file_path' => 'imports/some-matrix-file.xlsx',
        ]);

        // مبيعات مُطابَقة بالفعل (نتحاشى SQL الخام غير المتوافق مع SQLite — راجع تعليق الكلاس)
        TemporarySale::create([
            'session_id' => self::SESSION, 'pharmacy_name' => 'صيدلية أ', 'company' => 'شركة أ',
            'material_name' => 'بنادول', 'quantity' => 15, 'pharmacy_id' => $pharmacy->id, 'product_id' => $product->id,
        ]);

        $result = $this->callCompleteDeferredSalesRestocking(self::SESSION);

        $this->assertTrue($result['success']);
        // 🩹 قبل الإصلاح: كانت هذه الرسالة تحديداً هي ما يظهر (المسار الخاطئ).
        $this->assertStringNotContainsString('بلا أي ترميم أو فاتورة', $result['message']);
        // الفجوة الفعلية بعد البيع (40-15=25 رصيد، قياسي 50 ⇐ فجوة 25) وُلِّدت فاتورة حقيقية لها.
        $this->assertSame(1, DB::table('invoices')->count());
        $item = DB::table('invoice_items')->first();
        $this->assertSame(25, (int) $item->requested_qty);
    }

    public function test_genuinely_no_inventory_data_at_all_still_falls_back_to_pure_history_correctly(): void
    {
        // 🎯 تأكيد أن الإصلاح لم يكسر الحالة الأصلية الصحيحة: لا ملف جرد قديم
        // ولا كشف مستودعات إطلاقاً — التسجيل التاريخي فقط ما زال يعمل كما يجب.
        $pharmacy = Pharmacy::create(['name' => 'صيدلية أ']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ']);

        ImportSession::create([
            'session_id' => self::SESSION, 'type' => 'sales_and_inventory', 'status' => 'processing',
            'restocking_mode' => RestockingMode::SalesGatedStandardInventory,
            'inventory_file_path' => null, 'warehouse_matrix_file_path' => null,
        ]);

        TemporarySale::create([
            'session_id' => self::SESSION, 'pharmacy_name' => 'صيدلية أ', 'company' => 'شركة أ',
            'material_name' => 'بنادول', 'quantity' => 15, 'pharmacy_id' => $pharmacy->id, 'product_id' => $product->id,
        ]);

        $result = $this->callCompleteDeferredSalesRestocking(self::SESSION);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('بلا أي ترميم أو فاتورة', $result['message']);
        $this->assertSame(0, DB::table('invoices')->count());
    }

    public function test_the_pure_history_message_mentions_old_shortages_it_actually_fulfilled(): void
    {
        // 🎯 يثبت الإصلاح الثاني تحديداً: fulfillActiveShortages() لها أثر جانبي
        // حقيقي (فاتورة إلحاقية لنقص قديم غير مرتبط بهذه الدفعة إطلاقاً) — يجب
        // أن تُذكَر الرسالة هذا صراحة، لا أن تدّعي "بلا أي ترميم" رغم حدوثه فعلياً.
        $overstocked = Pharmacy::create(['name' => 'صيدلية فائضة']);
        $short = Pharmacy::create(['name' => 'صيدلية ناقصة']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ']);
        CentralInventory::create(['product_id' => $product->id, 'quantity' => 50]);

        // نقص معلَّق قديم، لا علاقة له بمبيعات هذه الدفعة إطلاقاً
        Shortage::create([
            'pharmacy_id' => $short->id, 'product_id' => $product->id, 'quantity' => 20,
            'status' => ShortageStatus::Pending, 'source' => 'standard_inventory',
        ]);

        ImportSession::create([
            'session_id' => self::SESSION, 'type' => 'sales_and_inventory', 'status' => 'processing',
            'restocking_mode' => RestockingMode::Sales,
            'inventory_file_path' => null, 'warehouse_matrix_file_path' => null,
        ]);

        // مبيعات لصيدلية غير المصاب بالنقص أصلاً — فقط لتفعيل مسار "مبيعات بلا جرد مركزي"
        TemporarySale::create([
            'session_id' => self::SESSION, 'pharmacy_name' => 'صيدلية فائضة', 'company' => 'شركة أ',
            'material_name' => 'بنادول', 'quantity' => 2, 'pharmacy_id' => $overstocked->id, 'product_id' => $product->id,
        ]);

        $result = $this->callCompleteDeferredSalesRestocking(self::SESSION);

        $this->assertTrue($result['success']);
        // 🩹 قبل الإصلاح: الرسالة كانت تتجاهل هذا كلياً، رغم أن فاتورة إلحاقية حقيقية أُنشئت.
        $this->assertStringContainsString('تم ترميم نواقص قديمة', $result['message']);
        $this->assertSame(1, DB::table('invoices')->count());   // الفاتورة الإلحاقية للنقص القديم
    }
}
