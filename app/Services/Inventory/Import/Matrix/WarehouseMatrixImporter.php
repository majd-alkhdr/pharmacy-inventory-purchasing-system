<?php

declare(strict_types=1);

namespace App\Services\Inventory\Import\Matrix;

use App\Models\ExcludedCompany;
use App\Models\Pharmacy;
use App\Models\PharmacyMapping;
use App\Services\Inventory\Import\Exceptions\InvalidImportFileException;
use App\Enums\ImportFileType;
use App\Services\Inventory\Import\Support\ImportDiagnostics;
use App\Services\Inventory\Import\Validation\CrossFileCodeValidator;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 يستورد ملف "كشف المستودعات" (المركزي + كل الصيدليات) بمرور واحد، ويكتب
 * **كل شيء أو لا شيء**: رصيد المركزي ولقطات الصيدليات معاً داخل معاملة واحدة.
 *
 * 🎓 ثلاثة قرارات معمارية تستحق الفهم:
 *
 * (1) خطة ثم تنفيذ. الصفوف تُقرأ وتُحلَّل كاملةً إلى MatrixWritePlan **قبل** أي كتابة.
 *     فشل بالقراءة (رمز تالف، رؤوس خاطئة) لا يترك قاعدة البيانات بنصف تحديث.
 *
 * (2) معاملة واحدة تلف المركزي والصيدليات. الكود القديم كان يصفّر كل central_inventories
 *     ثم يعيد الكتابة **خارج معاملة** — فشل بينهما = مخزن مركزي كله صفر، وأول ترميم
 *     بعده يسجّل نواقص وهمية بالجملة. هنا التصفير والكتابة ذرّيان.
 *
 * (3) الصفر المُثبَّت لأزواج المخزون القياسي فقط. المحرك يتجاهل زوجاً لا صف رصيد له
 *     (`continue` بـ ParLevelReplenishmentService) — لا يعامله صفراً. وقاعدتك "الفراغ =
 *     صفر داخل المصفوفة" تعني أن الفراغ لمادة لها مخزون قياسي **يجب** أن يُرمَّم. الحل بلا
 *     لمس المحرك: نكتب صف رصيد بالقيمة 0 لكل زوج (صيدلية+مادة) له مخزون قياسي وتقع مادته
 *     ضمن الكشف. ندفع ثمن هذه الأصفار فقط حيث تُقرأ، لا 216 ألف صف كثيف.
 *
 * ⚖️ الشركات المستثناة: بنفس دلالة الاستيرادات القديمة — رصيدها **المركزي** يُحدَّث دائماً
 * (الاستثناء للترميم لا للتتبع)، وأرصدة صيدلياتها تُتجاهَل كأنها غير موجودة بالكشف.
 *
 * 🆕 المواد المجهولة: لا تُنشأ هنا. ما له رصيد يعود كـ pendingProducts لمسار التأكيد
 * الموجود (PendingInventoryItem) — لا نُنشئ منتجات بلا موافقتك. ما بلا رصيد بأي موقع
 * (57% من كشفك بلا رصيد أصلاً) لا يُقترح، ويُعدّ ويُعرَض.
 */
final class WarehouseMatrixImporter
{
    private const CENTRAL_CHUNK = 500;
    private const SNAPSHOT_CHUNK = 1000;
    private const PRODUCT_REFRESH_CHUNK = 500;

    /** قيمة "لا شركة" الافتراضية بالاستيرادات القديمة — لا تُكتب فوق شركة حقيقية. */
    private const NO_COMPANY = '-';

    public function __construct(
        private readonly WarehouseMatrixRowParser $parser,
        private readonly PharmacyColumnResolver $resolver,
        // 🆕 (أ.4) افتراضي بالمُنشئ: الاختبارات القائمة التي تبني المستورد يدوياً تبقى تعمل.
        private readonly CrossFileCodeValidator $crossFileValidator = new CrossFileCodeValidator(),
    ) {
    }

