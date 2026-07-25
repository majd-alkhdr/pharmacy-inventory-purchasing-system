<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\SupplierProductRequest;
use App\Models\Supplier;
use App\Models\SupplierProduct;

class SupplierProductController extends Controller
{
    public function store(SupplierProductRequest $request, Supplier $supplier)
    {
        $data = $request->validated();

        // 🎯 مورد واحد فقط "مفضّل" لكل مادة — لو حُدِّد هذا كمفضّل، نُلغي
        // التفضيل عن أي مورد آخر لنفس المادة أولاً (منع تعارض منطقي).
        if ($data['is_preferred'] ?? false) {
            SupplierProduct::where('product_id', $data['product_id'])->update(['is_preferred' => false]);
        }

        $supplier->products()->create([
            'product_id'             => $data['product_id'],
            'unit_cost'               => $data['unit_cost'],
            'is_preferred'            => $data['is_preferred'] ?? false,
            'last_price_updated_at'  => now(),
        ]);

        return back()->with('success', '✅ تم ربط المادة بهذا المورد.');
    }

    public function destroy(Supplier $supplier, SupplierProduct $supplierProduct)
    {
        if ($supplierProduct->supplier_id !== $supplier->id) {
            abort(404);
        }

        $supplierProduct->delete();

        return back()->with('success', 'تم إلغاء ربط المادة بهذا المورد.');
    }
}
