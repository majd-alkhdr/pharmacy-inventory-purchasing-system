<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\CentralInventory;
use App\Models\InventoryBatch;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MarketShortageFlag;
use App\Models\Pharmacy;
use App\Models\Product;
use App\Models\ProductAlternative;
use App\Models\ProductPurchaseSetting;
use App\Models\ProductSellingPrice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\SupplierOffer;
use App\Models\SupplierProduct;
use App\Models\BudgetSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * 🎯 Seeder اختباري شامل — كل رقم هنا **مقصود ومحسوب يدوياً** (راجع ملف
 * "اختبار-البيانات-والنتائج-المتوقعة.md" المرافق لهذا الملف لكل حساب
 * بالتفصيل). لا تُشغّل هذا الـ Seeder على قاعدة بيانات إنتاجية — هو
 * مُصمَّم لقاعدة اختبار/تجريبية فارغة أو شبه فارغة فقط.
 *
 * ⚠️ التواريخ هنا مبنية بإزاحة نسبية عن now() (subDays) وليست تواريخ
 * ثابتة — لضمان أن معدلات الاستهلاك المحسوبة تبقى نظيفة (أرقام صحيحة)
 * بغض النظر عن تاريخ تشغيل الـ Seeder فعلياً، **بشرط أن تُجري كل
 * اختبارات التحقق بنفس اليوم الذي شغّلت فيه هذا الملف** (وإلا تتغيّر
 * "الأيام المُلاحَظة" المستخدمة بحساب معدل الاستهلاك اليومي بمرور الوقت).
 *
 * php artisan db:seed --class=Database\\Seeders\\PurchasingScenariosSeeder
 */
