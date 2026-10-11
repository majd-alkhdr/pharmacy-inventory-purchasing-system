<?php

declare(strict_types=1);

namespace App\Services\Inventory\Import\Matrix;

use App\Services\Inventory\Import\Exceptions\InvalidImportFileException;
use App\Services\Inventory\Import\Support\ArabicTextNormalizer;
use InvalidArgumentException;

/**
 * 🎯 "خريطة الأعمدة" لملف المصفوفة اليومي — تُبنى **مرة واحدة** من صف الرؤوس،
 * ثم تخبر كل صف بعده أين يجد ماذا. تحمل عقد الملف المتفق عليه:
 *
 *   العمود 1: رمز المادة | 2: الاسم | 3: الشركة | 4: مستودع الصناعة (المركزي)
 *   من العمود 5 حتى آخر رأس: الصيدليات — عدد غير ثابت.
 *
 * 🎓 لماذا "موضع ثابت + تحقق من الرأس" وليس "بحث عن العمود بالاسم"؟
 * المواضع الأربعة الأولى ثابتة بقرارك، فالبحث بالاسم يُضيف مرونة لا حاجة لها.
 * لكن الاعتماد على الموضع وحده خطر صامت: ملف بعمود مُزاح يُقرأ بلا خطأ فيُعامَل
 * اسم المادة كرمز، والشركة ككمية… ويُكتشف بعد أن يلوّث البيانات. لذلك نتحقق
 * من رأس كل عمود من الأربعة ونرفض الملف كله برسالة تدلّ على العمود الخطأ.
 * الصيدليات وحدها تُقرأ بأسمائها لأن عددها متغيّر بالتعريف.
 *
 * 🛡️ لا تحوّل أي رأس مجهول إلى صيدلية هنا. مهمة هذا الكلاس **بنيوية** فقط
 * (هل الملف بالشكل المتفق عليه؟)؛ "أي صيدلية حقيقية هذه؟" قرار مطابقة
 * لاحق بطبقة أخرى، يتوقف عند الغموض بدل أن يُنشئ صيدلية من كلمة مثل
 * "ملاحظات" أو خطأ إملائي.
 *
 * ↔️ استثناء واحد: رؤوس "المجموع/الإجمالي" تُعرَف كأعمدة ملخَّصة وتُتجاهَل
 * بأمان (تُعرض بـ ignoredColumns) — لأن معناها لا يحتمل اللبس، وقراءتها
 * كصيدلية كانت ستضاعف كل الكميات.
 */
final class MatrixColumnMap
{
    public const BARCODE = 0;
    public const NAME = 1;
    public const COMPANY = 2;
    public const CENTRAL = 3;
    public const FIRST_PHARMACY = 4;

    /**
     * الأول بكل قائمة هو الاسم المعتمد (يظهر بالرسائل)، والباقي مرادفات مقبولة.
     * كلها تُقارَن بعد التطبيع (ArabicTextNormalizer)، فالفروق بالهمزة/الفراغ لا تُرفض.
     *
     * @var array<int, list<string>>
     */
    private const EXPECTED_HEADERS = [
        self::BARCODE => ['رمز المادة', 'رقم المادة', 'الباركود', 'باركود', 'رمز'],
        self::NAME    => ['الاسم', 'اسم المادة', 'المادة'],
        self::COMPANY => ['الشركة', 'اسم الشركة', 'الشركة المصنعة'],
        self::CENTRAL => ['مستودع الصناعة', 'المخزون المركزي', 'المستودع المركزي', 'المركزي'],
    ];

    /** @var list<string> */
    private const SUMMARY_HEADERS = ['المجموع', 'مجموع', 'الاجمالي', 'اجمالي', 'الإجمالي', 'Total'];

    /**
     * @param array<int, string> $pharmacyHeaders فهرس العمود ← رأسه الأصلي (كما كتبه المستخدم)
     * @param array<int, string> $ignoredHeaders  أعمدة ملخَّصة معروفة (المجموع…) تُتجاهَل
     */
    private function __construct(
        private readonly array $pharmacyHeaders,
        private readonly array $ignoredHeaders,
    ) {
    }

