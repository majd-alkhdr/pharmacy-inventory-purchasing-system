<?php

namespace Database\Seeders;

use App\Models\Pharmacy;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use Illuminate\Database\Seeder;

/**
 * 🎯 يطبّق طلب المستخدم مباشرة: "أريد اختباراً للنظام كاملاً من لحظة
 * رفع ملف مبيعات + جرد، إلى توليد نواقص، إلى الاستفادة منها بتوليد
 * فواتير الشراء". لهذا هذا الـ Seeder **لا** يُنشئ أي فاتورة أو نقص أو
 * جرد مباشرة — فقط "الحد الأدنى" الذي يحتاجه أي نظام حقيقي أول مرة
 * يُستخدَم: المنتجات المعروفة (بأسمائها وباركوداتها)، والمورد المرتبط
 * بالمادة النادرة لاحقاً. كل شيء آخر (المبيعات، الجرد، النواقص، الفواتير
 * الإلحاقية، فاتورة الشراء، الاستلام) يتولّد من رفعك فعلياً لملفات
 * test-inventory-day1.csv وtest-sales-day1.csv... الخ عبر الواجهة —
 * تماماً كما يعمل النظام مع عميل حقيقي أول يوم تشغيل.
 *
 * ⚠️ لاحظ عمداً: TESTE04NEW **غير موجود هنا بجدول products** — هذا
 * مقصود بالكامل لاختبار مسار "مواد جديدة بالجرد" الحقيقي (شاشة
 * pending-inventory) بدل تجاوزه.
 *
 * php artisan db:seed --class=Database\\Seeders\\EndToEndBaseSeeder
 */
class EndToEndBaseSeeder extends Seeder
{
    public function run(): void
    {
        // 🎯 firstOrCreate: قد تكون هذه الصيدلية موجودة أصلاً لو شغّلت
        // PurchasingScenariosSeeder قبلها بنفس القاعدة — لا نريد خطأ
        // تكرار unique.
        Pharmacy::firstOrCreate(['name' => 'صيدلية الاختبار المركزية']);

        Product::create(['barcode' => 'TESTE01', 'name' => 'دواء اختبار الدورة الكاملة - ترميم فوري', 'company' => 'فارما-E2E1']);
        Product::create(['barcode' => 'TESTE02', 'name' => 'دواء اختبار الدورة الكاملة - نقص وترميم جزئي', 'company' => 'فارما-E2E1']);
        $e3 = Product::create(['barcode' => 'TESTE03', 'name' => 'دواء اختبار الدورة الكاملة - نادر ذو حركة جيدة', 'company' => 'فارما-E2E2']);

        // 🆕 مورد مخصَّص ومعزول تماماً لاختبار "قنص نواقص السوق" عبر
        // مسار حقيقي كامل (وليس إدخالاً مباشراً بقاعدة البيانات كما
        // فعلنا بـ PurchasingScenariosSeeder) — بلا أي تشابك مع
        // موردين آخرين حتى لا يتأثر بأي قفل مسودة من اختبارات أخرى.
        $supplierE3 = Supplier::create(['name' => 'مورد نادر - اختبار قنص السوق الحقيقي', 'lead_time_days' => 7, 'is_active' => true]);

        SupplierProduct::create([
            'supplier_id'            => $supplierE3->id,
            'product_id'              => $e3->id,
            'unit_cost'                => 4.00,
            'last_price_updated_at'  => now(),
        ]);

        $this->command?->info('✅ تم إنشاء الأساس (منتجات + مورد) لاختبار الدورة الكاملة. الآن ارفع الملفات بالترتيب الموضَّح بدليل-اختبار-الدورة-الكاملة.md');
    }
}
