<?php

declare(strict_types=1);

namespace App\Services\Inventory;

/**
 * 🎯 فرصة مناقلة مباشرة بين صيدليتين لنفس المادة — قبل أي كتابة لقاعدة
 * البيانات (راجع تعليق InterPharmacyTransferPlanner). يحمل لقطة السبب
 * (فائض المُرسِلة، فجوة المستقبِلة وقت الرصد) ليُفهم القرار لاحقاً حتى لو
 * تغيّرت الأرصدة بين لحظة الاقتراح ولحظة المراجعة البشرية.
 */
final class TransferSuggestion
{
    public function __construct(
        public readonly int $productId,
        public readonly int $fromPharmacyId,
        public readonly int $toPharmacyId,
        public readonly int $quantity,
        public readonly int $fromPharmacyExcessAtTime,
        public readonly int $toPharmacyGapAtTime,
    ) {
    }
}