    /**
     * @param int|null $importLogId 🆕 (أ.4) سجل الرفع لهذا الكشف — يُكتب على كل مادة ظهرت به
     *        (products.last_matrix_import_log_id) داخل نفس معاملة الكتابة، فيستطيع فحص ملف
     *        الأصناف لاحقاً معرفة "ما الذي لم يظهر بآخر كشف". null = لا تتبّع (استدعاء قديم/اختبار).
     *
     * @throws InvalidImportFileException ملف بصيغة خاطئة أو عمودان لنفس الصيدلية
     */
    public function import(string $absolutePath, ?int $importLogId = null): MatrixImportOutcome
    {
        $diagnostics = new ImportDiagnostics();
        $parsed = $this->parser->parse($absolutePath, $diagnostics);

        $pharmacyNames = Pharmacy::query()->pluck('name', 'id')->all();
        $resolution = $this->resolver->resolve(
            $parsed->columns,
            $pharmacyNames,
            PharmacyMapping::query()->pluck('pharmacy_id', 'original_name')->all(),
        );

        // خطأ بالملف نفسه (لا قرار بشري يحله): عمودان لنفس الصيدلية.
        foreach ($resolution->conflicts as $pharmacyId => $indexes) {
            throw InvalidImportFileException::conflictingPharmacyColumns(
                $pharmacyNames[$pharmacyId] ?? "#{$pharmacyId}",
                array_map(MatrixColumnMap::columnLetter(...), $indexes),
            );
        }

        // قرار بشري مطلوب: لا نقرأ صفاً واحداً ولا نكتب شيئاً.
        if ($resolution->needsManualMapping()) {
            return MatrixImportOutcome::needsPharmacyMapping($resolution->headersNeedingMapping(), $diagnostics);
        }

        $plan = $this->buildPlan($parsed, $resolution, $diagnostics);
        $written = $this->write($plan, $importLogId);

        [$pending, $withoutStock] = $this->splitUnknownProducts($plan);

        return new MatrixImportOutcome(
            applied: true,
            affectedPharmacyIds: $resolution->pharmacyIds(),
            headersNeedingMapping: [],
            pendingProducts: $pending,
            productsInFile: $plan->productsInFile,
            centralRowsWritten: count($plan->centralByProduct),
            pharmacyRowsWritten: $written['pharmacy_rows'],
            zeroRowsMaterialized: $plan->zeroRowsMaterialized,
            productsRefreshed: count($plan->productRefresh),
            unknownWithoutStock: $withoutStock,
            diagnostics: $diagnostics,
        );
    }

    /**
     * الرؤوس التي تحتاج مطابقة يدوية — **يقرأ صف الرؤوس فقط** (لا شيء من البيانات).
     * تستخدمها شاشة المطابقة: نُعيد الاشتقاق من الملف نفسه بدل تخزين قائمة قد تتقادم
     * (لو حُفظت مطابقة من مكان آخر بين الرفع والشاشة، تختفي من القائمة تلقائياً).
     *
     * @return list<string>
     */
    public function pharmacyHeadersNeedingMapping(string $absolutePath): array
    {
        $parsed = $this->parser->parse($absolutePath, new ImportDiagnostics());

        return $this->resolver->resolve(
            $parsed->columns,
            Pharmacy::query()->pluck('name', 'id')->all(),
            PharmacyMapping::query()->pluck('pharmacy_id', 'original_name')->all(),
        )->headersNeedingMapping();
    }