    /**
     * @param array<int, string|int|float|bool|null> $cells خلايا الصف الأول (فهرس من 0)
     *
     * @throws InvalidImportFileException
     */
    public static function fromHeaderRow(array $cells): self
    {
        if (self::isBlankRow($cells)) {
            throw InvalidImportFileException::emptyFile();
        }

        // 1) الأعمدة الأربعة الثابتة: رأس مطابق أو رفض الملف كله.
        foreach (self::EXPECTED_HEADERS as $index => $aliases) {
            $actual = self::cellText($cells[$index] ?? null);

            if (!in_array(ArabicTextNormalizer::key($actual), self::normalizedSet($aliases), true)) {
                throw InvalidImportFileException::headerMismatch(self::columnLetter($index), $aliases[0], $actual);
            }
        }

        // 2) الصيدليات: من العمود الخامس حتى **آخر رأس غير فارغ**. الرؤوس الفارغة
        //    الزائدة بعده (تنسيق Excel) تُتجاهَل، لكن فراغ *بين* رؤوس = عمود
        //    مجهول الهوية قد يحوي بيانات، فنرفضه بدل التخمين.
        $lastHeaderIndex = self::lastNonBlankIndex($cells);
        $summaryKeys = self::normalizedSet(self::SUMMARY_HEADERS);

        $pharmacies = [];
        $ignored = [];
        $firstColumnByKey = [];

        for ($index = self::FIRST_PHARMACY; $index <= $lastHeaderIndex; $index++) {
            $header = self::cellText($cells[$index] ?? null);

            if ($header === '') {
                throw InvalidImportFileException::blankHeaderGap(self::columnLetter($index));
            }

            $key = ArabicTextNormalizer::key($header);

            if (in_array($key, $summaryKeys, true)) {
                $ignored[$index] = $header;
                continue;
            }

            // مفتاحان متطابقان = نفس الصيدلية مكتوبة بتنسيقين ("- " مقابل " / ")؛
            // جمعهما يُضاعف الكمية وتجاهل أحدهما يُخفي بيانات — كلاهما تخمين.
            if (isset($firstColumnByKey[$key])) {
                throw InvalidImportFileException::duplicateLocationHeader(
                    $header,
                    self::columnLetter($firstColumnByKey[$key]),
                    self::columnLetter($index),
                );
            }

            $firstColumnByKey[$key] = $index;
            $pharmacies[$index] = $header;
        }

        return new self($pharmacies, $ignored);
    }

    /**
     * @return array<int, string> فهرس العمود ← اسم الصيدلية كما كُتب بالملف
     */
    public function pharmacyColumns(): array
    {
        return $this->pharmacyHeaders;
    }

    /**
     * مفاتيح المطابقة لكل عمود صيدلية — تُقارَن بمفاتيح أسماء الصيدليات المسجَّلة.
     *
     * @return array<int, string> فهرس العمود ← مفتاح المطابقة
     */
    public function pharmacyKeys(): array
    {
        return array_map(ArabicTextNormalizer::key(...), $this->pharmacyHeaders);
    }

    /**
     * @return array<int, string> أعمدة المجموع/الإجمالي المتجاهَلة
     */
    public function ignoredColumns(): array
    {
        return $this->ignoredHeaders;
    }

    public function pharmacyCount(): int
    {
        return count($this->pharmacyHeaders);
    }

    /** آخر فهرس عمود له معنى (مركزي/صيدلية/ملخَّص) — ما بعده أعمدة "يتيمة" إن حوت بيانات. */
    public function lastColumnIndex(): int
    {
        return max([self::CENTRAL, ...array_keys($this->pharmacyHeaders), ...array_keys($this->ignoredHeaders)]);
    }

    /** اسم مقروء للموقع بتقارير التحذيرات: "المخزون المركزي" أو اسم الصيدلية أو حرف العمود. */
    public function locationLabel(int $index): string
    {
        return match (true) {
            $index === self::CENTRAL => 'المخزون المركزي',
            isset($this->pharmacyHeaders[$index]) => $this->pharmacyHeaders[$index],
            isset($this->ignoredHeaders[$index]) => $this->ignoredHeaders[$index],
            default => 'العمود ' . self::columnLetter($index),
        };
    }

    /** 0 → A، 25 → Z، 26 → AA — الحرف الذي يراه المستخدم في Excel (للرسائل). */
    public static function columnLetter(int $index): string
    {
        if ($index < 0) {
            throw new InvalidArgumentException("فهرس عمود سالب: {$index}");
        }

        $number = $index + 1;
        $letters = '';

        while ($number > 0) {
            $remainder = ($number - 1) % 26;
            $letters = chr(65 + $remainder) . $letters;
            $number = intdiv($number - 1, 26);
        }

        return $letters;
    }

    /**
     * @param list<string> $aliases
     * @return list<string>
     */
    private static function normalizedSet(array $aliases): array
    {
        return array_map(ArabicTextNormalizer::key(...), $aliases);
    }

    private static function cellText(string|int|float|bool|null $value): string
    {
        return $value === null ? '' : trim((string) $value);
    }

    /** @param array<int, string|int|float|bool|null> $cells */
    private static function isBlankRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (self::cellText($cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, string|int|float|bool|null> $cells */
    private static function lastNonBlankIndex(array $cells): int
    {
        $last = -1;

        foreach ($cells as $index => $cell) {
            if (self::cellText($cell) !== '') {
                $last = max($last, (int) $index);
            }
        }

        return $last;
    }
}
