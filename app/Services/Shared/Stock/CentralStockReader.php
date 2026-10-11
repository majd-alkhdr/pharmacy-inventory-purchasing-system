<?php

declare(strict_types=1);

namespace App\Services\Shared\Stock;

use App\Models\CentralInventory;

/**
 * 🎯 المصدر الوحيد لـ"رصيد المستودع المركزي" بقسم المشتريات: جدول central_inventories،
 * الذي يملؤه ملف المصفوفة (كشف المستودعات) عند كل رفع، وتخصم منه فواتير المناقلات بين الرفعات.
 *
 * 🎓 لماذا قارئ مشترك بدل استعلام مباشر بكل مستهلك؟ كان الرصيد يُقرأ سابقاً من مصدرين مختلفين
 * بنفس الشاشة: الكمية المقترحة من inventory_batches (ملف جرد المشتريات، أُزيل) وعمود "المتوفر
 * بالصناعة" من central_inventories — فيظهر رقمان مختلفان لنفس المادة. بمصدر واحد يقرؤه الجميع
 * (مقدِّما الاحتياج، حاسبة الدوران، تفاصيل الفاتورة وتصديرها) يستحيل هذا الانحراف بنيوياً.
 */
final class CentralStockReader
{
    /**
     * @param  iterable<int>  $productIds
     * @return array<int, int> product_id ← الرصيد (مادة بلا صف = غائبة؛ استخدم ?? 0)
     */
    public function quantitiesFor(iterable $productIds): array
    {
        $ids = collect($productIds)->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return CentralInventory::whereIn('product_id', $ids)
            ->pluck('quantity', 'product_id')
            ->map(fn ($qty) => (int) $qty)
            ->all();
    }

    public function quantityFor(int $productId): int
    {
        return $this->quantitiesFor([$productId])[$productId] ?? 0;
    }
}
