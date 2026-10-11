<?php

declare(strict_types=1);

namespace App\Services\Inventory\InvoiceEditing;

use App\Enums\AuditAction;
use App\Models\CentralInventory;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Services\Shared\Audit\AuditLogger;
use App\Services\Shared\Rounding\QuantityRounder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 🎯 (ب.2) يطبّق تعديلات المستخدم على فاتورة مناقلة مولَّدة قبل تصديرها: تغيير كمية، حذف
 * مادة، إضافة مادة — كلها بمعاملة واحدة مع سجل النشاط (قاعدة 3): إما يُحفظ كل التعديل
 * وسجله معاً، أو لا شيء.
 *
 * 🎓 لماذا يُعدَّل المخزون المركزي بفرق كل تعديل؟ الفاتورة عند توليدها **خصمت** كميتها من
 * central_inventories فعلاً (المحركات الثلاثة تفعل ذلك داخل معاملتها). لو خفّض المستخدم
 * الكمية من 10 إلى 6 دون إعادة 4 للمركزي، لبقي المركزي ناقصاً 4 وحدات وهمية — فيظهر عمود
 * "الكمية المتوفرة في الصناعة" بالمشتريات (ج.2) أقل من الحقيقة، ويُقترح شراء ما هو موجود.
 * لذلك: تخفيض/حذف ⇒ يُعاد الفرق للمركزي، زيادة/إضافة ⇒ يُخصم بشرط توفّره (بقفل).
 *
 * 🎓 قاعدة التدوير (أ.5): الكمية الخام كما أدخلها المستخدم تُدوَّر **مرة واحدة** هنا عبر
 * QuantityRounder — لا بالواجهة ولا بالطلب. كمية نهائية صفر تُعامَل كحذف (بند بلا شحن لا
 * معنى له بمستند تحضير، والتقارير تتجاهله أصلاً).
 */
