<?php

declare(strict_types=1);

namespace App\Services\Inventory\Import\Validation;

use App\Enums\ImportFileType;
use App\Models\ImportLog;
use App\Models\Pharmacy;
use App\Models\PharmacyMapping;
use App\Services\Inventory\Import\Support\ArabicTextNormalizer;
use App\Services\Inventory\Import\Support\ImportDiagnostics;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 (أ.4) فحص تطابق الأكواد **بين** الملفات المرفوعة — كل مستورد كان يتحقق من صحة
 * ملفه داخلياً فقط (رؤوس، أرقام)، ولا شيء يلاحظ أن رمزاً بكشف المستودعات غير موجود
 * بملف الأصناف أو العكس. النتيجة كانت أخطاء **صامتة**: مادة بلا سعر/عبوة تُرمَّم بعبوة
 * صفر، أو مادة بملف الأصناف لا تظهر بأي كشف فتبقى "بلا رصيد" دون أن يعرف أحد السبب.
 *
 * 🎓 لماذا خدمة مستقلة لا أسطر داخل كل مستورد؟ نفس الفحص يُشغَّل من 4 مستوردات (الكشف،
 * الأصناف، المخزون القياسي، مطابقة التحضير). SRP: المستورد يحلّل ملفه؛ هذه الخدمة تعرف
 * "علاقات" الملفات ببعضها. وكلها تكتب لنفس ImportDiagnostics الموجود (لا بنية تحذيرات
 * ثانية) — فتظهر النتائج تلقائياً بملخص الرفع (summaryLines) وبسجل الرفع، **لا باللوج فقط**.
 */
final class CrossFileCodeValidator
{
    private const QUERY_CHUNK = 1000;

    /**
     * رموز غير معروفة لكتالوج المواد (products — يُغذّيه ملف الأصناف).
     *
     * @param array<string, int> $firstRowByBarcode [barcode => أول صف ظهر فيه]
     * @param array<string, mixed>|null $knownBarcodes مجموعة رموز معروفة محمَّلة مسبقاً (barcode => أي قيمة)
     *        — المستورد الذي حمّل الكتالوج كله أصلاً (الكشف) يمرّره فلا نستعلم مرتين.
     * @return list<string> الرموز غير المعروفة
     */
    public function recordUnknownProducts(array $firstRowByBarcode, ImportDiagnostics $diagnostics, ImportFileType $source, ?array $knownBarcodes = null): array
    {
        if ($firstRowByBarcode === []) {
            return [];
        }

        $knownBarcodes ??= $this->existingBarcodes(array_keys($firstRowByBarcode));

        $unknown = [];
        foreach ($firstRowByBarcode as $barcode => $row) {
            $barcode = (string) $barcode; // مفاتيح رقمية بالكامل تتحول int بصمت بـ PHP
            if (!array_key_exists($barcode, $knownBarcodes)) {
                $diagnostics->recordUnknownProductCode($row, $barcode, $source->label());
                $unknown[] = $barcode;
            }
        }

        return $unknown;
    }

    /**
     * الاتجاه المعاكس: رموز ملف الأصناف المعروفة للنظام لكنها **لم تظهر بآخر كشف مستودعات
     * ناجح** (العمود products.last_matrix_import_log_id يكتبه الكشف بمعاملته). لا كشف بعد ⇒
     * لا مرجع للمقارنة ⇒ لا تحذير (أفضل من إغراق أول رفعة بآلاف التحذيرات الزائفة).
     *
     * @param array<string, int> $firstRowByBarcode
     */
    public function recordMissingFromLatestMatrix(array $firstRowByBarcode, ImportDiagnostics $diagnostics): int
    {
        $latestMatrix = ImportLog::latestSuccessful(ImportFileType::WarehouseMatrix);
        if ($latestMatrix === null || $firstRowByBarcode === []) {
            return 0;
        }

        $count = 0;
        foreach (array_chunk(array_map('strval', array_keys($firstRowByBarcode)), self::QUERY_CHUNK) as $chunk) {
            $missing = DB::table('products')
                ->whereIn('barcode', $chunk)
                ->where(fn ($q) => $q->whereNull('last_matrix_import_log_id')->orWhere('last_matrix_import_log_id', '!=', $latestMatrix->id))
                ->pluck('barcode');

            foreach ($missing as $barcode) {
                $diagnostics->recordMissingFromLatestMatrix($firstRowByBarcode[$barcode] ?? 0, (string) $barcode);
                $count++;
            }
        }

        return $count;
    }

    /**
     * أسماء صيدليات لا تطابق صيدلية معروفة (بالاسم المُطبَّع) ولا مطابقة محفوظة.
     *
     * @param array<string, int> $firstRowByName [اسم كما بالملف => أول صف]
     * @return list<string>
     */
    public function recordUnknownPharmacies(array $firstRowByName, ImportDiagnostics $diagnostics): array
    {
        if ($firstRowByName === []) {
            return [];
        }

        $known = [];
        foreach (Pharmacy::query()->pluck('name') as $name) {
            $known[ArabicTextNormalizer::key((string) $name)] = true;
        }
        foreach (PharmacyMapping::query()->pluck('original_name') as $name) {
            $known[ArabicTextNormalizer::key((string) $name)] = true;
        }

        $unknown = [];
        foreach ($firstRowByName as $name => $row) {
            if (!isset($known[ArabicTextNormalizer::key((string) $name)])) {
                $diagnostics->recordUnknownPharmacyCode($row, (string) $name);
                $unknown[] = (string) $name;
            }
        }

        return $unknown;
    }

    /**
     * @param list<string|int> $barcodes
     * @return array<string, true>
     */
    private function existingBarcodes(array $barcodes): array
    {
        $known = [];
        foreach (array_chunk(array_map('strval', $barcodes), self::QUERY_CHUNK) as $chunk) {
            foreach (DB::table('products')->whereIn('barcode', $chunk)->pluck('barcode') as $barcode) {
                $known[(string) $barcode] = true;
            }
        }

        return $known;
    }
}
