<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\ShortageSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    // 🩹 [إصلاح] 'source' كان غائباً هنا رغم أن InvoiceGenerationService وParLevelReplenishmentService
    // يمرّرانه بـ Invoice::create([...]). Laravel يتجاهل الحقول غير المسموحة بالتعبئة **بصمت**، فبقي
    // invoices.source = NULL دائماً، وسقطت كل فاتورة بخانة "قديم" بكشف تجهيز الطلبيات
    // (PreparationExport) بدل "من المبيعات" / "من المخزون القياسي".
    protected $fillable = ['pharmacy_id', 'date', 'status', 'source', 'exported_at'];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'date'   => 'date',
            'source' => ShortageSource::class, // 🆕 NULL للفواتير القديمة (قبل تتبع المصدر) يبقى NULL
            'exported_at' => 'datetime', // 🆕 (ب.2)
        ];
    }

    /** 🆕 (ب.2) التعديل متاح حتى أول تصدير فقط — راجع مايجريشن add_editing_columns_to_invoices. */
    public function isEditable(): bool
    {
        return $this->exported_at === null;
    }

    public function pharmacy()
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    // ══════════════════════════════════════════════════════════
    // 🎯 نُقلت من InventoryController::invoicesIndex()
    // ══════════════════════════════════════════════════════════

    public function scopeOnDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('date', $date);
    }

    public function scopeForPharmacy(Builder $query, ?int $pharmacyId): Builder
    {
        return $pharmacyId ? $query->where('pharmacy_id', $pharmacyId) : $query;
    }

    public function scopeOfStatus(Builder $query, ?string $status): Builder
    {
        return $status ? $query->where('status', $status) : $query;
    }

    public function scopeWithAvailability(Builder $query, ?string $availability): Builder
    {
        if (!$availability) {
            return $query;
        }

        return $query->whereHas('items', fn ($q) => $q->availability($availability));
    }

    /**
     * 🎯 يستبدل الاستعلام الكامل الذي كان مبنياً يدوياً بالكونترولر
     * (whereDate + withCount + whereHas + where status + where pharmacy_id)
     *
     * @param array{status?:string,pharmacy_id?:int,availability?:string,date:string} $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->onDate($filters['date'])
            ->ofStatus($filters['status'] ?? null)
            ->forPharmacy($filters['pharmacy_id'] ?? null)
            ->withAvailability($filters['availability'] ?? null)
            ->withCount(['items as items_count' => fn ($q) => $q->availability($filters['availability'] ?? null)]);
    }

    /**
     * 🎯 نفس نمط Shortage::isPending() بالضبط — بديل آمن لمقارنة
     * $invoice->status === 'processed' يدوياً بالـ Views. بعد إضافة
     * الـ Enum Cast، أي مقارنة نصية مباشرة بالـ status صارت تُرجع false
     * دائماً؛ هذه الميثود توفّر طريقة صحيحة وموحّدة للتحقق من النوع.
     */
    public function isProcessed(): bool
    {
        return $this->status === InvoiceStatus::Processed;
    }
}
