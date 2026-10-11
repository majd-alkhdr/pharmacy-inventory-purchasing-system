<?php

declare(strict_types=1);

namespace App\Services\Inventory\Import\Support;

use App\Enums\ImportWarning;

/**
 * 🎯 مُجمِّع تحذيرات الاستيراد — ينفّذ قرارك: "السالب يصبح 0 مع عدّاد تحذير
 * ظاهر، لا يُتجاهل بصمت". يُنشأ واحد لكل عملية رفع، يمرّ به المُحلِّل أثناء
 * القراءة، ثم يُحفظ بجلسة الاستيراد كـ JSON ويُعرض كبطاقة.
 *
 * 🎓 لماذا كائن بيانات منظَّم وليس نصاً يُضاف لـ message؟
 * حقل message الحالي سلسلة واحدة تُبنى بالتسلسل (راجع InventoryUploadService:
 * ".= ..." بعدة أماكن). كشفك الفعلي فيه 410 خلية سالبة و2,882 كسرية — لا رسالة
 * نصية تتسع لذلك، ولا تقبل الفرز حسب الصيدلية. هنا: عدّادات (للملخص) + عيّنات
 * بأرقام صفوفها (للتفاصيل القابلة للتنزيل) + تجميع حسب الموقع (لتعرف أي
 * صيدلية جرودها أسوأ).
 *
 * ⚖️ سقف العيّنات (MAX_SAMPLES_PER_WARNING): العدّاد دقيق دائماً، أما التفاصيل
 * فمحدودة. ملف تالف بالكامل (مليون خلية سالبة) لا يجب أن يملأ عمود JSON
 * بمئات الميغابايتات — والمستخدم يحتاج رؤية النمط لا كل حالة.
 *
 * ⚠️ حالة قابلة للتغيّر (Mutable) عمداً: مُجمِّع (Accumulator) بطبيعته، يعيش
 * طوال عملية استيراد واحدة ولا يُشارَك بين عمليات. لذلك ليس DTO مجمَّداً.
 */
final class ImportDiagnostics
{
    public const MAX_SAMPLES_PER_WARNING = 500;

    private const TOP_BUCKETS_IN_SUMMARY = 3;

    /** @var array<string, int> */
    private array $counts = [];

    /** @var array<string, list<array<string, int|float|string>>> */
    private array $samples = [];

    /**
     * تجميع فرعي لكل تحذير (سالب ← حسب الصيدلية، متخطَّى ← حسب السبب...).
     *
     * @var array<string, array<string, int>>
     */
    private array $buckets = [];

    public function recordNegativeQuantity(int $row, string $barcode, string $location, int|float $rawValue): void
    {
        $this->record(
            ImportWarning::NegativeQuantity,
            ['row' => $row, 'barcode' => $barcode, 'location' => $location, 'value' => $rawValue],
            $location,
        );
    }

    public function recordFractionalQuantity(int $row, string $barcode, string $location, int|float $rawValue, int $storedAs): void
    {
        $this->record(
            ImportWarning::FractionalQuantity,
            ['row' => $row, 'barcode' => $barcode, 'location' => $location, 'value' => $rawValue, 'stored_as' => $storedAs],
            $location,
        );
    }

    /** خانة كمية لا يمكن تفسيرها (نص مثل "N/A"، أو رقم خارج المدى الذي يتسع له العمود) — عُدّت صفراً. */
    public function recordInvalidQuantity(int $row, string $barcode, string $location, string $rawValue): void
    {
        $this->record(
            ImportWarning::InvalidQuantity,
            ['row' => $row, 'barcode' => $barcode, 'location' => $location, 'value' => $rawValue],
            $location,
        );
    }

    /** خانة اختيارية بملف بيانات المواد (سعر/حجم عبوة/حد أدنى للفرق) لا تُفسَّر رقماً — تُركت بلا تغيير. */
    public function recordInvalidValue(int $row, string $barcode, string $field, string $rawValue): void
    {
        $this->record(
            ImportWarning::InvalidValue,
            ['row' => $row, 'barcode' => $barcode, 'field' => $field, 'value' => $rawValue],
            $field,
        );
    }

    public function recordDuplicateBarcode(int $row, string $barcode): void
    {
        $this->record(ImportWarning::DuplicateBarcode, ['row' => $row, 'barcode' => $barcode]);
    }

    public function recordSkippedRow(int $row, string $reason): void
    {
        $this->record(ImportWarning::SkippedRow, ['row' => $row, 'reason' => $reason], $reason);
    }

    public function recordStrayCell(int $row, string $columnLabel): void
    {
        $this->record(ImportWarning::StrayCell, ['row' => $row, 'column' => $columnLabel], $columnLabel);
    }

