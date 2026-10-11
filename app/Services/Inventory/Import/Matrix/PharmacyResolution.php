<?php

declare(strict_types=1);

namespace App\Services\Inventory\Import\Matrix;

/**
 * 🎯 نتيجة مطابقة أعمدة الصيدليات بالصيدليات المسجَّلة — قرار كامل بلا آثار جانبية.
 *
 * ثلاث حالات فقط لكل عمود: مُطابَق بثقة، أو مجهول، أو غامض (اسمان مسجَّلان بنفس
 * المفتاح). الحالتان الأخيرتان **لا تُحسمان بالتخمين** — تُعرَضان على المستخدم
 * (شاشة المطابقة الموجودة) لأن الخطأ هنا صامت وخطير: كمية صيدلية تُكتب على صيدلية
 * أخرى، أو صيدلية وهمية تُنشأ من خطأ إملائي فلا يُرمَّم شيء لها.
 */
final class PharmacyResolution
{
    /**
     * @param array<int, int>       $resolved  فهرس العمود ← معرّف الصيدلية
     * @param array<int, string>    $unresolved فهرس العمود ← الرأس (لا صيدلية مسجَّلة بهذا الاسم)
     * @param array<int, list<int>> $ambiguous  فهرس العمود ← معرّفات صيدليات متعددة تتطابق معه
     * @param array<int, list<int>> $conflicts  معرّف الصيدلية ← أعمدة متعددة تشير إليها
     * @param array<int, string>    $headers    فهرس العمود ← الرأس الأصلي (للرسائل)
     */
    public function __construct(
        public readonly array $resolved,
        public readonly array $unresolved,
        public readonly array $ambiguous,
        public readonly array $conflicts,
        public readonly array $headers,
    ) {
    }

    /** هل يمكن الكتابة الآن؟ (كل عمود له صيدلية واحدة واضحة، ولا عمودين لنفس الصيدلية) */
    public function isComplete(): bool
    {
        return $this->unresolved === [] && $this->ambiguous === [] && $this->conflicts === [];
    }

    /** هل تحتاج الأعمدة قراراً بشرياً؟ (بخلاف التعارض الذي هو خطأ بالملف نفسه) */
    public function needsManualMapping(): bool
    {
        return $this->unresolved !== [] || $this->ambiguous !== [];
    }

    /** @return list<int> */
    public function pharmacyIds(): array
    {
        return array_values(array_unique($this->resolved));
    }

    /**
     * الرؤوس التي يجب عرضها على المستخدم (المجهولة + الغامضة) بترتيب أعمدتها.
     *
     * @return list<string>
     */
    public function headersNeedingMapping(): array
    {
        $indexes = array_unique([...array_keys($this->unresolved), ...array_keys($this->ambiguous)]);
        sort($indexes);

        return array_map(fn (int $index): string => $this->headers[$index], $indexes);
    }
}
