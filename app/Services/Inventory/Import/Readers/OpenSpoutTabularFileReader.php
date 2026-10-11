<?php

declare(strict_types=1);

namespace App\Services\Inventory\Import\Readers;

use App\Services\Inventory\Import\Contracts\TabularFileReader;
use App\Services\Inventory\Import\Exceptions\InvalidImportFileException;
use DateTimeInterface;
use Generator;
use OpenSpout\Common\Exception\OpenSpoutException;
use OpenSpout\Common\Helper\EncodingHelper;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Reader\XLSX\Sheet as XlsxSheet;

/**
 * 🎯 تطبيق TabularFileReader فوق OpenSpout — قراءة **متدفقة** (XMLReader) لملفات
 * xlsx وcsv بنفس الكلاس.
 *
 * 🎓 لماذا هذا أسرع جوهرياً من Maatwebsite/PhpSpreadsheet لهذه المهمة؟
 * PhpSpreadsheet يبني نموذج مستند كاملاً (خلايا، أنماط، صيغ) ثم WithChunkReading
 * يعيد تحليل الملف من بدايته لكل chunk. OpenSpout يمرّ على XML مرة واحدة من أول
 * الملف لآخره ولا يحتفظ إلا بالصف الحالي — كلفة خطية وذاكرة ثابتة. نحن لا نحتاج
 * أنماطاً ولا صيغاً: نحتاج قيماً فقط.
 *
 * ⚠️ حدود معروفة (تُرفض برسالة واضحة لا بصمت):
 *  - xls (الصيغة الثنائية القديمة) غير مدعومة — يُطلب حفظه xlsx.
 *  - ورقة واحدة تُقرأ: أول ورقة **ظاهرة**. الأوراق المخفية تُتجاهَل.
 *
 * 🛡️ التحقق (وجود الملف، الصيغة، سلامة الأرشيف) يحدث **فوراً** عند استدعاء read()،
 * لا عند أول تكرار — فمن يستدعيها يحصل على الخطأ بسطر الاستدعاء نفسه.
 */
final class OpenSpoutTabularFileReader implements TabularFileReader
{
    private const CSV_SAMPLE_BYTES = 8192;

    /** @var list<string> فواصل CSV المحتملة: ملفات Excel بإعدادات عربية كثيراً ما تستخدم ";" */
    private const CSV_DELIMITERS = [',', ';', "\t"];

    /** ترميم CSV العربي القديم من Excel على ويندوز عندما لا يكون UTF-8. */
    private const LEGACY_ARABIC_ENCODING = 'WINDOWS-1256';

    public function read(string $absolutePath): Generator
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            throw InvalidImportFileException::unreadable('الملف غير موجود أو غير قابل للقراءة.');
        }

        return $this->stream($this->open($absolutePath));
    }

    private function open(string $path): XlsxReader|CsvReader
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        try {
            $reader = match ($extension) {
                'xlsx' => $this->xlsxReader(),
                // Laravel قد يحفظ رفع CSV بامتداد txt (تخمين الـ MIME) — نقبله.
                'csv', 'txt' => $this->csvReader($path),
                'xls' => throw InvalidImportFileException::unreadable('صيغة xls القديمة غير مدعومة — افتح الملف واحفظه بصيغة xlsx.'),
                default => throw InvalidImportFileException::unreadable("الصيغة «{$extension}» غير مدعومة — المسموح xlsx أو csv."),
            };

            $reader->open($path);

            return $reader;
        } catch (OpenSpoutException $e) {
            throw InvalidImportFileException::unreadable($e->getMessage());
        }
    }

    private function xlsxReader(): XlsxReader
    {
        $options = new XlsxOptions();
        // يُبقي أرقام الصفوف صادقة: الصف الفارغ يُعاد بدل أن يُتخطّى ويُزيح ما بعده.
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;

        return new XlsxReader($options);
    }

    private function csvReader(string $path): CsvReader
    {
        $sample = (string) file_get_contents($path, false, null, 0, self::CSV_SAMPLE_BYTES);

        $options = new CsvOptions();
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $options->FIELD_DELIMITER = $this->detectDelimiter($sample);
        $options->ENCODING = $this->looksLikeUtf8($sample) ? EncodingHelper::ENCODING_UTF8 : self::LEGACY_ARABIC_ENCODING;

        return new CsvReader($options);
    }

    /** الفاصل الأكثر تكراراً بالسطر الأول (سطر الرؤوس). */
    private function detectDelimiter(string $sample): string
    {
        $firstLine = strtok($sample, "\r\n") ?: '';
        $best = ',';
        $bestCount = 0;

        foreach (self::CSV_DELIMITERS as $delimiter) {
            $count = substr_count($firstLine, $delimiter);
            if ($count > $bestCount) {
                $best = $delimiter;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * العينة مقطوعة عند 8KB فقد تنتهي بنصف حرف UTF-8 — نسمح بقصّ حتى 3 بايتات
     * من نهايتها قبل الحكم بأنها ليست UTF-8، وإلا فُسِّر ملف سليم كأنه Windows-1256.
     */
    private function looksLikeUtf8(string $sample): bool
    {
        $length = strlen($sample);

        for ($trim = 0; $trim <= 3 && $trim <= $length; $trim++) {
            if (mb_check_encoding(substr($sample, 0, $length - $trim), 'UTF-8')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Generator<int, array<int, string|int|float|bool|null>>
     */
    private function stream(XlsxReader|CsvReader $reader): Generator
    {
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($sheet instanceof XlsxSheet && !$sheet->isVisible()) {
                    continue;
                }

                foreach ($sheet->getRowIterator() as $rowNumber => $row) {
                    yield (int) $rowNumber => $this->cells($row);
                }

                return; // أول ورقة ظاهرة فقط
            }
        } catch (OpenSpoutException $e) {
            throw InvalidImportFileException::unreadable($e->getMessage());
        } finally {
            // يُنفَّذ أيضاً إن توقف المستهلك عن التكرار مبكراً (Generator destroyed).
            $reader->close();

            // 🩹 OpenSpout (v4.28) لا يحرّر مقبض الملف بـ close() إن توقفت القراءة قبل نهاية
            // الورقة (قراءة الرؤوس فقط مثلاً): XMLReader على zip:// يبقى محجوزاً داخل كائنات
            // القارئ/الورقة المترابطة دائرياً حتى يمرّ جامع الدورات. على ويندوز هذا يعني أن
            // Storage::delete() يفشل بصمت ويبقى الملف المرفوع للأبد (ظهر بـ MatrixUploadFlowTest:
            // فحص رؤوس الصيدليات ثم الاستئناف). إسقاط مرجع القارئ نفسه شرطٌ — بدونه لا يُجمَع شيء
            // (مُتحقَّق تجريبياً). يُنفَّذ مرة واحدة لكل ملف، لا لكل صف.
            $sheet = null;
            $row = null;
            $reader = null;
            gc_collect_cycles();
        }
    }

    /**
     * @return array<int, string|int|float|bool|null>
     */
    private function cells(Row $row): array
    {
        $cells = [];

        foreach ($row->toArray() as $index => $value) {
            $cells[(int) $index] = match (true) {
                $value === '' || $value === null => null,
                $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
                default => $value,
            };
        }

        return $cells;
    }
}
