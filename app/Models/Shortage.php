<?php

namespace App\Models;

use App\Enums\ShortageStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Shortage extends Model
{
    protected $fillable = ['pharmacy_id', 'product_id', 'quantity', 'status', 'notes'];

    protected function casts(): array
    {
        return [
            'status' => ShortageStatus::class, // ✅ بدل النص الحر
        ];
    }

    public function pharmacy()
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // ══════════════════════════════════════════════════════════
    // 🎯 Scopes — نُقلت هنا من InventoryController::buildShortagesFilterQuery()
    // الفائدة: أي مكان بالتطبيق (Controller, Export, Command) يحتاج نفس
    // الفلترة يستخدم نفس السطر بدل إعادة كتابة شروط WHERE يدوياً، فتضمن أن
    // كل الأرقام بالتطبيق (الجدول + الإحصائيات + التصدير) مبنية على نفس
    // التعريف بالضبط لكلمة "نقص pending".
    // ══════════════════════════════════════════════════════════

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ShortageStatus::Pending);
    }

    public function scopeForPharmacy(Builder $query, ?int $pharmacyId): Builder
    {
        return $pharmacyId ? $query->where('pharmacy_id', $pharmacyId) : $query;
    }

    public function scopeCreatedFrom(Builder $query, ?string $date): Builder
    {
        return $date ? $query->whereDate('created_at', '>=', $date) : $query;
    }

    public function scopeCreatedTo(Builder $query, ?string $date): Builder
    {
        return $date ? $query->whereDate('created_at', '<=', $date) : $query;
    }

    /**
     * 🎯 هذا الـ scope يستبدل buildShortagesFilterQuery() بالكامل.
     * بدل ما الكونترولر يبني شروط WHERE يدوياً، هو بس يمرر مصفوفة الفلاتر.
     *
     * @param array{status?:string,pharmacy_id?:int,date_from?:string,date_to?:string} $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when(
                !empty($filters['status']),
                fn(Builder $q) => $q->where('status', $filters['status']),
                fn(Builder $q) => $q->pending() // الافتراضي: المعلقة فقط (نفس السلوك الأصلي)
            )
            ->forPharmacy($filters['pharmacy_id'] ?? null)
            ->createdFrom($filters['date_from'] ?? null)
            ->createdTo($filters['date_to'] ?? null);
    }

    public function isPending(): bool
    {
        return $this->status === ShortageStatus::Pending;
    }

    public function appendResolutionNote(string $note): string
    {
        return $this->notes ? "{$this->notes} | {$note}" : $note;
    }
}
