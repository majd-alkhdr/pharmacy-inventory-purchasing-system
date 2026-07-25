<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $fillable = ['name', 'contact_person', 'phone', 'email', 'lead_time_days', 'notes', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function products()
    {
        return $this->hasMany(SupplierProduct::class);
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** 🎯 نفس نمط Pharmacy::scopeSearch() / Product::scopeSearch() المعتمَد بالمشروع */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where('name', 'LIKE', "%{$term}%");
    }
}