    private function buildPlan(ParsedMatrix $parsed, PharmacyResolution $resolution, ImportDiagnostics $diagnostics): MatrixWritePlan
    {
        $plan = new MatrixWritePlan($resolution->pharmacyIds());

        // barcode ← [id, name, company]. جدول المنتجات كله (عشرات الآلاف) بمصفوفة صغيرة —
        // أرخص بكثير من استعلام لكل صف، وأرخص من تحميل نماذج Eloquent.
        $products = [];
        foreach (DB::table('products')->get(['id', 'barcode', 'name', 'company']) as $product) {
            $products[$product->barcode] = ['id' => (int) $product->id, 'name' => (string) $product->name, 'company' => (string) $product->company];
        }

        $excludedCompanies = ExcludedCompany::currentList();
        $pharmacyEligibleProducts = []; // مواد يحق لها أصفار الصيدليات (غير شركة مستثناة)
        $unknownFirstRows = []; // 🆕 (أ.4) barcode => أول صف ظهر فيه

        foreach ($parsed->rows() as $row) {
            $known = $products[$row->barcode] ?? null;

            if ($known === null) {
                $this->recordUnknown($plan, $row, $resolution);
                $unknownFirstRows[$row->barcode] ??= $row->sourceRow;
                continue;
            }

            $plan->productsInFile++;
            $productId = $known['id'];

            // رمز مكرر بالملف: الكميات تُجمع (نفس دلالة CentralInventoryImport القديم).
            $plan->centralByProduct[$productId] = ($plan->centralByProduct[$productId] ?? 0) + $row->centralQuantity;

            if (!in_array($row->company, $excludedCompanies, true)) {
                $pharmacyEligibleProducts[$productId] = true;

                foreach ($resolution->resolved as $columnIndex => $pharmacyId) {
                    $quantity = $row->pharmacyQuantities[$columnIndex];
                    if ($quantity > 0) {
                        $plan->snapshotByPharmacy[$pharmacyId][$productId] = ($plan->snapshotByPharmacy[$pharmacyId][$productId] ?? 0) + $quantity;
                    }
                }
            }

            $this->planProductRefresh($plan, $known, $row);
        }

        $this->materializeStandardStockZeros($plan, $pharmacyEligibleProducts);

        // 🆕 (أ.4) رمز بالكشف غير موجود بملف الأصناف — كان يُعدّ رقماً بالملخص فقط
        // ("مادة جديدة تنتظر تأكيدك" / "بلا رصيد")؛ الآن تحذير مرئي بعيّنات بأرقام صفوفها.
        // الكتالوج المحمَّل أعلاه يُمرَّر فلا يُستعلَم عنه مرة ثانية.
        $this->crossFileValidator->recordUnknownProducts($unknownFirstRows, $diagnostics, ImportFileType::WarehouseMatrix, $products);

        return $plan;
    }

    /** @param array{id: int, name: string, company: string} $known */
    private function planProductRefresh(MatrixWritePlan $plan, array $known, WarehouseMatrixRow $row): void
    {
        // الكشف يملك اسم المادة وشركتها. لكن اسماً فارغاً أو شركة "-" (افتراض المُحلِّل عند
        // الفراغ) ليسا معلومة — لا يُكتب أيٌّ منهما فوق قيمة حقيقية محفوظة.
        $name = $row->name !== '' ? $row->name : $known['name'];
        $company = $row->company !== self::NO_COMPANY ? $row->company : $known['company'];

        if ($name !== $known['name'] || $company !== $known['company']) {
            $plan->productRefresh[$known['id']] = ['name' => $name, 'company' => $company];
        }
    }

    private function recordUnknown(MatrixWritePlan $plan, WarehouseMatrixRow $row, PharmacyResolution $resolution): void
    {
        $hasPharmacyStock = false;
        foreach ($resolution->resolved as $columnIndex => $_pharmacyId) {
            if ($row->pharmacyQuantities[$columnIndex] > 0) {
                $hasPharmacyStock = true;
                break;
            }
        }

        $existing = $plan->unknownProducts[$row->barcode] ?? null;

        $plan->unknownProducts[$row->barcode] = [
            'barcode' => $row->barcode,
            'material_name' => $row->name !== '' ? $row->name : ($existing['material_name'] ?? ''),
            'company' => $row->company,
            'quantity' => ($existing['quantity'] ?? 0) + $row->centralQuantity,
            'pack_size' => null,
            'price' => null,
            'has_stock' => ($existing['has_stock'] ?? false) || $row->centralQuantity > 0 || $hasPharmacyStock,
        ];
    }

    /**
     * لكل زوج (صيدلية+مادة) له مخزون قياسي وتقع مادته بالكشف ولم يُسجَّل له رصيد موجب:
     * صف رصيد بالقيمة 0. (راجع تعليق الكلاس، القرار 3.)
     *
     * @param array<int, true> $eligibleProducts
     */
    private function materializeStandardStockZeros(MatrixWritePlan $plan, array $eligibleProducts): void
    {
        if ($plan->pharmacyIds === [] || $eligibleProducts === []) {
            return;
        }

        $pairs = DB::table('pharmacy_minimum_stocks')
            ->whereIn('pharmacy_id', $plan->pharmacyIds)
            ->cursor(); // تدفّق: لا تحميل كل الأزواج معاً

        foreach ($pairs as $pair) {
            $productId = (int) $pair->product_id;
            $pharmacyId = (int) $pair->pharmacy_id;

            if (isset($eligibleProducts[$productId]) && !isset($plan->snapshotByPharmacy[$pharmacyId][$productId])) {
                $plan->snapshotByPharmacy[$pharmacyId][$productId] = 0;
                $plan->zeroRowsMaterialized++;
            }
        }
    }