    /**
     * 🆕 (أ.4) رمز مادة بملف لا يعرفه كتالوج المواد (المُغذّى من ملف الأصناف). التجميع
     * حسب الملف المصدر لأن نفس الفحص يُشغَّل من أكثر من مستورد.
     */
    public function recordUnknownProductCode(int $row, string $barcode, string $sourceFile): void
    {
        $this->record(ImportWarning::UnknownProductCode, ['row' => $row, 'barcode' => $barcode, 'file' => $sourceFile], $sourceFile);
    }

    /** 🆕 (أ.4) الاتجاه المعاكس: مادة بملف الأصناف لم تظهر بآخر كشف مستودعات ناجح. */
    public function recordMissingFromLatestMatrix(int $row, string $barcode): void
    {
        $this->record(ImportWarning::MissingFromLatestMatrix, ['row' => $row, 'barcode' => $barcode]);
    }

    /** 🆕 (أ.4) اسم/رمز صيدلية بملف لا يطابق صيدلية معروفة ولا مطابقة محفوظة. */
    public function recordUnknownPharmacyCode(int $row, string $pharmacy): void
    {
        $this->record(ImportWarning::UnknownPharmacyCode, ['row' => $row, 'pharmacy' => $pharmacy], $pharmacy);
    }

    /** 🆕 (ب.3) مادة بملف التحضير لم تكن ضمن الفاتورة الأصلية (أُضيفت كفرق موجب كامل). */
    public function recordProductNotInDocument(int $row, string $barcode): void
    {
        $this->record(ImportWarning::ProductNotInDocument, ['row' => $row, 'barcode' => $barcode]);
    }

    public function count(ImportWarning $warning): int
    {
        return $this->counts[$warning->value] ?? 0;
    }

    public function total(): int
    {
        return array_sum($this->counts);
    }

    public function hasWarnings(): bool
    {
        return $this->total() > 0;
    }

    /**
     * @return list<array<string, int|float|string>>
     */
    public function samples(ImportWarning $warning): array
    {
        return $this->samples[$warning->value] ?? [];
    }

    /** هل العيّنات أقل من العدد الحقيقي؟ (لعرض "أول 500 من أصل 2,882" بالواجهة) */
    public function isTruncated(ImportWarning $warning): bool
    {
        return $this->count($warning) > count($this->samples($warning));
    }

    /**
     * أسطر عربية جاهزة لرسالة الاكتمال، مرتَّبة بترتيب تعريف الـ Enum.
     *
     * @return list<string>
     */
    public function summaryLines(): array
    {
        $lines = [];

        foreach (ImportWarning::cases() as $warning) {
            $count = $this->count($warning);

            if ($count === 0) {
                continue;
            }

            $line = "⚠️ {$warning->label()}: {$count}";

            $buckets = $this->buckets[$warning->value] ?? [];
            if ($buckets !== []) {
                arsort($buckets);
                $top = [];
                foreach (array_slice($buckets, 0, self::TOP_BUCKETS_IN_SUMMARY, true) as $bucket => $bucketCount) {
                    $top[] = "{$bucket} ({$bucketCount})";
                }
                $line .= ' — الأكثر: ' . implode('، ', $top);
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * صيغة قابلة للحفظ كعمود JSON بجلسة الاستيراد.
     *
     * @return array{counts: array<string, int>, buckets: array<string, array<string, int>>, samples: array<string, list<array<string, int|float|string>>>}
     */
    public function toArray(): array
    {
        return ['counts' => $this->counts, 'buckets' => $this->buckets, 'samples' => $this->samples];
    }

    /**
     * يعيد بناء المُجمِّع من JSON محفوظ. مفاتيح تحذيرات غير معروفة (نسخة أقدم/أحدث
     * من الكود) تُتجاهَل بدل أن تُسقط الشاشة — قراءة تقرير قديم يجب ألا تنهار أبداً.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $instance = new self();

        foreach ((array) ($data['counts'] ?? []) as $key => $count) {
            if (ImportWarning::tryFrom((string) $key) !== null) {
                $instance->counts[(string) $key] = (int) $count;
            }
        }

        foreach ((array) ($data['buckets'] ?? []) as $key => $buckets) {
            if (ImportWarning::tryFrom((string) $key) === null) {
                continue;
            }
            foreach ((array) $buckets as $bucket => $bucketCount) {
                $instance->buckets[(string) $key][(string) $bucket] = (int) $bucketCount;
            }
        }

        foreach ((array) ($data['samples'] ?? []) as $key => $list) {
            if (ImportWarning::tryFrom((string) $key) !== null) {
                $instance->samples[(string) $key] = array_values((array) $list);
            }
        }

        return $instance;
    }

    /** @param array<string, int|float|string> $sample */
    private function record(ImportWarning $warning, array $sample, ?string $bucket = null): void
    {
        $key = $warning->value;

        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;

        if ($bucket !== null) {
            $this->buckets[$key][$bucket] = ($this->buckets[$key][$bucket] ?? 0) + 1;
        }

        if (count($this->samples[$key] ?? []) < self::MAX_SAMPLES_PER_WARNING) {
            $this->samples[$key][] = $sample;
        }
    }
}
