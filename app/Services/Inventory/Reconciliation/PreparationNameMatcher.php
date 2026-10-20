<?php

declare(strict_types=1);

namespace App\Services\Inventory\Reconciliation;

use App\Services\Inventory\Import\Support\ArabicTextNormalizer;
use App\Services\Inventory\Import\Support\MaterialNameKey;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 (ب.3) مسؤولية واحدة: «أي مادة تقصد هذه الأسماء من ملف التحضير؟».
 *
 * 🎓 لماذا تبدأ ببنود الفاتورة لا بالكتالوج؟ products لم يعد فريداً على (name, company)، فالاسم
 * قد يتكرر لمادتين مختلفتين بالكتالوج. بنود فاتورة واحدة مجموعة صغيرة جداً، فالتكرار فيها نادر —
 * والكتالوج يُسأل فقط عمّا لم يُوجد بالفاتورة (لتمييز "مادة ليست بالفاتورة" عن "اسم مجهول").
 *
 * ثلاثة مرورات لكل اسم، يتوقف عند أول مرور يجد مرشحاً:
 *   1. تام بالمفتاح المحافظ (MaterialNameKey) ضمن مواد الفاتورة.
 *   2. تقريبي بـ ArabicTextNormalizer ضمن مواد الفاتورة فقط — لأن توحيد ة/ه والفواصل خطر على
 *      الكتالوج كله، ومقبول بمجموعة صغيرة مع تحذير ظاهر.
 *   3. تام بالكتالوج كله ⇒ NotInDocument (مرشح واحد) أو Unknown (لا شيء).
 * أكثر من مرشح بأي مرور ⇒ Ambiguous: لا تخمين.
 */
final class PreparationNameMatcher
{
    private const CATALOG_CHUNK = 1000;

    /**
     * @param array<string, array{name: string, row: int}> $preparedByKey  [المفتاح المحافظ => اسم كما بالملف + أول صف]
     * @param array<int, string> $invoiceProductNames [product_id => name]
     * @return array<string, MatchResult>  بنفس مفاتيح $preparedByKey
     */
    public function match(array $preparedByKey, array $invoiceProductNames): array
    {
        $exactIndex = [];
        $approximateIndex = [];
        foreach ($invoiceProductNames as $productId => $name) {
            $exactIndex[MaterialNameKey::key($name)][] = (int) $productId;
            $approximateIndex[ArabicTextNormalizer::key($name)][] = (int) $productId;
        }

        $results = [];
        $unresolved = [];
        foreach ($preparedByKey as $key => $prepared) {
            $key = (string) $key;
            $candidates = $exactIndex[$key] ?? $approximateIndex[ArabicTextNormalizer::key($prepared['name'])] ?? [];
            $kind = isset($exactIndex[$key]) ? NameMatchKind::Exact : NameMatchKind::Approximate;

            $results[$key] = match (count($candidates)) {
                0 => null,
                1 => new MatchResult($kind, $candidates[0]),
                default => new MatchResult(NameMatchKind::Ambiguous),
            };

            if ($results[$key] === null) {
                $unresolved[$key] = $prepared['name'];
            }
        }

        $catalog = $this->catalogCandidates($unresolved);
        foreach ($unresolved as $key => $name) {
            $candidates = $catalog[$key] ?? [];
            $results[$key] = match (count($candidates)) {
                0 => new MatchResult(NameMatchKind::Unknown),
                1 => new MatchResult(NameMatchKind::NotInDocument, $candidates[0]),
                default => new MatchResult(NameMatchKind::Ambiguous),
            };
        }

        return $results;
    }

    /**
     * مرشحو الكتالوج بنفس الاسم — استعلام لكل دفعة لا لكل اسم. نجلب بالاسم كما بالملف وبمفتاحه
     * (يلتقط المسافات الزائدة)، ثم نُبقي فقط ما يطابق المفتاح المحافظ.
     *
     * @param array<string, string> $namesByKey
     * @return array<string, list<int>>
     */
    private function catalogCandidates(array $namesByKey): array
    {
        if ($namesByKey === []) {
            return [];
        }

        $lookup = array_values(array_unique([...array_values($namesByKey), ...array_map('strval', array_keys($namesByKey))]));
        $byKey = [];
        foreach (array_chunk($lookup, self::CATALOG_CHUNK) as $chunk) {
            foreach (DB::table('products')->whereIn('name', $chunk)->get(['id', 'name']) as $product) {
                $key = MaterialNameKey::key((string) $product->name);
                if (isset($namesByKey[$key])) {
                    $byKey[$key][(int) $product->id] = (int) $product->id;
                }
            }
        }

        return array_map('array_values', $byKey);
    }
}
