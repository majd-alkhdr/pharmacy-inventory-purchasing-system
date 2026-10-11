<?php

namespace App\Services\Purchasing;

use App\Models\Shortage;
use Illuminate\Support\Collection;

/**
 * 🎯 يسدّ الفجوة المكتشَفة أثناء اختبار الدورة الكاملة: محرك توليد فواتير
 * الشراء كان يتجاهل تماماً جدول shortages (النواقص الفعلية المسجَّلة عند
 * عجز صيدلية عن الحصول على طلبها من central_inventories بقسم المناقلات)،
 * ويعتمد فقط على تنبؤ مبني على معدل الاستهلاك التاريخي + رصيد المشتريات.
 *
 * 🎓 لماذا هذا الفرق مهم؟ منتج قد يكون رصيد المشتريات له موجباً (فلا
 * يُصنَّف "نقص حرج" بمعادلة الاحتياج)، بينما تعاني الصيدليات فعلياً من
 * عجز حقيقي غير محلول — لأن الرصيدين (رصيد المشتريات المركزي بقسم
 * المشتريات، ورصيد التوزيع للصيدليات بقسم المناقلات) مفهومان منفصلان
 * تماماً بهذا النظام. جدول shortages هو "الدليل الميداني المؤكَّد" الوحيد
 * على وجود عجز حقيقي بالفعل، بخلاف كل الإشارات الأخرى (تارجت/نقص حرج/
 * دوران) وهي جميعها استنتاجات محسوبة أو قرارات استراتيجية.
 *
 * بقرار صريح من صاحب المشروع: "لها الأولوية لأنها نواقص حقيقية يعجز
 * الجرد عن تلبيتها" — لذلك تحمل أعلى أولوية إدراج ممكنة بمحرك القرار
 * (راجع PurchaseLineCandidate::priorityScore())، **و** تتجاوز استبعاد
 * "الركود" أيضاً (راجع PurchaseEligibilityFilter) — منطقياً، منتج تنتظره
 * صيدلية فعلياً بعجز حقيقي لا يمكن اعتباره "راكداً" مهما بدا رقم دورانه.
 *
 * 🩹 قرار أداء: استعلام واحد فقط بالمجمل (يُخزَّن مؤقتاً بالذاكرة طوال
 * عمر هذا الكائن)، بدل استعلام منفصل لكل منتج — بنفس فلسفة
 * CrossSupplierOverlapDetector تماماً.
 *
 * ⚠️ قرار مقصود ومحدود النطاق: هذه الإشارة تؤثر فقط على (1) الأولوية
 * و(2) تجاوز استبعاد الركود — **لا تُغيِّر معادلة حساب الكمية المطلوبة
 * نفسها** (تلك بقيت مجمَّدة كما أكَّدها صاحب المشروع صراحة). الحالة
 * النادرة "احتياج محسوب = صفر رغم وجود نقص صيدلية معلَّق" تبقى مُستبعَدة
 * حالياً (خارج نطاق هذا التعديل) — قرار مستقبلي منفصل إن احتجتم معالجتها.
 */
class PendingPharmacyShortageDetector
{
    private ?Collection $pendingQuantitiesByProduct = null;

    public function hasPendingShortage(int $productId): bool
    {
        return $this->loadPendingQuantities()->has($productId);
    }

    /** 🎯 مجموع الكمية المعلَّقة (Pending) لهذا المنتج عبر كل الصيدليات معاً */
    public function pendingQuantityFor(int $productId): int
    {
        return (int) $this->loadPendingQuantities()->get($productId, 0);
    }

    private function loadPendingQuantities(): Collection
    {
        return $this->pendingQuantitiesByProduct ??= Shortage::pending()
            ->selectRaw('product_id, SUM(quantity) as total_quantity')
            ->groupBy('product_id')
            ->pluck('total_quantity', 'product_id');
    }
}
