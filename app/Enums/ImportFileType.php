<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 🎯 نوع الملف المرفوع — مفتاح سجل الرفع الموحَّد (ImportLog). كل شاشة رفع
 * بالقسمين (المناقلات والمشتريات) تُسجِّل نفسها بقيمة واحدة من هنا، فيُجاب عن
 * سؤال "هل نجح رفع ملف X؟ متى؟ من رفعه؟" من جدول واحد بلا استثناء.
 *
 * 🎓 لماذا Enum لا نص حر بكل كونترولر؟ نفس سبب ImportWarning/ShortageSource:
 * النص الحر يتشتّت ("matrix" هنا، "warehouse-matrix" هناك) فيصبح البحث
 * بالسجل غير موثوق. إضافة نوع ملف جديد = حالة جديدة هنا + label()، صفر تعديل
 * على خدمة السجل نفسها (Open/Closed).
 */
enum ImportFileType: string
{
    // ── قسم المناقلات ──
    case WarehouseMatrix = 'warehouse_matrix';
    case MaterialMaster = 'material_master';
    case PharmacyMinimumStock = 'pharmacy_minimum_stock';
    case CentralInventory = 'central_inventory';
    case PharmacyInventory = 'pharmacy_inventory';
    case Sales = 'sales';
    case Shortages = 'shortages';
    case WatchedProducts = 'watched_products';
    case TransferPreparation = 'transfer_preparation';

    // ── قسم المشتريات ──
    case PurchaseInventory = 'purchase_inventory'; // ⚠️ أُلغي (2026-10) — يبقى فقط لعرض سجلات الرفع القديمة بسجل الرفع.
    case PurchasingMinimumStock = 'purchasing_minimum_stock';
    case ManualSalesRates = 'manual_sales_rates';
    case CategoriesMaster = 'categories_master';
    case CategoryLinks = 'category_links';
    case HistoricalPrices = 'historical_prices';
    case SupplierCompanyLinks = 'supplier_company_links';
    case PurchaseReceipt = 'purchase_receipt';

    public function label(): string
    {
        return match ($this) {
            self::WarehouseMatrix => 'كشف المستودعات (الكشف التفصيلي)',
            self::MaterialMaster => 'ملف الأصناف (بيانات المواد)',
            self::PharmacyMinimumStock => 'المخزون القياسي للصيدليات',
            self::CentralInventory => 'جرد المستودع المركزي',
            self::PharmacyInventory => 'جرد صيدلية',
            self::Sales => 'المبيعات',
            self::Shortages => 'النواقص التاريخية',
            self::WatchedProducts => 'أصناف المراقبة',
            self::TransferPreparation => 'مطابقة تحضير مناقلة',
            self::PurchaseInventory => 'جرد المشتريات',
            self::PurchasingMinimumStock => 'الحد الأدنى (مشتريات)',
            self::ManualSalesRates => 'معدلات المبيعات اليدوية',
            self::CategoriesMaster => 'قائمة التصنيفات',
            self::CategoryLinks => 'ربط المواد بالتصنيفات',
            self::HistoricalPrices => 'الأسعار التاريخية للموردين',
            self::SupplierCompanyLinks => 'ربط الموردين بالشركات',
            self::PurchaseReceipt => 'استلام فاتورة شراء',
        };
    }

    /**
     * هل نجاح رفع هذا النوع يبدأ "دورة رفع" جديدة لمخزون الصيدليات؟
     *
     * 🎯 هذا هو تعريف "الدورة" الذي تعتمد عليه خدمة الفوائض المشتركة (SurplusEngine):
     * فقط الملفان اللذان **يستبدلان لقطة pharmacy_inventories** يُصفّران التزامات
     * الفائض — لأن الفائض نفسه مشتق من تلك اللقطة. رفع المخزون القياسي مثلاً يغيّر
     * الفائض المحسوب لكنه لا يعكس حركة بضاعة فعلية، فلا يُلغي التزاماً قائماً.
     */
    public function startsInventoryCycle(): bool
    {
        return $this === self::WarehouseMatrix || $this === self::PharmacyInventory;
    }

    /** @return list<self> */
    public static function inventoryCycleTypes(): array
    {
        return array_values(array_filter(self::cases(), fn (self $type): bool => $type->startsInventoryCycle()));
    }
}
