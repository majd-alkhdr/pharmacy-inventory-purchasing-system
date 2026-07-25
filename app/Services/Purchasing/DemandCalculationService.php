<?php

namespace App\Services\Purchasing;

use App\Models\Product;

/**
 * 🎯 "محرك الاحتياج" (Demand Engine) الموصوف بقسم 4 من مستندك، بالمعادلة
 * الحرفية التي كتبتها:
 *
 *   (الاستهلاك اليومي × المدة المحددة) - (الرصيد الحالي + الطلبيات قيد التوريد)
 *   = الكمية المطلوبة للترميم
 *
 *   ثم: يقارن النظام النتيجة مع الحد الأدنى للمادة (يدوي أو ديناميكي)
 *   لضمان عدم الهبوط دونه.
 *
 * 🎓 هذا الصنف "منسّق بحت" (Orchestrator) بنفس نمط InventoryUploadService
 * بالضبط: لا يحسب شيئاً بنفسه مباشرة، بل يستدعي خدمتين متخصصتين
 * (ConsumptionRateCalculator, OpenPurchaseOrderQuantityService) عبر
 * الـ Constructor، ويُركّب نتائجهما بمعادلة واحدة واضحة.
 */
class DemandCalculationService
{
    /**
     * 🎯 قيمة افتراضية تُستخدم *فقط* إن لم يحدد المشرف safety_stock_days
     * يدوياً لهذه المادة بالذات بجدول product_purchase_settings. رقم 14
     * يوماً قابل للنقاش — اعتبره Placeholder معقولاً بانتظار قرار عمل
     * منك، وليس رقماً "صحيحاً" مطلقاً. لو احتجت لاحقاً جعله قابلاً
     * للتعديل من واجهة إعدادات النظام بدل ثابت بالكود، هذا تحسين بسيط
     * (عمود بجدول إعدادات عام) لن يكسر أي شيء بهذا التصميم.
     */
    private const DEFAULT_SAFETY_STOCK_DAYS = 14;

    public function __construct(
        private readonly ConsumptionRateCalculator $consumptionRateCalculator,
        private readonly OpenPurchaseOrderQuantityService $openOrderQuantityService,
    ) {
    }

    public function calculateFor(Product $product, int $forecastPeriodDays): ProductDemandCalculation
    {
        $dailyRate = $this->consumptionRateCalculator->dailyRateFor($product->id);
        $forecastNeedQty = (int) ceil($dailyRate * $forecastPeriodDays);

        [$safetyStockQty, $usedManualMinimum] = $this->resolveSafetyStock($product, $dailyRate);

        // 🎯 "يقارن النظام النتيجة مع الحد الأدنى" — الهدف النهائي للمخزون
        // هو الأعلى بين احتياج التنبؤ والحد الأدنى الآمن، وليس التنبؤ فقط.
        $targetStockQty = max($forecastNeedQty, $safetyStockQty);

        $currentUsableStockQty = $product->usableStockQuantity();
        $openPurchaseOrderQty = $this->openOrderQuantityService->forProduct($product->id);

        $requiredQty = max(0, $targetStockQty - ($currentUsableStockQty + $openPurchaseOrderQty));

        return new ProductDemandCalculation(
            productId: $product->id,
            dailyConsumptionRate: $dailyRate,
            forecastPeriodDays: $forecastPeriodDays,
            forecastNeedQty: $forecastNeedQty,
            safetyStockQty: $safetyStockQty,
            usedManualMinimum: $usedManualMinimum,
            currentUsableStockQty: $currentUsableStockQty,
            openPurchaseOrderQty: $openPurchaseOrderQty,
            requiredQty: $requiredQty,
        );
    }

    /**
     * @return array{0: int, 1: bool} [الحد_الأدنى, هل_يدوي؟]
     */
    private function resolveSafetyStock(Product $product, float $dailyRate): array
    {
        $setting = $product->purchaseSetting;

        if ($setting && $setting->manual_minimum_stock !== null) {
            return [$setting->manual_minimum_stock, true];
        }

        $safetyDays = $setting?->safety_stock_days ?? self::DEFAULT_SAFETY_STOCK_DAYS;

        return [(int) ceil($dailyRate * $safetyDays), false];
    }
}
