<?php

declare(strict_types=1);

namespace App\Services\Inventory\Import\MaterialMaster;

use App\Enums\ImportFileType;
use App\Services\Inventory\CentralInventoryPriceRecorderService;
use App\Services\Inventory\Import\Exceptions\InvalidImportFileException;
use App\Services\Inventory\Import\Support\ImportDiagnostics;
use App\Services\Inventory\Import\Validation\CrossFileCodeValidator;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 يستورد ملف "بيانات المواد" (الباركود، الاسم، الشركة، السعر، حجم العبوة،
 * الحد الأدنى للفرق). يحدّث مواداً **موجودة فقط** — بلا صلاحية إنشاء شركات
 * أو مواد جديدة (القرار المتفق عليه: هذا الملف لا يحمل بيانات كافية لإنشاء
 * منتج بثقة — لا كمية، لا مصدر تحقّق). رمز غير مسجَّل يظهر بقائمة معلوماتية
 * فقط، بلا أي أثر بقاعدة البيانات.
 *
 * 🎓 ثلاثة قرارات تستحق الشرح:
 *
 * (1) دمج عبر الصفوف المكررة، لا "آخر صف يفوز بالكامل". لو تكرر نفس الباركود
 *     بصفين (خطأ نسخ شائع)، والصف الأول يذكر حجم العبوة والثاني يذكر السعر
 *     فقط: "آخر صف يفوز كلياً" كان سيُفقِد حجم العبوة بصمت رغم أن قاعدة الملف
 *     نفسها تقول "الفراغ = لا تغيير" — فراغ الصف الثاني بخانة حجم العبوة لا
 *     يجوز أن يُلغي قيمة حقيقية ذُكرت بصف آخر لنفس المادة بنفس الملف. الحل:
 *     نجمع كل الصفوف بنفس الباركود عمود-عمود قبل الكتابة (آخر قيمة غير-فارغة
 *     لكل عمود على حدة تفوز)، ونُبلّغ عن التكرار كتحذير مرئي.
 *
 * (2) لا كتابة بلا تغيير فعلي. مادة ذُكرت بالملف لكن كل قيمها الفعلية (بعد
 *     الدمج) مطابقة لما هو محفوظ أصلاً لا تدخل دفعة upsert إطلاقاً — نفس مبدأ
 *     WarehouseMatrixImporter::planProductRefresh() بالضبط: لا تحديث بلا سبب،
 *     ولا updated_at يتغيّر بلا داعٍ حقيقي.
 *
 * (3) معاملة واحدة تلف تحديث المنتجات وتسجيل تاريخ الأسعار معاً — إما كلاهما،
 *     أو لا شيء. (السعر يُسجَّل عبر CentralInventoryPriceRecorderService
 *     الموجودة أصلاً — لا كود مكرر لمقارنة الأسعار هنا.)
 */
final class MaterialMasterImporter
{
    private const UPDATE_CHUNK = 500;

    public function __construct(
        private readonly MaterialMasterRowParser $parser,
        private readonly CentralInventoryPriceRecorderService $priceRecorder,
        private readonly CrossFileCodeValidator $crossFileValidator = new CrossFileCodeValidator(), // 🆕 (أ.4)
        private readonly MaterialPriceHistoryRecorder $priceHistory = new MaterialPriceHistoryRecorder(),
    ) {
    }

    /**
     * @throws InvalidImportFileException ملف تالف أو رؤوس لا تطابق الترتيب المتفق عليه — قبل أي كتابة
     */
    public function import(string $absolutePath): MaterialMasterImportOutcome
    {
        $diagnostics = new ImportDiagnostics();
        $merged = $this->mergeRowsByBarcode($this->parser->parse($absolutePath, $diagnostics), $diagnostics, $rowsInFile);

        if ($merged === []) {
            return new MaterialMasterImportOutcome($rowsInFile, 0, 0, [], $diagnostics);
        }

        // 🩹 array_column() لا array_keys(): نفس سبب حفظ 'barcode' صراحة بكل عنصر أعلاه —
        // مفاتيح المصفوفة قد تكون int لباركودات رقمية بالكامل، والقيم الصريحة محصَّنة من هذا.
        $existingByBarcode = DB::table('products')
            ->whereIn('barcode', array_column($merged, 'barcode'))
            ->get(['id', 'barcode', 'name', 'company', 'pack_size', 'min_reorder_gap'])
            ->keyBy('barcode');

        $unmatched = [];
        $updateRows = [];
        $pricesByProductId = [];
        $matchedFirstRows = []; // 🆕 (أ.4)
        $unmatchedFirstRows = []; // 🆕 (أ.4)

        foreach ($merged as $fields) {
            $barcode = $fields['barcode'];
            $existing = $existingByBarcode->get($barcode);

            if ($existing === null) {
                $unmatched[] = $barcode;
                $unmatchedFirstRows[$barcode] = $fields['row'];
                continue;
            }

            $matchedFirstRows[$barcode] = $fields['row'];

            if ($fields['price'] !== null) {
                $pricesByProductId[$existing->id] = $fields['price'];
            }

            $updateRow = $this->buildUpdateRowIfChanged($existing, $fields);
            if ($updateRow !== null) {
                $updateRows[] = $updateRow;
            }
        }

        // 🆕 (أ.4) فحص الاتجاهين بين ملف الأصناف وكشف المستودعات — تحذيرات مرئية بنتيجة الرفع:
        //   ← رمز هنا غير معروف للنظام (كان سطراً معلوماتياً فقط؛ صار تحذيراً بأرقام صفوفه).
        //   → رمز هنا معروف لكنه غائب عن آخر كشف ناجح (لا رصيد له بأي موقع ⇒ لن يُرمَّم أبداً).
        $this->crossFileValidator->recordUnknownProducts(
            $unmatchedFirstRows,
            $diagnostics,
            ImportFileType::MaterialMaster,
            $existingByBarcode->keys()->mapWithKeys(fn ($b) => [(string) $b => true])->all(),
        );
        $this->crossFileValidator->recordMissingFromLatestMatrix($matchedFirstRows, $diagnostics);

        $pricesChanged = 0;

        DB::transaction(function () use ($updateRows, $pricesByProductId, &$pricesChanged): void {
            foreach (array_chunk($updateRows, self::UPDATE_CHUNK) as $chunk) {
                DB::table('products')->upsert($chunk, ['barcode'], ['name', 'company', 'pack_size', 'min_reorder_gap', 'updated_at']);
            }

            // 🆕 سجل التغيّر (قديم ← جديد) يُكتب أولاً — قبل أن يصبح السعر الجديد هو "المخزَّن".
            $this->priceHistory->recordChanges($pricesByProductId);
            $pricesChanged = $this->priceRecorder->recordChanges($pricesByProductId);
        });

        return new MaterialMasterImportOutcome($rowsInFile, count($updateRows), $pricesChanged, $unmatched, $diagnostics);
    }

