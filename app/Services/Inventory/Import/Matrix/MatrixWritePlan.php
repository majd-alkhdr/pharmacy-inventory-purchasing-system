<?php

declare(strict_types=1);

namespace App\Services\Inventory\Import\Matrix;

/**
 * 🎯 "ما سيُكتب" — خطة كاملة تُبنى بالذاكرة من تدفّق الصفوف، ثم تُنفَّذ **كلها** داخل
 * معاملة واحدة. فصل "القرار" (بناء الخطة) عن "التنفيذ" (الكتابة) يعني:
 *  - أي خطأ أثناء القراءة/التحليل يحدث قبل لمس قاعدة البيانات إطلاقاً.
 *  - الخطة قابلة للفحص والاختبار كبيانات.
 *
 * 🎓 لماذا الذاكرة كافية؟ الخطة **متناثرة** (Sparse): تحوي الخلايا غير الفارغة فقط
 * (+ أصفار مُثبَّتة لأزواج لها مخزون قياسي). بكشفك 34 ألف خلية من 216 ألفاً. حتى لو
 * تضاعف الكشف عشر مرات يبقى بحدود عشرات الميغابايتات. الحد: كشف كثيف جداً (ملايين
 * الخلايا المعبَّأة) يحتاج كتابة على دفعات — قرار لا حاجة له بحجم عملك الحالي.
 */
final class MatrixWritePlan
{
    /** @var array<int, int> معرّف المنتج ← كمية المركزي (كل مواد الملف، بما فيها الأصفار) */
    public array $centralByProduct = [];

    /** @var array<int, array<int, int>> معرّف الصيدلية ← [معرّف المنتج ← الكمية] */
    public array $snapshotByPharmacy = [];

    /** @var array<int, array{name: string, company: string}> منتجات موجودة تغيّر اسمها/شركتها بالملف */
    public array $productRefresh = [];

    /** @var array<string, array{barcode: string, material_name: string, company: string, quantity: int, pack_size: null, price: null, has_stock: bool}> */
    public array $unknownProducts = [];

    public int $productsInFile = 0;
    public int $zeroRowsMaterialized = 0;

    /** @param list<int> $pharmacyIds كل الصيدليات التي سيُستبدَل جردها (حتى لو بلا أي صف) */
    public function __construct(public readonly array $pharmacyIds)
    {
        foreach ($pharmacyIds as $pharmacyId) {
            $this->snapshotByPharmacy[$pharmacyId] = [];
        }
    }
}