    /**
     * @return array{pharmacy_rows: int}
     */
    private function write(MatrixWritePlan $plan, ?int $importLogId = null): array
    {
        $now = now()->toDateTimeString();
        $pharmacyRows = 0;

        DB::transaction(function () use ($plan, $now, $importLogId, &$pharmacyRows): void {
            // ── المركزي: استبدال كامل (المادة غير المذكورة بالكشف = صفر) ──
            DB::table('central_inventories')->update(['quantity' => 0]);

            $centralRows = [];
            foreach ($plan->centralByProduct as $productId => $quantity) {
                $centralRows[] = ['product_id' => $productId, 'quantity' => $quantity, 'created_at' => $now, 'updated_at' => $now];
            }
            foreach (array_chunk($centralRows, self::CENTRAL_CHUNK) as $chunk) {
                DB::table('central_inventories')->upsert($chunk, ['product_id'], ['quantity', 'updated_at']);
            }

            // ── الصيدليات: استبدال كامل للصيدليات المذكورة بالكشف فقط ──
            if ($plan->pharmacyIds !== []) {
                DB::table('pharmacy_inventories')->whereIn('pharmacy_id', $plan->pharmacyIds)->delete();

                $snapshotRows = [];
                foreach ($plan->snapshotByPharmacy as $pharmacyId => $quantities) {
                    foreach ($quantities as $productId => $quantity) {
                        $snapshotRows[] = ['pharmacy_id' => $pharmacyId, 'product_id' => $productId, 'quantity' => $quantity, 'created_at' => $now, 'updated_at' => $now];
                    }
                }
                foreach (array_chunk($snapshotRows, self::SNAPSHOT_CHUNK) as $chunk) {
                    DB::table('pharmacy_inventories')->insert($chunk);
                }
                $pharmacyRows = count($snapshotRows);
            }

            // ── اسم/شركة المواد التي تغيّرت (upsert بمفتاح الباركود؛ كلها موجودة أصلاً فلا إدراج) ──
            if ($plan->productRefresh !== []) {
                $barcodeById = DB::table('products')->whereIn('id', array_keys($plan->productRefresh))->pluck('barcode', 'id')->all();
                $refreshRows = [];
                foreach ($plan->productRefresh as $productId => $values) {
                    $refreshRows[] = ['barcode' => $barcodeById[$productId], 'name' => $values['name'], 'company' => $values['company'], 'created_at' => $now, 'updated_at' => $now];
                }
                foreach (array_chunk($refreshRows, self::PRODUCT_REFRESH_CHUNK) as $chunk) {
                    DB::table('products')->upsert($chunk, ['barcode'], ['name', 'company', 'updated_at']);
                }
            }

            // ── 🆕 (أ.4) وسم "ظهرت بهذا الكشف" — بنفس المعاملة: كشف تراجع = لا وسم كاذب ──
            if ($importLogId !== null) {
                foreach (array_chunk(array_keys($plan->centralByProduct), self::PRODUCT_REFRESH_CHUNK) as $chunk) {
                    DB::table('products')->whereIn('id', $chunk)->update(['last_matrix_import_log_id' => $importLogId]);
                }
            }
        });

        return ['pharmacy_rows' => $pharmacyRows];
    }

    /**
     * المجهولة ذات الرصيد → مسار التأكيد. المجهولة بلا رصيد → تُعدّ فقط.
     *
     * @return array{0: list<array{barcode: string, material_name: string, company: string, quantity: int, pack_size: null, price: null}>, 1: int}
     */
    private function splitUnknownProducts(MatrixWritePlan $plan): array
    {
        $pending = [];
        $withoutStock = 0;

        foreach ($plan->unknownProducts as $item) {
            if (!$item['has_stock']) {
                $withoutStock++;
                continue;
            }

            unset($item['has_stock']);
            $pending[] = $item;
        }

        return [$pending, $withoutStock];
    }
}
