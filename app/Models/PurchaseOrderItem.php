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
        'ordered_qty', 'received_qty', 'received_at', 'cancelled_qty', 'receiving_note',
    ];

    protected function casts(): array
    {
        return [
            'unit_cost'                 => 'decimal:2',
            'line_total_cost'           => 'decimal:2',
            'is_target_item'            => 'boolean',
            'is_market_shortage'        => 'boolean',
            'is_over_budget_exception'  => 'boolean',
            'explanation_factors'       => 'array',
            'received_at'               => 'datetime', // 🆕 وقت آخر استلام فعلي — مصدر products.last_purchase_date
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

    public function finalQuantity(): int
    {
        return $this->approved_qty ?? $this->suggested_qty;
    }

    public function receivingShortfall(): int
    {
        return max(0, ($this->ordered_qty ?? $this->finalQuantity()) - $this->received_qty);
    }

    public function isFullyReceived(): bool
    {
        return $this->receivingShortfall() === 0;
    }

    /**
     * 🆕 إصلاح جوهري (2026-08، بمناسبة سيناريو "التدقيق اليدوي" الجديد):
     * الكمية "قيد الطريق" الآن تستثني صراحة أي جزء سبق تسجيله كـ
     * cancelled_qty — بغض النظر عن حالة الفاتورة نفسها (Draft، PartiallyReceived،
     * إلخ). هذا يطبّق مباشرة طلب صاحب المشروع: "الكمية غير المستلَمة يجب
     * أن تعود للاحتياج عند توليد فواتير شراء جديدة" — بمجرد تسجيلها
     * كملغاة (سواء عبر "إلغاء المتبقي" اليدوي القديم، أو عبر اختيار
     * "عدم استلام"/"استلام جزئي" بشاشة التدقيق اليدوي الجديدة)، تتوقف
     * فوراً عن اعتبارها "قادمة قريباً" بمعادلة DemandCalculationService،
     * فيرتفع الاحتياج المحسوب تلقائياً بالفاتورة التالية ليغطيها من جديد.
     *
     * قبل هذا الإصلاح: كانت تعتمد فقط على استبعاد الفاتورة كلها بحالة
     * PartiallyCancelled عبر OpenPurchaseOrderQuantityService — وهذا كان
     * يعمل فقط لو أُلغيت الفاتورة بالكامل، وليس على مستوى سطر واحد ضمن
     * فاتورة لا تزال "قيد الاستلام الجزئي" بحالتها العامة.
     */
    public function outstandingQuantity(): int
    {
        $orderedOrPlanned = $this->ordered_qty ?? $this->finalQuantity();

        return max(0, $orderedOrPlanned - $this->received_qty - $this->cancelled_qty);
    }

    public function hasCancelledQuantity(): bool
    {
        return $this->cancelled_qty > 0;
    }
}
