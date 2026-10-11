<?php
// app/Services/Inventory/RestockingResult.php

namespace App\Services\Inventory;

use App\Enums\ShortageSource;

final class RestockingResult
{
    /**
     * @param array<string, int> $perPairSupplied
     * @param array<int, array<int, array{item_id:int, quantity:int, pharmacy_name:string}>> $reverseTransferSources
     */
    public function __construct(
        public readonly ?ShortageSource $source = null,
        public readonly int $pairsEvaluated = 0,
        public readonly int $invoicesCreated = 0,
        public readonly int $fulfilledCount = 0,
        public readonly int $partialCount = 0,
        public readonly int $shortagesRecorded = 0,
        public readonly int $shortagesResolved = 0,
        public readonly int $reverseTransfersCreated = 0,
        // 🆕 [تشخيص] لماذا زوج (صيدلية+مادة) باع شيئاً بهذه الدفعة **لم يُقيَّم إطلاقاً**؟
        // خاصة بالوضع الرابع (SalesGatedStandardInventoryRestockingStrategy) حالياً،
        // لكن الحقلان عامّان (0 افتراضياً لبقية الاستراتيجيات) — بلا هذين الحقلين، كان
        // "صفر فواتير" يظهر برسالة عامة غير مُفسَّرة ("لا توجد بيانات كافية") حتى لو
        // السبب الحقيقي معروفاً تماماً وقابلاً للعرض (بيانات ناقصة تحديداً، لا غيابها).
        public readonly int $skippedNoBaseline = 0,
        public readonly int $skippedNoStandard = 0,
        // 🆕 [تشخيص إضافي] فجوة حقيقية موجودة (بعد رصد بيع فعلي) لكن قُمعت بصمت:
        // إما ضمن حد التسامح (min_reorder_gap)، أو أصغر من عبوة كاملة واحدة
        // (pack_size) فتقرَّبت لصفر. راجع تعليق ParLevelCalculator للفلسفة
        // الكاملة وراء هذا السلوك نفسه (قرار عمل أصلي موروث، لا خطأ) — هذان
        // الحقلان فقط يجعلانه **مرئياً** للمستخدم بدل صمت تام بلا أي أثر.
        public readonly int $skippedGapBelowTolerance = 0,
        public readonly int $skippedGapBelowPackSize = 0,
        public readonly array $perPairSupplied = [],
        public readonly array $reverseTransferSources = [], // 🆕
        public readonly ?string $message = null,
    ) {}

    public static function empty(?ShortageSource $source = null): self
    {
        return new self(source: $source);
    }

    public function mergeWith(self $other): self
    {
        $mergedPairs = $this->perPairSupplied;
        foreach ($other->perPairSupplied as $key => $qty) {
            $mergedPairs[$key] = ($mergedPairs[$key] ?? 0) + $qty;
        }

        // 🆕 دمج بسيط (تجميع القوائم) — كل عنصر يحمل item_id فريداً أصلاً، لا حاجة إزالة تكرار
        $mergedSources = $this->reverseTransferSources;
        foreach ($other->reverseTransferSources as $productId => $items) {
            $mergedSources[$productId] = array_merge($mergedSources[$productId] ?? [], $items);
        }

        return new self(
            source: null,
            pairsEvaluated: $this->pairsEvaluated + $other->pairsEvaluated,
            invoicesCreated: $this->invoicesCreated + $other->invoicesCreated,
            fulfilledCount: $this->fulfilledCount + $other->fulfilledCount,
            partialCount: $this->partialCount + $other->partialCount,
            shortagesRecorded: $this->shortagesRecorded + $other->shortagesRecorded,
            shortagesResolved: $this->shortagesResolved + $other->shortagesResolved,
            reverseTransfersCreated: $this->reverseTransfersCreated + $other->reverseTransfersCreated,
            skippedNoBaseline: $this->skippedNoBaseline + $other->skippedNoBaseline, // 🆕
            skippedNoStandard: $this->skippedNoStandard + $other->skippedNoStandard, // 🆕
            skippedGapBelowTolerance: $this->skippedGapBelowTolerance + $other->skippedGapBelowTolerance, // 🆕
            skippedGapBelowPackSize: $this->skippedGapBelowPackSize + $other->skippedGapBelowPackSize, // 🆕
            perPairSupplied: $mergedPairs,
            reverseTransferSources: $mergedSources, // 🆕
        );
    }
}
