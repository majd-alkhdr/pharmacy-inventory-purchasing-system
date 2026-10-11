<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\RestockingMode;
use App\Models\ImportSession;
use App\Models\Pharmacy;
use App\Models\Product;
use App\Models\TemporarySale;
use App\Http\Controllers\InventoryController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Tests\TestCase;

/**
 * 🎯 يحمي إصلاح خطأ حقيقي أبلغ عنه المستخدم فعلياً: كشف مستودعات صحيح تماماً
 * (لا يحتاج أي مطابقة أعمدة) + ملف مبيعات به أسماء غير مطابَقة، بوضع "مخزون
 * قياسي مُفعَّل بالمبيعات". النتيجة الفعلية بالإنتاج: الجلسة تصل لحالة
 * needs_mapping برسالة صحيحة بقاعدة البيانات — لكن showMapping() كانت تفترض
 * "جلسة كشف مستودعات ⇐ لا بد أن السبب أعمدة الكشف"، فترى كشف المستودعات
 * سليماً وتُعيد التوجيه لشاشة الرفع **بصمت تام** (بلا استدعاء ->with()) —
 * تاركة مشكلة المبيعات الحقيقية غير معروضة إطلاقاً للمستخدم.
 */
final class MatrixSessionSalesMappingFallthroughTest extends TestCase
{
    use RefreshDatabase;

    private const SESSION = 'matrix_sales_mapping_test';

    private function storeCleanMatrix(string $pharmacyName): string
    {
        $relative = 'imports/' . bin2hex(random_bytes(6)) . '.xlsx';
        $writer = new XlsxWriter();
        $writer->openToFile(Storage::path($relative));
        $writer->addRow(Row::fromValues(['رمز المادة', 'الاسم', 'الشركة', 'مستودع الصناعة', $pharmacyName]));
        $writer->addRow(Row::fromValues(['111', 'بنادول', 'شركة أ', 100, 40]));
        $writer->close();

        return $relative;
    }

    private function controller(): InventoryController
    {
        return $this->app->make(InventoryController::class);
    }

    public function test_showing_mapping_for_a_clean_matrix_session_falls_through_to_unmapped_sales_names(): void
    {
        // 🎯 كشف المستودعات نفسه سليم تماماً (يطابق صيدلية مسجَّلة فعلياً) —
        // لا شيء يحتاج مطابقة أعمدة إطلاقاً هنا.
        $registeredPharmacy = Pharmacy::create(['name' => 'صيدلية أ']);
        $product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ']);
        $matrixPath = $this->storeCleanMatrix('صيدلية أ');

        ImportSession::create([
            'session_id' => self::SESSION, 'type' => 'sales_and_inventory', 'status' => 'needs_mapping',
            'restocking_mode' => RestockingMode::SalesGatedStandardInventory,
            'warehouse_matrix_file_path' => $matrixPath,
        ]);

        // لكن اسم صيدلية بملف المبيعات لا يطابق أي صيدلية مسجَّلة — هذا سبب التوقف الحقيقي.
        TemporarySale::create([
            'session_id' => self::SESSION, 'pharmacy_name' => 'صيدلية مكتوبة بخطأ إملائي',
            'company' => 'شركة أ', 'material_name' => 'بنادول', 'quantity' => 5,
            'pharmacy_id' => null, 'product_id' => $product->id,
        ]);

        $response = $this->controller()->showMapping(self::SESSION);

        // 🩹 قبل الإصلاح: كان هذا يُعيد RedirectResponse صامتاً لشاشة الرفع.
        // بعد الإصلاح: يجب أن يُعيد شاشة المطابقة نفسها، حاملة اسم صيدلية المبيعات غير المطابَق.
        $this->assertInstanceOf(\Illuminate\Contracts\View\View::class, $response);
        $this->assertSame('inventory.mapping', $response->name());
        $this->assertTrue($response->getData()['unmappedPharmacies']->contains('صيدلية مكتوبة بخطأ إملائي'));
    }

    public function test_a_matrix_session_that_genuinely_needs_column_mapping_still_shows_that_first(): void
    {
        // 🎯 تأكيد أن الإصلاح لم يكسر المسار الأصلي: عمود كشف مستودعات فعلاً
        // غير مطابَق يجب أن يظهر كما كان، بلا تأثير من وجود مبيعات أو عدمه.
        $matrixPath = $this->storeCleanMatrix('صيدلية غير مسجَّلة إطلاقاً');

        ImportSession::create([
            'session_id' => self::SESSION, 'type' => 'sales_and_inventory', 'status' => 'needs_mapping',
            'restocking_mode' => RestockingMode::SalesGatedStandardInventory,
            'warehouse_matrix_file_path' => $matrixPath,
        ]);

        $response = $this->controller()->showMapping(self::SESSION);

        $this->assertInstanceOf(\Illuminate\Contracts\View\View::class, $response);
        $this->assertSame('inventory.mapping', $response->name());
        $this->assertTrue($response->getData()['unmappedPharmacies']->contains('صيدلية غير مسجَّلة إطلاقاً'));
    }

    public function test_a_clean_matrix_session_with_no_unmapped_sales_either_redirects_normally(): void
    {
        // 🎯 لا شيء يحتاج مطابقة إطلاقاً (لا كشف مستودعات ولا مبيعات) — التوجيه
        // الصامت لشاشة الرفع هنا **صحيح فعلاً** (لا يوجد ما يُعرَض أصلاً).
        Pharmacy::create(['name' => 'صيدلية أ']);
        $matrixPath = $this->storeCleanMatrix('صيدلية أ');

        ImportSession::create([
            'session_id' => self::SESSION, 'type' => 'sales_and_inventory', 'status' => 'needs_mapping',
            'restocking_mode' => RestockingMode::SalesGatedStandardInventory,
            'warehouse_matrix_file_path' => $matrixPath,
        ]);

        $response = $this->controller()->showMapping(self::SESSION);

        $this->assertInstanceOf(\FakeRedirectResponse::class, $response);
    }
}
