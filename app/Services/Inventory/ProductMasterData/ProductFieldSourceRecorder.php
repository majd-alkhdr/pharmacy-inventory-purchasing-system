<?php

declare(strict_types=1);

namespace App\Services\Inventory\ProductMasterData;

use App\Enums\ProductMasterField;
use App\Models\AuditLog;
use App\Models\ProductFieldSource;
use Illuminate\Support\Carbon;

/**
 * 🎯 المسؤولية الوحيدة: تحديث "آخر كاتب" لحقول المواد — من مكانين فقط: رفع ملف الأصناف (لكل حقل
 * حملت خليته قيمة فعلاً؛ الخلية الفارغة لا تكتب شيئاً فلا تُسجَّل)، والتصحيح اليدوي.
 *
 * upsert على (product_id, field): السطر الأحدث يحل محل السابق — الشاشة تحتاج "الآن" لا التاريخ
 * الكامل (التاريخ موجود أصلاً بسجل الرفع وسجل النشاط نفسيهما).
 */
final class ProductFieldSourceRecorder
{
    private const UPSERT_CHUNK = 1000;

    /**
     * @param  array<int, list<ProductMasterField>>  $fieldsByProductId  product_id ← الحقول التي حملت قيمة بهذا الرفع
     */
    public function recordUpload(int $importLogId, array $fieldsByProductId, ?Carbon $at = null): void
    {
        $at ??= now();
        $rows = [];

        foreach ($fieldsByProductId as $productId => $fields) {
            foreach ($fields as $field) {
                $rows[] = $this->row((int) $productId, $field, $importLogId, null, $at);
            }
        }

        foreach (array_chunk($rows, self::UPSERT_CHUNK) as $chunk) {
            ProductFieldSource::upsert($chunk, ['product_id', 'field'], ['import_log_id', 'audit_log_id', 'recorded_at']);
        }
    }

    public function recordManual(int $productId, ProductMasterField $field, AuditLog $audit): void
    {
        ProductFieldSource::upsert(
            [$this->row($productId, $field, null, $audit->id, now())],
            ['product_id', 'field'],
            ['import_log_id', 'audit_log_id', 'recorded_at'],
        );
    }

    /** @return array{product_id: int, field: string, import_log_id: ?int, audit_log_id: ?int, recorded_at: Carbon} */
    private function row(int $productId, ProductMasterField $field, ?int $importLogId, ?int $auditLogId, Carbon $at): array
    {
        return [
            'product_id' => $productId,
            'field' => $field->value,
            'import_log_id' => $importLogId,
            'audit_log_id' => $auditLogId,
            'recorded_at' => $at,
        ];
    }
}
