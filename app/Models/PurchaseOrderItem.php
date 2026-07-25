<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id', 'product_id', 'supplier_product_id',
        'suggested_qty', 'approved_qty', 'unit_cost', 'bonus_qty', 'line_total_cost',
        'is_target_item', 'is_market_shortage', 'is_over_budget_exception', 'priority_rank',
        'explanation_summary', 'explanation_factors',
        'ordered_qty', 'received_qty',
    ];

    protected function casts(): array
    {
        return [
            'unit_cost'                 => 'decimal:2',
            'line_total_cost'           => 'decimal:2',
            'is_target_item'            => 'boolean',
            'is_market_shortage'        => 'boolean',
            'is_over_budget_exception'  => 'boolean',
            'explanation_factors'       => 'array', // 🎯 JSON ↔ array PHP تلقائياً
        ];
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function supplierProduct()
    {
        return $this->belongsTo(SupplierProduct::class);
    }

    public function batches()
    {
        return $this->hasMany(InventoryBatch::class);
    }

    /** الكمية الفعلية النهائية = ما اعتمده المشرف، أو المقترح تلقائياً إن لم يُعدّله أحد */
    public function finalQuantity(): int
    {
        return $this->approved_qty ?? $this->suggested_qty;
    }

    /** 🎯 يخدم مباشرة منطق "قنص نواقص السوق" بعد مطابقة الاستلام الفعلي */
    public function receivingShortfall(): int
    {
        return max(0, ($this->ordered_qty ?? $this->finalQuantity()) - $this->received_qty);
    }

    public function isFullyReceived(): bool
    {
        return $this->receivingShortfall() === 0;
    }

    /**
     * 🎯 الكمية المتبقية "قيد الطريق" لهذا السطر — طُلبت لكن لم تُستلَم
     * بعد بالكامل. تُستخدم من OpenPurchaseOrderQuantityService لخصمها من
     * احتياج أي فاتورة مستقبلية لنفس المادة (تطبيق مباشر لـ"فحص الطلبيات
     * قيد التوريد" الموصوف بقسم 3 من مستندك — منع الازدواجية بالطلب).
     *
     * لو لم تُرسَل الفاتورة للمورد بعد (ordered_qty لا يزال NULL)، نعتبر
     * الكمية المعتمَدة (أو المقترحة إن لم تُعتمَد بعد) بالكامل "قيد الطريق"
     * افتراضياً — لأنها ستُطلب فعلياً قريباً.
     */
    public function outstandingQuantity(): int
    {
        $orderedOrPlanned = $this->ordered_qty ?? $this->finalQuantity();

        return max(0, $orderedOrPlanned - $this->received_qty);
    }
}