    /**
     * يدمج كل الصفوف بنفس الباركود عمود-عمود (راجع تعليق الكلاس، القرار 1)، ويُبلّغ
     * عن أي تكرار. $rowsInFile يُمرَّر بالمرجع لأن Generator أحادي المرور — نحتاج
     * عدّه أثناء الاستهلاك الوحيد المتاح له، لا استهلاكاً ثانياً بعد ذلك.
     *
     * 🩹 كل عنصر يحمل باركوده كحقل صريح (لا كمفتاح المصفوفة فقط) عمداً: PHP يحوّل
     * مفاتيح المصفوفات التي تبدو كأعداد صحيحة كاملة (مثل "6281000123456") إلى
     * مفاتيح int صامتاً — وباركودات هذا النظام أرقام بالكامل. الاعتماد على قراءة
     * الباركود من مفتاح الحلقة (`foreach ($merged as $barcode => ...)`) كان يُعيد
     * أحياناً int بدل string حسب شكل الرقم تحديداً — خطأ دقيق يصعب ملاحظته لأنه
     * يظهر لبعض الباركودات دون غيرها فقط. القيمة المخزَّنة صراحة هنا محصَّنة من هذا.
     *
     * @return array<string, array{barcode: string, row: int, name: ?string, company: ?string, price: ?float, packSize: ?int, minReorderGap: ?int}>
     */
    private function mergeRowsByBarcode(iterable $rows, ImportDiagnostics $diagnostics, ?int &$rowsInFile): array
    {
        $rowsInFile = 0;
        $merged = [];

        foreach ($rows as $row) {
            $rowsInFile++;

            if (isset($merged[$row->barcode])) {
                $diagnostics->recordDuplicateBarcode($row->sourceRow, $row->barcode);
            }

            $current = $merged[$row->barcode] ?? ['barcode' => $row->barcode, 'row' => $row->sourceRow, 'name' => null, 'company' => null, 'price' => null, 'packSize' => null, 'minReorderGap' => null];

            $merged[$row->barcode] = [
                'barcode' => $row->barcode,
                'row' => $current['row'], // 🆕 (أ.4) أول صف ظهر فيه الرمز — لعيّنات التحذير
                'name' => $row->name ?? $current['name'],
                'company' => $row->company ?? $current['company'],
                'price' => $row->price ?? $current['price'],
                'packSize' => $row->packSize ?? $current['packSize'],
                'minReorderGap' => $row->minReorderGap ?? $current['minReorderGap'],
            ];
        }

        return $merged;
    }

    /**
     * @param object{id: int, barcode: string, name: string, company: string, pack_size: int, min_reorder_gap: ?int} $existing
     * @param array{name: ?string, company: ?string, price: ?float, packSize: ?int, minReorderGap: ?int} $fields
     * @return array{barcode: string, name: string, company: string, pack_size: int, min_reorder_gap: ?int, created_at: string, updated_at: string}|null
     */
    private function buildUpdateRowIfChanged(object $existing, array $fields): ?array
    {
        $newName = $fields['name'] ?? $existing->name;
        $newCompany = $fields['company'] ?? $existing->company;
        $newPackSize = $fields['packSize'] ?? (int) $existing->pack_size;

        $oldMinReorderGap = $existing->min_reorder_gap === null ? null : (int) $existing->min_reorder_gap;
        $newMinReorderGap = $fields['minReorderGap'] ?? $oldMinReorderGap;

        $unchanged = $newName === $existing->name
            && $newCompany === $existing->company
            && $newPackSize === (int) $existing->pack_size
            && $newMinReorderGap === $oldMinReorderGap;

        if ($unchanged) {
            return null;
        }

        $now = now()->toDateTimeString();

        return [
            'barcode' => $existing->barcode,
            'name' => $newName,
            'company' => $newCompany,
            'pack_size' => $newPackSize,
            'min_reorder_gap' => $newMinReorderGap,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
