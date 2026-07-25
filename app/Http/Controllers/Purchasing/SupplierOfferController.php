<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\SupplierOfferRequest;
use App\Models\SupplierOffer;
use App\Models\SupplierProduct;

class SupplierOfferController extends Controller
{
    public function store(SupplierOfferRequest $request, SupplierProduct $supplierProduct)
    {
        $supplierProduct->offers()->create($request->validated());

        return back()->with('success', '✅ تم إضافة العرض بنجاح.');
    }

    public function destroy(SupplierProduct $supplierProduct, SupplierOffer $offer)
    {
        if ($offer->supplier_product_id !== $supplierProduct->id) {
            abort(404);
        }

        $offer->delete();

        return back()->with('success', 'تم حذف العرض.');
    }
}
