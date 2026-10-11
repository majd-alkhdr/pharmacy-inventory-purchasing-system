#!/bin/bash
# ══════════════════════════════════════════════════════════════════
# 🎯 سكربت تنظيف الملفات القديمة غير المستخدمة بعد إعادة هيكلة المشتريات
#
# ⚠️ شغّل هذا من جذر مشروع Laravel مباشرة (نفس مستوى مجلد app/).
# ⚠️ راجع القائمة أدناه قبل التشغيل — كل ملف هنا تأكّدتُ أنه لم يعد
#    مُستدعى من أي مكان بالكود بعد التعديلات (لا Controller، لا Service،
#    لا Route، لا View) — التحقق تم يدوياً بمراجعة كل نقطة استدعاء محتملة.
#
# ✅ ما لا يُحذف عمداً (بقرار صريح من صاحب المشروع، لعودة الأسعار/العروض
#    لاحقاً): SupplierProduct, SupplierOffer, SupplierProductController,
#    SupplierOfferController, BonusType, ProductSellingPrice (يبقى، فقط
#    شاشة الرفع المستقلة له هي التي تُحذف).
# ══════════════════════════════════════════════════════════════════

set -e

echo "🗑  حذف استراتيجيات السيناريوهات الثلاث القديمة (استُبدلت بـ PriorityBasedPurchaseDecisionService)..."
rm -f app/Services/Purchasing/Scenarios/RestockingOnlyScenarioService.php
rm -f app/Services/Purchasing/Scenarios/MaxProfitabilityScenarioService.php
rm -f app/Services/Purchasing/Scenarios/BalancedScenarioService.php
rm -f app/Services/Purchasing/Scenarios/PurchaseScenarioStrategyFactory.php
rm -f app/Services/Purchasing/Scenarios/PurchaseScenarioStrategy.php

echo "🗑  حذف محرّك تسعير الموردين القديم (لم يعد يُستدعى من أي استراتيجية)..."
rm -f app/Services/Purchasing/SupplierPricingResolver.php

echo "🗑  حذف شاشة 'أسعار البيع الرسمية' المستقلة (استُبدلت بعمود ضمن ملف جرد المشتريات)..."
rm -f app/Http/Controllers/Purchasing/SellingPriceController.php
rm -f app/Services/Purchasing/SellingPriceImportService.php
rm -f app/Imports/SellingPricesImport.php
rm -f app/Http/Requests/Purchasing/ImportSellingPricesRequest.php
rm -f resources/views/purchasing/selling-prices-import.blade.php
rm -f resources/views/purchasing/selling-prices-import-result.blade.php

echo ""
echo "✅ اكتمل التنظيف. تحقّق الآن يدوياً بالأمر التالي أنه لا يوجد أي استدعاء متبقٍّ:"
echo ""
echo "   grep -rn 'SellingPriceController\|SellingPriceImportService\|SellingPricesImport\|RestockingOnlyScenarioService\|MaxProfitabilityScenarioService\|BalancedScenarioService\|PurchaseScenarioStrategyFactory\|SupplierPricingResolver' app/ routes/ resources/"
echo ""
echo "لو ظهر أي سطر (غير تعليق توثيقي)، لا تُكمل قبل مراجعته."
