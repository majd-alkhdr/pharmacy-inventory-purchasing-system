<?php

declare(strict_types=1);

namespace App\Services\Inventory\Transfers;

/**
 * 🎯 نتيجة تنفيذ مستند مناقلة (عكسية أو بين صيدليتين): كم بنداً نُفِّذ، وكم أُلغي تلقائياً
 * لأن فائضه التُزم به لمناقلة أخرى بنفس الدورة (الإلغاء المتبادل). لا حالة "جزئي" لكمية
 * بند — البند إما يُنفَّذ بكامل كميته أو يُلغى (لا تنفيذ جزئي).
 */
final class TransferExecutionOutcome
{
    public function __construct(
        public readonly int $documentId,
        public readonly int $executedCount,
        public readonly int $supersededCount,
    ) {
    }

    public function wasFullyExecuted(): bool
    {
        return $this->supersededCount === 0;
    }

    public function message(): string
    {
        return $this->wasFullyExecuted()
            ? "✅ نُفِّذت المناقلة #{$this->documentId} بالكامل ({$this->executedCount} صنف)، وحُجز فائضها — لن يُقترح مجدداً بهذه الدورة."
            : "⚠️ نُفِّذت المناقلة #{$this->documentId}: {$this->executedCount} صنف نُفِّذ، و{$this->supersededCount} صنف أُلغي تلقائياً لأن فائضه التُزم به لمناقلة أخرى بنفس الدورة.";
    }
}
