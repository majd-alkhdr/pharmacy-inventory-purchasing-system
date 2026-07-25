<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Pharmacy extends Model
{
    protected $fillable = ['name'];

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function shortages()
    {
        return $this->hasMany(Shortage::class)->pending(); // ✅ يستخدم الـ scope الجديد بدل ->where('status','pending')
    }

    /** 🎯 نُقل من InventoryController::searchPharmacies() */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where('name', 'LIKE', "%{$term}%");
    }

    /** تنسيق موحّد لاستجابة select2 (id/text) — يمنع تكرار ->map() بالكونترولر */
    public function toSelect2Option(): array
    {
        return ['id' => $this->id, 'text' => $this->name];
    }
}
