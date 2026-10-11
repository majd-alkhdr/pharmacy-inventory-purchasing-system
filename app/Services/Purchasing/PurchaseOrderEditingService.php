<?php

namespace App\Services\Purchasing;

use App\Enums\AuditAction;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\Shared\Audit\AuditLogger;
use App\Services\Shared\Rounding\QuantityRounder;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 يسدّ فجوة كشفتها الإدارة بعد التجربة الفعلية: "يجب إمكانية تعديل
 * الفاتورة (لا فقط اعتماد أو رفض بشكلها المقترح كما هو)."
 *
 * 🎓 لاحظ أن عمود approved_qty كان موجوداً أصلاً بجدول
 * purchase_order_items منذ التصميم الأول للنظام (نية واضحة من البداية
 * لدعم "كمية معتمَدة تختلف عن الكمية المقترحة")، لكن لم يكن هناك أي
 * مسار فعلي بالواجهة لتحديثه — هذه الخدمة تُفعِّل هذه النية أخيراً.
 *
 * القيود: التعديل متاح فقط لفاتورة بحالة "مسودة" (isEditable())، بنفس
 * قيود الاعتماد/الرفض الحالية — لا يمكن تعديل فاتورة مُعتمَدة أو مُرسَلة
 * أو مستلَمة، حفاظاً على سلامة السجل التاريخي بعد أي قرار نهائي.
 *
 * 🆕 (ج.2) حقلان منفصلان صراحة: suggested_qty (ما حسبه النظام — لا يُكتب فوقه أبداً) و
 * approved_qty (ما يعتمده المستخدم). كل تغيير بالمعتمدة يُسجَّل بسجل النشاط عبر **نفس**
 * AuditLogger::recordChange() الذي تستخدمه فواتير المناقلة (ب.2) — لا نسخة موازية — وبنفس
 * المعاملة. والكمية المعتمدة تمر بقاعدة التدوير الموحَّدة (أ.5) مرة واحدة على القيمة الخام.
 */
class PurchaseOrderEditingService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly QuantityRounder $rounder,
    ) {}

    /**
     * @param array<int, array{approved_qty:int|float|string}> $itemsInput [item_id => ['approved_qty' => قيمة خام]]
     * @param array<int> $itemIdsToRemove أسطر يُراد حذفها بالكامل من الفاتورة
     */
    public function updateItems(PurchaseOrder $order, array $itemsInput, array $itemIdsToRemove = []): void
    {
        if (!$order->isEditable()) {
            throw new \RuntimeException('لا يمكن تعديل فاتورة ليست بحالة "مسودة".');
        }

        DB::transaction(function () use ($order, $itemsInput, $itemIdsToRemove) {
            $items = PurchaseOrderItem::where('purchase_order_id', $order->id)->with('product:id,name')->lockForUpdate()->get()->keyBy('id');
            $itemIdsToRemove = array_map('intval', $itemIdsToRemove);

            foreach ($itemIdsToRemove as $itemId) {
                $item = $items->get($itemId);
                if ($item === null) {
                    continue;
                }

                $this->audit->record(AuditAction::PurchaseItemRemoved, $item, [
                    'suggested_qty' => (int) $item->suggested_qty,
                    'approved_qty' => $item->finalQuantity(),
                ], null, $this->context($order, $item));

                $item->delete();
            }

            foreach ($itemsInput as $itemId => $input) {
                $item = $items->get((int) $itemId);
                if ($item === null || in_array((int) $itemId, $itemIdsToRemove, true)) {
                    continue; // 🩹 حُذف للتو أعلاه — لا داعي لتحديثه أيضاً
                }

                $before = $item->finalQuantity();
                $after = $this->rounder->toWholeUnits($input['approved_qty']);

                $item->update(['approved_qty' => $after]);
                $this->audit->recordChange(AuditAction::PurchaseItemApprovedQtyChanged, $item, 'approved_qty', $before, $after, $this->context($order, $item));
            }

            // 🎯 إعادة حساب الإجمالي التقديري بعد التعديل — حالياً بلا أثر
            // فعلي (unit_cost = 0 دائماً بمحرك القرار الحالي، راجع وثيقة
            // التصميم §9)، لكن نُبقي هذا السطر جاهزاً لحظة عودة الأسعار
            // مستقبلاً، بدل نسيان تحديث total_estimated_cost حينها.
            $order->refresh();
            $order->update([
                'total_estimated_cost' => round(
                    (float) $order->items()->get()->sum(
                        fn (PurchaseOrderItem $i) => $i->unit_cost * $i->finalQuantity()
                    ),
                    2
                ),
            ]);
        });
    }

    /** @return array<string, mixed> */
    private function context(PurchaseOrder $order, PurchaseOrderItem $item): array
    {
        return [
            'purchase_order_id' => $order->id,
            'product_id' => (int) $item->product_id,
            'product_name' => $item->product?->name,
            'suggested_qty' => (int) $item->suggested_qty,
        ];
    }
}
