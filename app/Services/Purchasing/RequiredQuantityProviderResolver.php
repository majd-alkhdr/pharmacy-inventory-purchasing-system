<?php

namespace App\Services\Purchasing;

/** 🎯 يبني مزوِّد "الكمية المطلوبة" الصحيح حسب اختيار المشرف — نقطة واحدة تعرف كل الأنماط */
class RequiredQuantityProviderResolver
{
    public function __construct(
        private readonly OpenPurchaseOrderQuantityService $openOrderQuantityService,
    ) {}

    public function resolve(GeneratePurchaseOrdersParams $params): RequiredQuantityProvider
    {
        $providers = [];

        if ($params->generationMode->includesShortages()) {
            $providers[] = new ShortageBasedRequiredQuantityProvider($params->shortageSourceFilter);
        }

        if ($params->generationMode->includesStandardStock()) {
            $providers[] = new StandardStockRequiredQuantityProvider($params->forecastPeriodDays, $this->openOrderQuantityService);
        }

        return count($providers) === 1 ? $providers[0] : new CompositeRequiredQuantityProvider($providers);
    }
}