final class InvoiceEditingService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly QuantityRounder $rounder,
    ) {
    }

    /**
     * @param array<int, string|int|float> $quantityChanges [item_id => الكمية الخام]
     * @param list<int> $removals
     * @param list<array{product_id: int, quantity: string|int|float}> $additions
     */
    public function apply(Invoice $invoice, array $quantityChanges, array $removals, array $additions): InvoiceEditSummary
    {
        return DB::transaction(function () use ($invoice, $quantityChanges, $removals, $additions): InvoiceEditSummary {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (!$invoice->isEditable()) {
                throw new RuntimeException("فاتورة المناقلة #{$invoice->id} صُدِّرت بتاريخ {$invoice->exported_at->format('Y-m-d H:i')} — لا يمكن تعديلها بعد التصدير.");
            }

            $items = InvoiceItem::where('invoice_id', $invoice->id)->with('product:id,name')->lockForUpdate()->get()->keyBy('id');
            $plan = $this->plan($invoice, $items, $quantityChanges, $removals, $additions);

            $this->applyCentralDeltas($plan->centralDeltas);

            foreach ($plan->updates as $itemId => $newQty) {
                $this->updateQuantity($invoice, $items[$itemId], $newQty);
            }
            foreach ($plan->removals as $itemId) {
                $this->remove($invoice, $items[$itemId]);
            }
            foreach ($plan->additions as $productId => $qty) {
                $this->add($invoice, $productId, $qty);
            }

            return new InvoiceEditSummary(count($plan->updates), count($plan->removals), count($plan->additions));
        });
    }

    /**
     * يحوّل مدخلات المستخدم إلى خطة نظيفة قبل أي كتابة: تدوير مرة واحدة، تجاهل ما لم يتغيّر،
     * صفر = حذف، إضافة مادة موجودة أصلاً = زيادة على سطرها (لا سطرين لنفس المادة بمستند واحد).
     *
     * @param \Illuminate\Support\Collection<int, InvoiceItem> $items
     */
    private function plan(Invoice $invoice, $items, array $quantityChanges, array $removals, array $additions): InvoiceEditPlan
    {
        $plan = new InvoiceEditPlan();
        $removals = array_values(array_intersect(array_unique($removals), $items->keys()->all()));

        foreach ($removals as $itemId) {
            $plan->removals[] = $itemId;
            $plan->addCentralDelta((int) $items[$itemId]->product_id, (int) $items[$itemId]->supplied_qty);
        }

        foreach ($quantityChanges as $itemId => $raw) {
            if (!$items->has($itemId) || in_array($itemId, $plan->removals, true)) {
                continue;
            }

            $new = $this->rounder->toWholeUnits($raw);
            $current = (int) $items[$itemId]->supplied_qty;

            if ($new === 0) {
                $plan->removals[] = $itemId;
                $plan->addCentralDelta((int) $items[$itemId]->product_id, $current);
            } elseif ($new !== $current) {
                $plan->updates[$itemId] = $new;
                $plan->addCentralDelta((int) $items[$itemId]->product_id, $current - $new);
            }
        }

        $itemIdByProduct = $items->reject(fn (InvoiceItem $i) => in_array($i->id, $plan->removals, true))
            ->mapWithKeys(fn (InvoiceItem $i) => [(int) $i->product_id => $i->id]);

        foreach ($additions as $row) {
            $qty = $this->rounder->toWholeUnits($row['quantity']);
            if ($qty === 0) {
                continue;
            }

            $existingId = $itemIdByProduct->get($row['product_id']);
            if ($existingId !== null) {
                $base = $plan->updates[$existingId] ?? (int) $items[$existingId]->supplied_qty;
                $plan->updates[$existingId] = $base + $qty;
            } else {
                $plan->additions[$row['product_id']] = ($plan->additions[$row['product_id']] ?? 0) + $qty;
            }
            $plan->addCentralDelta($row['product_id'], -$qty);
        }

        return $plan;
    }

    /**
     * فرق موجب = يُعاد للمركزي، سالب = يُخصم منه بشرط التوفّر. الأقفال تُؤخذ قبل أي كتابة
     * فلا يتسابق تعديلان (أو تعديل وترميم) على نفس الرصيد.
     *
     * @param array<int, int> $deltas [product_id => فرق]
     */
    private function applyCentralDeltas(array $deltas): void
    {
        $deltas = array_filter($deltas);
        if ($deltas === []) {
            return;
        }

        $stocks = CentralInventory::whereIn('product_id', array_keys($deltas))->lockForUpdate()->get()->keyBy('product_id');

        foreach ($deltas as $productId => $delta) {
            $available = (int) ($stocks->get($productId)?->quantity ?? 0);

            if ($available + $delta < 0) {
                $name = Product::whereKey($productId)->value('name') ?? "#{$productId}";
                throw new RuntimeException("لا يكفي رصيد المستودع المركزي لزيادة «{$name}»: المتاح {$available}، والمطلوب إضافياً " . (-$delta) . '.');
            }

            CentralInventory::updateOrCreate(['product_id' => $productId], ['quantity' => $available + $delta]);
        }
    }

    private function updateQuantity(Invoice $invoice, InvoiceItem $item, int $newQty): void
    {
        $before = (int) $item->supplied_qty;
        $systemQty = $item->systemQuantity();

        $item->update([
            'supplied_qty' => $newQty,
            // أول تعديل يثبّت "ما اقترحه النظام" — التعديلات اللاحقة لا تكتب فوقه.
            'system_supplied_qty' => $item->system_supplied_qty ?? ($item->is_manually_added ? 0 : $before),
        ]);

        $this->audit->recordChange(AuditAction::InvoiceItemQuantityChanged, $item, 'supplied_qty', $before, $newQty, $this->context($invoice, $item, $systemQty));
    }

    private function remove(Invoice $invoice, InvoiceItem $item): void
    {
        // لقطة كاملة قبل الحذف الفعلي — هي الأثر الوحيد الباقي للبند (راجع المايجريشن).
        $this->audit->record(
            AuditAction::InvoiceItemRemoved,
            $item,
            ['supplied_qty' => (int) $item->supplied_qty, 'requested_qty' => (int) $item->requested_qty, 'shortage_qty' => (int) $item->shortage_qty],
            null,
            $this->context($invoice, $item, $item->systemQuantity()),
        );

        $item->delete();
    }

    private function add(Invoice $invoice, int $productId, int $qty): void
    {
        $item = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $productId,
            'requested_qty' => $qty,
            'supplied_qty' => $qty,
            'shortage_qty' => 0,
            'system_supplied_qty' => 0,
            'is_manually_added' => true,
        ]);

        $this->audit->record(AuditAction::InvoiceItemAdded, $item, null, ['supplied_qty' => $qty], $this->context($invoice, $item->load('product:id,name'), 0));
    }

    /** @return array<string, mixed> */
    private function context(Invoice $invoice, InvoiceItem $item, int $systemQty): array
    {
        return [
            'invoice_id' => $invoice->id,
            'product_id' => (int) $item->product_id,
            'product_name' => $item->product?->name,
            'system_suggested_qty' => $systemQty,
        ];
    }
}
