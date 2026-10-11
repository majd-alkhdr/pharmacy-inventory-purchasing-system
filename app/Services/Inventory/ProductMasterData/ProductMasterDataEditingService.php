<?php

declare(strict_types=1);

namespace App\Services\Inventory\ProductMasterData;

use App\Enums\AuditAction;
use App\Enums\ProductMasterField;
use App\Models\CentralInventoryPrice;
use App\Models\Product;
use App\Services\Inventory\CentralInventoryPriceRecorderService;
use App\Services\Shared\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 تصحيح يدوي سريع لبيانات مادة — **ليس مصدر بيانات موازياً**.
 *
 * كل قيمة تُكتب في مكانها الوحيد المعتاد (products / central_inventory_prices)
 * تماماً كما يكتبها الرفع، فأول رفع لاحق لملف الحقل يحمل قيمة له يكتب فوقها بلا أي استثناء. لا علم
 * "تجاوز يدوي" ولا تخطٍّ عند الرفع — ذلك كان سيُعيد مشكلة المصدرين التي أُزيلت للتو.
 *
 * قواعد الإدخال (نفس منطق ملف الأصناف عمداً): الحقل الفارغ = "لا تغيير"، والقيمة المطابقة للمخزَّنة
 * لا تكتب شيئاً ولا تُسجَّل. كل حقل تغيّر فعلاً = سطر بسجل النشاط (قبل ← بعد) + تحديث "آخر كاتب"،
 * والكل بمعاملة واحدة.
 *
 * رصيد المستودع المركزي خارج نطاق هذه الخدمة عمداً (ProductMasterField::isEditable): هو الرصيد الذي
 * تُشحَن منه فواتير المناقلات، ومصدره الوحيد ملف المصفوفة.
 */
final class ProductMasterDataEditingService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CentralInventoryPriceRecorderService $priceRecorder,
        private readonly ProductFieldSourceRecorder $fieldSources,
    ) {
    }

    /**
     * @param  array<string, string|int|float|null>  $input  مفاتيح ProductMasterField->value؛ null = لا تغيير
     * @return list<ProductMasterField> الحقول التي تغيّرت فعلاً
     */
    public function apply(Product $product, array $input): array
    {
        return DB::transaction(function () use ($product, $input): array {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $changed = [];

            foreach (ProductMasterField::editable() as $field) {
                $new = self::normalize($field, $input[$field->value] ?? null);
                if ($new === null) {
                    continue;
                }

                $old = $this->currentValue($product, $field);
                if ($old !== null && $old === $new) {
                    continue;
                }

                $this->write($product, $field, $new);

                $auditEntry = $this->audit->record(
                    AuditAction::ProductMasterFieldCorrected,
                    $product,
                    [$field->value => $old],
                    [$field->value => $new],
                    ['field' => $field->value, 'overwritten_by_next' => $field->sourceFile()->value],
                );
                $this->fieldSources->recordManual($product->id, $field, $auditEntry);

                $changed[] = $field;
            }

            return $changed;
        });
    }

    /** قيمة الإدخال بشكلها المخزَّن نفسه، أو null إن كانت فارغة (= لا تغيير). */
    private static function normalize(ProductMasterField $field, string|int|float|null $raw): string|int|float|null
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return null;
        }

        return match ($field) {
            ProductMasterField::Name, ProductMasterField::Company => trim((string) $raw),
            ProductMasterField::Price => round((float) $raw, 2),
            ProductMasterField::PackSize, ProductMasterField::MinReorderGap => (int) $raw,
            ProductMasterField::CentralQuantity => throw new \LogicException('رصيد المستودع المركزي للعرض فقط.'),
        };
    }

    private function currentValue(Product $product, ProductMasterField $field): string|int|float|null
    {
        return match ($field) {
            ProductMasterField::Name => $product->name,
            ProductMasterField::Company => $product->company,
            ProductMasterField::Price => CentralInventoryPrice::latestFor($product->id),
            ProductMasterField::PackSize => $product->pack_size === null ? null : (int) $product->pack_size,
            ProductMasterField::MinReorderGap => $product->min_reorder_gap === null ? null : (int) $product->min_reorder_gap,
            ProductMasterField::CentralQuantity => throw new \LogicException('رصيد المستودع المركزي للعرض فقط.'),
        };
    }

    private function write(Product $product, ProductMasterField $field, string|int|float $value): void
    {
        match ($field) {
            ProductMasterField::Name, ProductMasterField::Company,
            ProductMasterField::PackSize, ProductMasterField::MinReorderGap => $product->forceFill([$field->value => $value])->save(),
            // نفس كاتب السعر الذي يستخدمه ملف الأصناف — سطر سعر جديد يصبح "سعر المادة" الحالي.
            ProductMasterField::Price => $this->priceRecorder->recordChanges([$product->id => (float) $value]),
            ProductMasterField::CentralQuantity => throw new \LogicException('رصيد المستودع المركزي للعرض فقط.'),
        };
    }
}
