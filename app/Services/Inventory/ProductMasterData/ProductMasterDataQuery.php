<?php

declare(strict_types=1);

namespace App\Services\Inventory\ProductMasterData;

use App\Enums\AuditAction;
use App\Enums\ProductMasterField;
use App\Models\AuditLog;
use App\Models\CentralInventoryPrice;
use App\Models\ImportLog;
use App\Models\Product;
use App\Models\ProductFieldSource;
use App\Services\Shared\Stock\CentralStockReader;
use Illuminate\Support\Collection;

/**
 * 🎯 قراءة شاشة "بيانات المادة": القيمة الحالية لكل حقل من مكانها الوحيد + آخر كاتب لها.
 *
 * مصدر "آخر تحديث" لكل حقل:
 *  - حقول ملف الأصناف: سطر product_field_sources (رفع حمل قيمة لهذا الحقل، أو تصحيح يدوي).
 *    السعر استثناء جزئي: ملفات أخرى (الجرد المركزي، المواد الجديدة) تكتب سعراً بلا سجل لكل مادة —
 *    إن كان آخر سطر سعر أحدث من آخر كاتب مسجَّل، يُعرض "ملف آخر" بوقته بدل نسب القيمة لكاتب خاطئ.
 *  - رصيد المركزي (للعرض فقط): يُستبدَل بالكامل مع كل رفع للكشف/الجرد المركزي ⇒ آخر رفع ناجح لأيهما.
 */
final class ProductMasterDataQuery
{
    public function __construct(
        private readonly CentralStockReader $centralStock,
    ) {
    }

    /** @return list<ProductFieldSnapshot> */
    public function snapshot(Product $product): array
    {
        $sources = ProductFieldSource::where('product_id', $product->id)
            ->with(['importLog.user:id,name', 'auditLog.user:id,name'])
            ->get()
            ->keyBy(fn (ProductFieldSource $s) => $s->field->value);

        $latestPriceRow = CentralInventoryPrice::where('product_id', $product->id)->orderByDesc('id')->first();

        $snapshots = [];
        foreach (ProductMasterField::cases() as $field) {
            $recorded = $this->fromRecorded($sources->get($field->value));

            $snapshots[] = new ProductFieldSnapshot($field, $this->valueOf($product, $field, $latestPriceRow), match ($field) {
                ProductMasterField::CentralQuantity => $this->quantitySource($field),
                ProductMasterField::Price => $this->priceSource($recorded, $latestPriceRow),
                default => $recorded,
            });
        }

        return $snapshots;
    }

    /** @return Collection<int, AuditLog> سجل التصحيحات اليدوية لهذه المادة، الأحدث أولاً */
    public function corrections(Product $product): Collection
    {
        return AuditLog::query()
            ->forSubject($product)
            ->where('action', AuditAction::ProductMasterFieldCorrected)
            ->with('user:id,name')
            ->latest('id')
            ->limit(50)
            ->get();
    }

    private function valueOf(Product $product, ProductMasterField $field, ?CentralInventoryPrice $priceRow): string|int|float|null
    {
        return match ($field) {
            ProductMasterField::Name => $product->name,
            ProductMasterField::Company => $product->company,
            ProductMasterField::Price => $priceRow ? (float) $priceRow->price : null,
            ProductMasterField::PackSize => $product->pack_size === null ? null : (int) $product->pack_size,
            ProductMasterField::MinReorderGap => $product->min_reorder_gap === null ? null : (int) $product->min_reorder_gap,
            ProductMasterField::CentralQuantity => $this->centralStock->quantitiesFor([$product->id])[$product->id] ?? null,
        };
    }

    private function fromRecorded(?ProductFieldSource $source): ?FieldSourceInfo
    {
        return match (true) {
            $source === null => null,
            $source->auditLog !== null => FieldSourceInfo::manual($source->auditLog, $source->recorded_at),
            $source->importLog !== null => FieldSourceInfo::upload($source->importLog, $source->recorded_at),
            default => null, // السجل المرجعي حُذف — لا نختلق مصدراً
        };
    }

    private function quantitySource(ProductMasterField $field): ?FieldSourceInfo
    {
        $lastUpload = ImportLog::latestSuccessful($field->wholesaleReplacementFiles());
        $lastUpload?->loadMissing('user:id,name');

        return $lastUpload ? FieldSourceInfo::upload($lastUpload) : null;
    }

    private function priceSource(?FieldSourceInfo $recorded, ?CentralInventoryPrice $latestPriceRow): ?FieldSourceInfo
    {
        if ($latestPriceRow === null) {
            return $recorded;
        }

        // created_at بالثانية؛ السجل يُكتب دائماً بعد سطر السعر بنفس العملية ⇒ لا يسبقه أبداً.
        if ($recorded === null || $latestPriceRow->created_at->greaterThan($recorded->at)) {
            return FieldSourceInfo::other($latestPriceRow->created_at);
        }

        return $recorded;
    }
}
