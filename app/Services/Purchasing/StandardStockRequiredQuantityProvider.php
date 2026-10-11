<?php

namespace App\Services\Purchasing;

use App\Models\ProductManualSalesRate;
use App\Services\Shared\Stock\CentralStockReader;
use Illuminate\Support\Collection;

/**
 * 🎯 نمط "تحقيق المخزون القياسي للمستودع" — نفس شكل معادلة
 * DemandCalculationService القديمة بالضبط (forecastNeedQty = ceil(معدّل×مدة)،
 * ثم طرح الرصيد والقادم)، لكن بمصدر معدّل استهلاك مختلف كلياً: بدل
 * ConsumptionRateCalculator (فواتير حقيقية)، من ProductManualSalesRate
 * (رفعة يدوية). لا حد أدنى أمان يُقارَن — أُزيل عمداً مع تجميد المحرك
 * القديم بالكامل.
 *
 * الرصيد يُجلَب دفعة واحدة (نفس نمط TurnoverCalculator::bulkCalculate()
 * حرفياً) بدل Product::usableStockQuantity() المفردة — تفادياً لاستعلام
 * منفصل لكل منتج.
 *
 * 🆕 مصدر الرصيد = رصيد المستودع المركزي من ملف المصفوفة (CentralStockReader)، لا inventory_batches
 * (ملف جرد المشتريات، أُلغي) — نفس الرقم المعروض بعمود "المتوفر بالصناعة" بتفاصيل الفاتورة.
 */
class StandardStockRequiredQuantityProvider implements RequiredQuantityProvider
{
    public function __construct(
        private readonly int $forecastPeriodDays,
        private readonly OpenPurchaseOrderQuantityService $openOrderQuantityService,
        private readonly CentralStockReader $centralStock = new CentralStockReader(),
    ) {}

    public function bulkCalculate(Collection $products): array
    {
        $productIds = $products->pluck('id');

        $ratesByProduct = ProductManualSalesRate::whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');

        $stockByProduct = $this->centralStock->quantitiesFor($productIds);


        $result = [];

        foreach ($products as $product) {
            $rate = $ratesByProduct->get($product->id);

            if (!$rate) {
                $result[$product->id] = new RequiredQuantityResult(
                    productId: $product->id,
                    requiredQty: 0,
                    explanationSummary: 'لا يوجد معدّل مبيعات يدوي مرفوع لهذه المادة بعد.',
                    explanationFactors: ['source' => 'standard_stock', 'has_manual_rate' => false],
                );
                continue;
            }

            $dailyRate = $rate->dailyRate();
            $forecastNeedQty = (int) ceil($dailyRate * $this->forecastPeriodDays);
            $currentStock = (int) ($stockByProduct[$product->id] ?? 0);

            // ⚠️ إعادة استخدام مباشرة لـOpenPurchaseOrderQuantityService كما
            // هي — بلا إعادة بناء موازية لمنطقها (outstandingQuantity يتعامل
            // بدقة مع حالات ordered_qty الفارغ وcancelled_qty، تكراره هنا
            // مخاطرة اختلاف سلوك لا داعي لها). هذا يبقي استعلاماً منفصلاً
            // لكل منتج هنا تحديداً — قرار واعٍ لصالح صحّة الحساب، لا إغفالاً.
            $openQty = $this->openOrderQuantityService->forProduct($product->id);

            $requiredQty = max(0, $forecastNeedQty - $currentStock - $openQty);

            $result[$product->id] = new RequiredQuantityResult(
                productId: $product->id,
                requiredQty: $requiredQty,
                explanationSummary: sprintf(
                    'معدّل مبيعات يدوي: %.2f وحدة/يوم (من %d وحدة خلال %d يوماً). الاحتياج المتوقّع لـ%d يوم: %d وحدة. '
                        . 'الرصيد الحالي: %d وحدة، قيد التوريد: %d وحدة. الكمية المطلوبة: %d وحدة.',
                    $dailyRate,
                    $rate->sales_quantity,
                    $rate->period_days,
                    $this->forecastPeriodDays,
                    $forecastNeedQty,
                    $currentStock,
                    $openQty,
                    $requiredQty,
                ),
                explanationFactors: [
                    'source'                   => 'standard_stock',
                    'daily_rate'               => $dailyRate,
                    'sales_quantity'           => $rate->sales_quantity,
                    'period_days'              => $rate->period_days,
                    'forecast_period_days'     => $this->forecastPeriodDays,
                    'forecast_need_qty'        => $forecastNeedQty,
                    'current_stock_qty'        => $currentStock,
                    'open_purchase_order_qty'  => $openQty,
                    'required_qty'             => $requiredQty,
                ],
            );
        }

        return $result;
    }
}
