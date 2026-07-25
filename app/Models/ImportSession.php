<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportSession extends Model
{
    protected $fillable = [
        'session_id',
        'type',
        'status',
        'message',
        'has_sales',
        'inventory_file_path',
        'sales_file_path',
        'shortages_file_path',
    ];

    protected $casts = ['has_sales' => 'boolean'];

    /**
     * 🎯 نُقل من InventoryController::importStatus()
     * الكونترولر كان يحتوي منطق "أي حالة تذهب لأي رابط" — هذا منطق خاص
     * ببيانات الجلسة نفسها، مكانه الطبيعي هو الـ Model (أو Enum لاحقاً
     * إذا الحالات ثبتت وما عاد رح تتغير).
     */
    public function redirectRouteName(): ?string
    {
        return match ($this->status) {
            'needs_pending_products' => 'inventory.pending-inventory.show',
            'needs_mapping' => $this->type === 'shortages'
                ? 'inventory.shortages.mapping.show'
                : 'inventory.mapping.show',
            default => null,
        };
    }

    public function resolveRedirectUrl(): ?string
    {
        $routeName = $this->redirectRouteName();

        return $routeName ? route($routeName, $this->session_id) : null;
    }
}
