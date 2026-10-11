<?php

namespace App\Imports;

use App\Models\TemporarySale;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Illuminate\Support\Collection;

/**
 * 🩹 [إصلاح جوهري 2026-09] كان هذا الملف يُطبِّق `(int) ceil($rawQuantity)`
 * على كل سطر خام هنا مباشرة — قبل أي تجميع لاحق. التعليق الأصلي بهذا
 * الملف نفسه قال "قراءة القيمة كعدد عشري أولاً **للحفاظ على الكسور**"،
 * لكن السطر التالي مباشرة كان يُدمِّر ذلك الكسر فوراً بلا أي محاولة حفظ
 * فعلية — تناقض بين النية المُعلَنة والتنفيذ الفعلي.
 *
 * الأثر العملي المؤكَّد: بيع نصف عبوة (ظرف من عبوة ظرفين = 0.5) مرتين
 * بدفعتين منفصلتين (0.5 + 0.5 = 1 بالضبط فعلياً) كان يُترجَم لشحن
 * ceil(0.5)+ceil(0.5) = 1+1 = **2** — ضعف الكمية الحقيقية المُباعة،
 * بشكل منهجي ومتكرر مع كل بيع جزئي.
 *
 * الحل: هذا الملف الآن **لا يُقرِّب الكسر إطلاقاً** — يحفظ الكمية الخام
 * كما هي (بدعم عمود quantity الآن decimal، راجع مايجريشن
 * change_quantity_to_decimal...)، ويترك قرار "متى نصل لعدد صحيح جاهز
 * للشحن" حصراً لـ SalesRemainderResolverService، التي تجمع هذا الكسر مع
 * أي باقٍ متراكم من دفعات سابقة لنفس الزوج (صيدلية+مادة) قبل التقريب.
 *
 * 🆕 (2026-09) [إعادة تصميم الاستثناء] الشركات المستثناة تُقرأ الآن من
 * قائمة دائمة (ExcludedCompany)، لا من اختيار خاص بكل دفعة رفع. أي سطر
 * بهذا الملف لشركة مستثناة **يُتجاهَل بالكامل هنا** — لا يصل لـ temporary_sales
 * إطلاقاً، كأنه لم يُكتب بالملف أصلاً. هذا يجعل الاستثناء فعلياً حقيقياً:
 * لا فاتورة أساسية، ولا نقص يُسجَّل، ولا أي أثر آخر — بدل الاعتماد على
 * فحوصات لاحقة متفرقة بخدمات الترميم المختلفة.
 */
class SalesImport implements ToCollection, WithStartRow, WithChunkReading, WithBatchInserts
{
    public function chunkSize(): int
    {
        return 1000;
    }
    public function batchSize(): int
    {
        return 1000;
    }

    protected $sessionId;
    private array $excludedCompanies; // 🆕

    public function __construct($sessionId)
    {
        $this->sessionId = $sessionId;
        $this->excludedCompanies = \App\Models\ExcludedCompany::currentList(); // 🆕
    }

    public function startRow(): int
    {
        return 2;
    }

    public function collection(Collection $rows)
    {
        $records = [];

        foreach ($rows as $row) {
            // ✅ رقم المادة[0] - شركة[1] - مادة[2] - صيدلية[3] - كمية[4]
            $barcode      = trim($row[0] ?? '');
            $company      = trim($row[1] ?? '') ?: '-';
            $materialName = trim($row[2] ?? '');
            $pharmacyName = trim($row[3] ?? '');

            // 🆕 شركة مستثناة = تجاهل صريح لهذا السطر، قبل أي معالجة أخرى
            if (in_array($company, $this->excludedCompanies, true)) {
                continue;
            }

            // 🩹 [مُصحَّح] لم نعد نُقرِّب الكسر هنا إطلاقاً — راجع تعليق
            // الكلاس أعلاه للسبب الكامل. round(...,4) هنا فقط لتفادي
            // أرقام عشرية طويلة بلا معنى ناتجة عن قراءة الملف نفسها
            // (مثل 0.33333333333)، لا لتقريب "للأعلى/الأسفل" بأي معنى
            // عملي — القيمة تبقى كسرية كاملة كما كتبها المستخدم بالملف.
            $rawQuantity = round(floatval($row[4] ?? 0), 4);

            if (!$pharmacyName || !$materialName || !$barcode || $rawQuantity <= 0) continue;

            $records[] = [
                'session_id'    => $this->sessionId,
                'pharmacy_name' => $pharmacyName,
                'company'       => $company,
                'material_name' => $materialName,
                'barcode'       => $barcode,
                'quantity'      => $rawQuantity,
                'pharmacy_id'   => null,
                'product_id'    => null,
                'created_at'    => now(),
                'updated_at'    => now(),
            ];
        }

        foreach (array_chunk($records, 500) as $chunk) {
            TemporarySale::insert($chunk);
        }
    }
}