class PurchasingScenariosSeeder extends Seeder
{
    public function run(): void
    {
        $pharmacy = Pharmacy::create(['name' => 'صيدلية الاختبار المركزية']);

        // ══════════════════════════════════════════════════════════
        // الموردون
        // ══════════════════════════════════════════════════════════
        $s1  = Supplier::create(['name' => 'شركة الشفاء للتوزيع', 'lead_time_days' => 7, 'is_active' => true]);
        $s2  = Supplier::create(['name' => 'مؤسسة الدواء الحديث', 'lead_time_days' => 10, 'is_active' => true]);
        $s10 = Supplier::create(['name' => 'مورد رابع - اختبار قفل المسودة', 'lead_time_days' => 5, 'is_active' => true]);
        $s11 = Supplier::create(['name' => 'مورد خامس - اختبار الاستلام الجزئي', 'lead_time_days' => 5, 'is_active' => true]);
        $s12 = Supplier::create(['name' => 'مورد سادس - اختبار الإلغاء الكامل', 'lead_time_days' => 5, 'is_active' => true]);
        $s13 = Supplier::create(['name' => 'مورد سابع - فاتورة مكتملة (ضبط سلبي)', 'lead_time_days' => 5, 'is_active' => true]);
        $s15 = Supplier::create(['name' => 'مورد ثامن - اختبار تجاوز الميزانية المعزول', 'lead_time_days' => 5, 'is_active' => true]);
        $s16 = Supplier::create(['name' => 'مورد تاسع - المورد المفضَّل', 'lead_time_days' => 5, 'is_active' => true]);

        // ══════════════════════════════════════════════════════════
        // التصنيفات (Categories)
        // ══════════════════════════════════════════════════════════
        $catPain = Category::create(['name' => 'مسكنات']);
        $catAnti = Category::create(['name' => 'مضادات التهاب']);
        Category::create(['name' => 'مضادات حيوية']); // موجودة بالقائمة لكن بلا أي منتج مرتبط بها — لاختبار عرض تصنيف فارغ

        // ══════════════════════════════════════════════════════════
        // دالة مساعدة محلية: إنشاء منتج + جدول استهلاك بمعدل نظيف
        // ══════════════════════════════════════════════════════════
        $makeProduct = function (string $barcode, string $name, string $company) {
            return Product::create(['barcode' => $barcode, 'name' => $name, 'company' => $company]);
        };

        $addConsumption = function (Product $product, int $daysAgo, int $qty) use ($pharmacy) {
            $invoice = Invoice::create([
                'pharmacy_id' => $pharmacy->id,
                'date'        => Carbon::now()->subDays($daysAgo)->toDateString(),
                'status'      => 'processed',
            ]);
            InvoiceItem::create([
                'invoice_id'    => $invoice->id,
                'product_id'    => $product->id,
                'requested_qty' => $qty,
                'supplied_qty'  => $qty,
                'shortage_qty'  => 0,
            ]);
        };

        // ══════════════════════════════════════════════════════════
        // P1 — صنف تارجت + عرض متدرّج (بونص+خصم مركَّب) — يختبر
        // "الربحية الأعلى" وتضخيم الكمية لتفعيل شريحة أعلى
        // معدل استهلاك: 60 وحدة قبل 19 يوماً → 60/20 = 3.0/يوم بالضبط
        // ══════════════════════════════════════════════════════════
        $p1 = $makeProduct('TEST001', 'دواء تارجت-بونص (اختبار الربحية)', 'فارما-A');
        $addConsumption($p1, 19, 60);
        ProductPurchaseSetting::create(['product_id' => $p1->id, 'is_target' => true]);
        InventoryBatch::create(['product_id' => $p1->id, 'quantity' => 10, 'received_at' => now()->subDays(30)->toDateString()]);
        ProductSellingPrice::create(['product_id' => $p1->id, 'price' => 1.30, 'effective_from' => now()->toDateString()]);
        $p1sp = SupplierProduct::create(['supplier_id' => $s1->id, 'product_id' => $p1->id, 'unit_cost' => 1.00, 'is_preferred' => true, 'last_price_updated_at' => now()]);
        SupplierOffer::create(['supplier_product_id' => $p1sp->id, 'min_quantity' => 100, 'bonus_quantity' => 10, 'discount_percent' => 9]);
        $p1->categories()->attach($catPain->id);

        // ══════════════════════════════════════════════════════════
        // P2 — بلا سعر بيع مسجَّل — يختبر "تراجع الهامش المجهول"
        // معدل استهلاك: 10 وحدات قبل 19 يوماً → 10/20 = 0.5/يوم بالضبط
        // ══════════════════════════════════════════════════════════
        $p2 = $makeProduct('TEST002', 'دواء بلا سعر بيع (اختبار التراجع)', 'فارما-B');
        $addConsumption($p2, 19, 10);
        // ⚠️ عمداً: لا يوجد أي سطر بجدول product_selling_prices لهذا المنتج
        SupplierProduct::create(['supplier_id' => $s1->id, 'product_id' => $p2->id, 'unit_cost' => 3.00, 'last_price_updated_at' => now()]);

        // ══════════════════════════════════════════════════════════
        // P3 — راكد (Stagnant) — يُستبعد بالكامل قبل أي حساب احتياج
        // ══════════════════════════════════════════════════════════
        $p3 = $makeProduct('TEST003', 'دواء راكد (اختبار الاستبعاد)', 'فارما-A');
        ProductPurchaseSetting::create(['product_id' => $p3->id, 'is_stagnant' => true, 'stagnant_marked_at' => now()]);
        SupplierProduct::create(['supplier_id' => $s1->id, 'product_id' => $p3->id, 'unit_cost' => 1.00, 'last_price_updated_at' => now()]);

        // ══════════════════════════════════════════════════════════
        // P4 — نقص حرج (رصيد صفري) — أولوية 2 بمحرك الميزانية
        // معدل استهلاك: 90 وحدة قبل 9 أيام → 90/10 = 9.0/يوم بالضبط
        // ══════════════════════════════════════════════════════════
        $p4 = $makeProduct('TEST004', 'دواء نقص حرج (رصيد صفري)', 'فارما-D');
        $addConsumption($p4, 9, 90);
        SupplierProduct::create(['supplier_id' => $s1->id, 'product_id' => $p4->id, 'unit_cost' => 2.00, 'last_price_updated_at' => now()]);

        // ══════════════════════════════════════════════════════════
        // P5 — مقطوع من السوق (Market Shortage) — يُدرَج دائماً أولاً
        // بصرف النظر عن الميزانية. معدل استهلاك: 20 وحدة قبل 4 أيام
        // → 20/5 = 4.0/يوم بالضبط
        // ══════════════════════════════════════════════════════════
        $p5 = $makeProduct('TEST005', 'دواء مقطوع من السوق', 'فارما-E');
        $addConsumption($p5, 4, 20);
        SupplierProduct::create(['supplier_id' => $s1->id, 'product_id' => $p5->id, 'unit_cost' => 5.00, 'last_price_updated_at' => now()]);
        MarketShortageFlag::create(['product_id' => $p5->id, 'flagged_at' => now()->subDays(2)]);

        // ══════════════════════════════════════════════════════════
        // P6 — بلا مورد مرتبط إطلاقاً — يظهر بعدّاد productsWithNoSupplier
        // معدل استهلاك: 10 وحدات قبل 9 أيام → 1.0/يوم
        // ══════════════════════════════════════════════════════════
        $p6 = $makeProduct('TEST006', 'دواء بلا مورد مرتبط', 'فارما-F');
        $addConsumption($p6, 9, 10);
        // ⚠️ عمداً: لا يوجد أي SupplierProduct لهذا المنتج

        // ══════════════════════════════════════════════════════════
        // P7 — شركة مشتركة بين موردين (S1 وS2) — يختبر تحذير التداخل
        // وقفل مسودة S2 بنفس الوقت. معدل استهلاك: 1.0/يوم (نفس نمط P6)
        // ══════════════════════════════════════════════════════════
        $p7 = $makeProduct('TEST007', 'دواء بشركة مشتركة بين موردين', 'فارما-C');
        $addConsumption($p7, 9, 10);
        SupplierProduct::create(['supplier_id' => $s1->id, 'product_id' => $p7->id, 'unit_cost' => 1.00, 'last_price_updated_at' => now()]);
        $p7sp_s2 = SupplierProduct::create(['supplier_id' => $s2->id, 'product_id' => $p7->id, 'unit_cost' => 1.20, 'last_price_updated_at' => now()]);

        // ══════════════════════════════════════════════════════════
        // P8 — اختبار "ترميم فقط" (RestockingOnly): مورد مفضَّل أغلى
        // من مورد آخر أرخص — يجب أن يختار النظام المفضَّل (S16) رغم
        // غلائه. معدل استهلاك: 1.0/يوم (نفس نمط P6/P7)
        // ══════════════════════════════════════════════════════════
        $p8 = $makeProduct('TEST008', 'دواء اختبار ترميم فقط', 'فارما-G');
        $addConsumption($p8, 9, 10);
        SupplierProduct::create(['supplier_id' => $s1->id, 'product_id' => $p8->id, 'unit_cost' => 1.00, 'is_preferred' => false, 'last_price_updated_at' => now()]);
        SupplierProduct::create(['supplier_id' => $s16->id, 'product_id' => $p8->id, 'unit_cost' => 1.30, 'is_preferred' => true, 'last_price_updated_at' => now()]);

        // ══════════════════════════════════════════════════════════
        // P9 — عرض "بونص فقط" (بلا خصم) — معدل استهلاك: 30 وحدة قبل
        // 14 يوماً → 30/15 = 2.0/يوم بالضبط
        // ══════════════════════════════════════════════════════════
        $p9 = $makeProduct('TEST009', 'دواء عرض بونص فقط', 'فارما-H');
        $addConsumption($p9, 14, 30);
        $p9sp = SupplierProduct::create(['supplier_id' => $s1->id, 'product_id' => $p9->id, 'unit_cost' => 1.50, 'last_price_updated_at' => now()]);
        SupplierOffer::create(['supplier_product_id' => $p9sp->id, 'min_quantity' => 40, 'bonus_quantity' => 5, 'discount_percent' => 0]);

        // ══════════════════════════════════════════════════════════
        // P10 — عرض "خصم فقط" (بلا بونص) — معدل استهلاك: 10 وحدات
        // قبل 9 أيام → 1.0/يوم بالضبط
        // ══════════════════════════════════════════════════════════
        $p10 = $makeProduct('TEST010', 'دواء عرض خصم فقط', 'فارما-I');
        $addConsumption($p10, 9, 10);
        $p10sp = SupplierProduct::create(['supplier_id' => $s1->id, 'product_id' => $p10->id, 'unit_cost' => 1.00, 'last_price_updated_at' => now()]);
        SupplierOffer::create(['supplier_product_id' => $p10sp->id, 'min_quantity' => 20, 'bonus_quantity' => 0, 'discount_percent' => 15]);

        // ══════════════════════════════════════════════════════════
        // P11 — بلا احتياج إطلاقاً (لا تاريخ مبيعات) — يظهر بعدّاد
        // productsWithNoRequirement رغم وجود مورد مرتبط به
        // ══════════════════════════════════════════════════════════
        $p11 = $makeProduct('TEST011', 'دواء بلا احتياج حالياً', 'فارما-J');
        SupplierProduct::create(['supplier_id' => $s1->id, 'product_id' => $p11->id, 'unit_cost' => 1.00, 'last_price_updated_at' => now()]);

        // ══════════════════════════════════════════════════════════
        // P12/P13 — بديلان يدويان (product_alternatives) — معلوماتي،
        // لا يدخل بأي حساب آلي
        // ══════════════════════════════════════════════════════════
        $p12 = $makeProduct('TEST012', 'دواء أصلي', 'فارما-K');
        $p13 = $makeProduct('TEST013', 'دواء بديل يدوي', 'فارما-L');
        ProductAlternative::create(['product_id' => $p12->id, 'alternative_product_id' => $p13->id, 'note' => 'بديل مقبول مؤكَّد من الصيدلي المختص — اختبار يدوي فقط']);

        // ══════════════════════════════════════════════════════════
        // P14 — منتج بتصنيفين معاً (اختبار العلاقة متعددة لمتعددة)
        // ══════════════════════════════════════════════════════════
        $p14 = $makeProduct('TEST014', 'دواء بتصنيفين معاً', 'فارما-M');
        $p14->categories()->attach([$catPain->id, $catAnti->id]);

        // ══════════════════════════════════════════════════════════
        // P15 — لاختبار شاشة "مطابقة الروابط المعلّقة" يدوياً عبر ملف
        // test-category-links.csv المرفق (يحتوي تصنيفاً غير معروف لهذا
        // الباركود عمداً) — لا رابط تصنيف له هنا بالـ Seeder نفسه
        // ══════════════════════════════════════════════════════════
        $makeProduct('TEST015', 'دواء بتصنيف غير معروف بالملف', 'فارما-N');

        // ══════════════════════════════════════════════════════════
        // P16 — صنف بسيط عادي (بلا تارجت/عرض/نقص) — يختبر السلوك
        // الجشع (Greedy) لمحرك الميزانية: مادة صغيرة تُدرَج رغم تأخّر
        // ترتيبها، لأنها تُناسب المساحة المتبقية بالضبط
        // معدل استهلاك: 10 وحدات قبل 9 أيام → 1.0/يوم
        // ══════════════════════════════════════════════════════════
        $p16 = $makeProduct('TEST016', 'دواء بسيط (اختبار الخوارزمية الجشعة)', 'فارما-O');
        $addConsumption($p16, 9, 10);
        SupplierProduct::create(['supplier_id' => $s1->id, 'product_id' => $p16->id, 'unit_cost' => 0.333, 'last_price_updated_at' => now()]);
        // 🎯 ملاحظة: unit_cost = 0.333 × 30 وحدة ≈ 10.00 بالضبط (بعد round(...,2))

        // ══════════════════════════════════════════════════════════
        // P17 — مقطوع من السوق بمورد معزول تماماً (S15) — لاختبار
        // "استثناء تجاوز الميزانية" (Budget Override) بمعزل عن أي
        // تشويش من بقية السيناريوهات. معدل استهلاك: 100 وحدة قبل 9
        // أيام → 100/10 = 10.0/يوم بالضبط
        // ══════════════════════════════════════════════════════════
        $p17 = $makeProduct('TEST017', 'دواء مقطوع من السوق (اختبار معزول)', 'فارما-P');
        $addConsumption($p17, 9, 100);
        SupplierProduct::create(['supplier_id' => $s15->id, 'product_id' => $p17->id, 'unit_cost' => 5.00, 'last_price_updated_at' => now()]);
        MarketShortageFlag::create(['product_id' => $p17->id, 'flagged_at' => now()->subDays(1)]);

        // ══════════════════════════════════════════════════════════
        // إعداد الميزانية الشهرية الحالية — سقف 700 لاختبار "Test Run #1"
        // ══════════════════════════════════════════════════════════
        BudgetSetting::create([
            'effective_month'     => now()->startOfMonth()->toDateString(),
            'monthly_cap_amount' => 700,
            'is_enabled'          => true,
        ]);

        // ══════════════════════════════════════════════════════════
        // فاتورة مسودة موجودة أصلاً لـ S2 (تحتوي P7) — تُفعّل معاً:
        // 1) قفل توليد فواتير جديدة لـ S2
        // 2) تحذير تداخل الموردين على P7 عند توليد فاتورة S1
        // ══════════════════════════════════════════════════════════
        $draftS2 = PurchaseOrder::create([
            'supplier_id'            => $s2->id,
            'status'                  => 'draft',
            'scenario'                => 'balanced',
            'forecast_period_days'   => 30,
            'generated_at'            => now()->subHours(3),
            'total_estimated_cost'   => 24.00,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id'  => $draftS2->id,
            'product_id'          => $p7->id,
            'supplier_product_id' => $p7sp_s2->id,
            'suggested_qty'       => 20,
            'unit_cost'            => 1.20,
            'line_total_cost'     => 24.00,
        ]);

        // ══════════════════════════════════════════════════════════
        // فاتورة مسودة معزولة لـ S10 — اختبار "قفل مسودة" بسيط ومباشر
        // ══════════════════════════════════════════════════════════
        $pIsolatedDraft = $makeProduct('TEST018', 'دواء بمسودة معزولة (اختبار القفل)', 'فارما-Q');
        SupplierProduct::create(['supplier_id' => $s10->id, 'product_id' => $pIsolatedDraft->id, 'unit_cost' => 1.00, 'last_price_updated_at' => now()]);
        $draftS10 = PurchaseOrder::create([
            'supplier_id'            => $s10->id,
            'status'                  => 'draft',
            'scenario'                => 'balanced',
            'forecast_period_days'   => 30,
            'generated_at'            => now()->subDay(),
            'total_estimated_cost'   => 15.00,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $draftS10->id,
            'product_id'         => $pIsolatedDraft->id,
            'suggested_qty'      => 15,
            'unit_cost'           => 1.00,
            'line_total_cost'    => 15.00,
        ]);

        // ══════════════════════════════════════════════════════════
        // فاتورة "استلام جزئي" لـ S11 — لاختبار زر "إلغاء المتبقي"
        // وأثره التلقائي على قنص نواقص السوق. ordered=100, received=60
        // → عجز متبقٍّ = 40
        // ══════════════════════════════════════════════════════════
        $pPartial = $makeProduct('TEST019', 'دواء استلام جزئي (اختبار إلغاء المتبقي)', 'فارما-R');
        SupplierProduct::create(['supplier_id' => $s11->id, 'product_id' => $pPartial->id, 'unit_cost' => 2.50, 'last_price_updated_at' => now()]);
        $poS11 = PurchaseOrder::create([
            'supplier_id'            => $s11->id,
            'status'                  => 'partially_received',
            'scenario'                => 'balanced',
            'forecast_period_days'   => 30,
            'generated_at'            => now()->subDays(12),
            'approved_at'              => now()->subDays(11),
            'approved_by'              => 'اختبار آلي',
            'total_estimated_cost'   => 250.00,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $poS11->id,
            'product_id'         => $pPartial->id,
            'suggested_qty'      => 100,
            'approved_qty'        => 100,
            'unit_cost'           => 2.50,
            'line_total_cost'    => 250.00,
            'ordered_qty'         => 100,
            'received_qty'       => 60,
        ]);
        InventoryBatch::create(['product_id' => $pPartial->id, 'quantity' => 60, 'received_at' => now()->subDays(10)->toDateString()]);

        // ══════════════════════════════════════════════════════════
        // فاتورة "معتمدة" لـ S12 — لاختبار زر "إلغاء كامل"
        // ══════════════════════════════════════════════════════════
        $pApproved = $makeProduct('TEST020', 'دواء بفاتورة معتمدة (اختبار الإلغاء الكامل)', 'فارما-S');
        SupplierProduct::create(['supplier_id' => $s12->id, 'product_id' => $pApproved->id, 'unit_cost' => 4.00, 'last_price_updated_at' => now()]);
        $poS12 = PurchaseOrder::create([
            'supplier_id'            => $s12->id,
            'status'                  => 'approved',
            'scenario'                => 'balanced',
            'forecast_period_days'   => 30,
            'generated_at'            => now()->subDays(3),
            'approved_at'              => now()->subDays(2),
            'approved_by'              => 'اختبار آلي',
            'total_estimated_cost'   => 80.00,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $poS12->id,
            'product_id'         => $pApproved->id,
            'suggested_qty'      => 20,
            'approved_qty'        => 20,
            'unit_cost'           => 4.00,
            'line_total_cost'    => 80.00,
        ]);

        // ══════════════════════════════════════════════════════════
        // فاتورة "مستلمة بالكامل" لـ S13 — ضبط سلبي (لا أزرار إلغاء
        // يجب أن تظهر، ولا تُحتسَب "قيد التوريد")
        // ══════════════════════════════════════════════════════════
        $pReceived = $makeProduct('TEST021', 'دواء بفاتورة مكتملة (ضبط سلبي)', 'فارما-T');
        SupplierProduct::create(['supplier_id' => $s13->id, 'product_id' => $pReceived->id, 'unit_cost' => 1.50, 'last_price_updated_at' => now()]);
        $poS13 = PurchaseOrder::create([
            'supplier_id'            => $s13->id,
            'status'                  => 'received',
            'scenario'                => 'balanced',
            'forecast_period_days'   => 30,
            'generated_at'            => now()->subDays(20),
            'approved_at'              => now()->subDays(19),
            'approved_by'              => 'اختبار آلي',
            'total_estimated_cost'   => 30.00,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $poS13->id,
            'product_id'         => $pReceived->id,
            'suggested_qty'      => 20,
            'approved_qty'        => 20,
            'unit_cost'           => 1.50,
            'line_total_cost'    => 30.00,
            'ordered_qty'         => 20,
            'received_qty'       => 20,
        ]);

        $this->command?->info('✅ تم إنشاء كل بيانات سيناريوهات الاختبار بنجاح — راجع اختبار-البيانات-والنتائج-المتوقعة.md للأرقام المتوقعة بالتفصيل.');
    }
}
