<?php

namespace Database\Seeders;

use App\Models\CentralInventory;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Pharmacy;
use App\Models\Product;
use App\Models\ProductPurchaseSetting;
use App\Models\Supplier;
use App\Models\SupplierCompany;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * 🎯 Seeder اختباري مخصَّص لإعادة هيكلة المشتريات (2026-08) — يغطي كل
 * سيناريو أُضيف حديثاً، بمعزل عن PurchasingScenariosSeeder القديم (الذي
 * يبقى بلا حذف ويغطي سيناريوهات الأسعار/العروض القديمة).
 *
 * ⚠️ نفس ملاحظة PurchasingScenariosSeeder: التواريخ هنا نسبية (subDays)
 * لضمان نظافة الحساب — أجرِ التحقق بنفس يوم تشغيل هذا الملف.
 *
 * php artisan db:seed --class=Database\\Seeders\\TurnoverAndPrioritySeeder
 */
class TurnoverAndPrioritySeeder extends Seeder
{
    public function run(): void
    {
        $pharmacy = Pharmacy::firstOrCreate(['name' => 'صيدلية اختبار الدوران']);

        // ══════════════════════════════════════════════════════════
        // موردان يتشاركان نفس الشركة عمداً — لاختبار شاشة "حل تعارض
        // الموردين" بالتفصيل (SupplierSelectionService::conflict).
        // ══════════════════════════════════════════════════════════
        $supplierA = Supplier::create(['name' => 'مورد تارجت-اختبار A', 'lead_time_days' => 7, 'is_active' => true]);
        $supplierB = Supplier::create(['name' => 'مورد تارجت-اختبار B', 'lead_time_days' => 5, 'is_active' => true]);
        $supplierSolo = Supplier::create(['name' => 'مورد منفرد — بلا تعارض', 'lead_time_days' => 6, 'is_active' => true]);

        SupplierCompany::create(['supplier_id' => $supplierA->id, 'company_name' => 'فارما-مشتركة']);
        SupplierCompany::create(['supplier_id' => $supplierB->id, 'company_name' => 'فارما-مشتركة']); // 🎯 نفس الشركة → تعارض
        SupplierCompany::create(['supplier_id' => $supplierSolo->id, 'company_name' => 'فارما-منفردة']);

        $makeProduct = fn (string $barcode, string $name, string $company) =>
            Product::create(['barcode' => $barcode, 'name' => $name, 'company' => $company]);

        $addSale = function (Product $product, int $daysAgo, int $qty) use ($pharmacy) {
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

        $addStockBatch = function (Product $product, int $qty) {
            CentralInventory::updateOrCreate(['product_id' => $product->id], ['quantity' => $qty]);
        };

        // ══════════════════════════════════════════════════════════
        // T1 — دوران عالٍ (< شهرين): رصيد 40، مبيعات أسبوعية 20
        // → (40/20)/4 = 0.5 شهر → HighTurnover
        // ══════════════════════════════════════════════════════════
        $t1 = $makeProduct('TURN001', 'دواء دوران عالٍ (اختبار)', 'فارما-منفردة');
        for ($i = 0; $i < 7; $i++) {
            $addSale($t1, $i, 20 / 7 < 1 ? 3 : (int) (20 / 7)); // توزيع تقريبي على 7 أيام مختلفة
        }
        $addStockBatch($t1, 40);

        // ══════════════════════════════════════════════════════════
        // T2 — راكد (> شهرين): رصيد 500، مبيعات أسبوعية 10
        // → (500/10)/4 = 12.5 شهر → Stagnant → يُستبعد بالكامل من الشراء
        // ══════════════════════════════════════════════════════════
        $t2 = $makeProduct('TURN002', 'دواء راكد (اختبار الاستبعاد)', 'فارما-منفردة');
        $addSale($t2, 3, 10);
        $addStockBatch($t2, 500);

        // ══════════════════════════════════════════════════════════
        // T3 — منتج جديد بلا مبيعات إطلاقاً، لكن تاريخ شراء حديث (5 أيام)
        // → weeklySales=0 لكن isNewlyPurchased()=true → TooNewToJudge
        // → لا يُستبعد، ولا يُعطى أولوية دوران عالٍ أيضاً (محايد)
        // ══════════════════════════════════════════════════════════
        $t3 = $makeProduct('TURN003', 'دواء جديد الشراء (اختبار المحايد)', 'فارما-منفردة');
        $t3->update(['last_purchase_date' => now()->subDays(5)]);
        $addStockBatch($t3, 15);

        // ══════════════════════════════════════════════════════════
        // T4 — بلا مبيعات وبلا تاريخ شراء حديث (30+ يوماً) → راكد فوراً
        // ══════════════════════════════════════════════════════════
        $t4 = $makeProduct('TURN004', 'دواء بلا مبيعات وقديم (اختبار الركود الفوري)', 'فارما-منفردة');
        $t4->update(['last_purchase_date' => now()->subDays(90)]);
        $addStockBatch($t4, 50);

        // ══════════════════════════════════════════════════════════
        // T5 — صنف تارجت + شركة متعارضة بين موردين (لاختبار شاشة الحل)
        // ══════════════════════════════════════════════════════════
        $t5 = $makeProduct('TURN005', 'دواء تارجت بشركة متعارضة (اختبار حل التعارض)', 'فارما-مشتركة');
        $addSale($t5, 2, 30);
        $addStockBatch($t5, 10);
        ProductPurchaseSetting::create(['product_id' => $t5->id, 'is_target' => true]);

        // ══════════════════════════════════════════════════════════
        // T6 — نقص حرج: رصيد صفري تماماً، مبيعات جيدة
        // ══════════════════════════════════════════════════════════
        $t6 = $makeProduct('TURN006', 'دواء نقص حرج (اختبار الأولوية)', 'فارما-منفردة');
        $addSale($t6, 1, 25);
        // ⚠️ عمداً: بلا أي دفعة مخزون — رصيد صفري

        $this->command?->info('✅ تم إنشاء بيانات اختبار الدوران والأولوية والتعارض بنجاح.');
        $this->command?->info('   TURN001=دوران عالٍ | TURN002=راكد(يُستبعد) | TURN003=جديد محايد');
        $this->command?->info('   TURN004=راكد فوراً | TURN005=تارجت+تعارض موردين | TURN006=نقص حرج');
    }
}
