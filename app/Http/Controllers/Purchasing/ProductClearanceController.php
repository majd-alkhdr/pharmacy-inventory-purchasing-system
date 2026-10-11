<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\MarkProductClearanceRequest;
use App\Models\Product;
use App\Models\ProductPurchaseSetting;

/**
 * 🎯 شاشة بسيطة (طلب صريح من صاحب المشروع: "شاشة تفعيل بسيطة") لتفعيل
 * أو إلغاء علم "قيد التصفية" لأي منتج — يدوياً بالكامل، بلا أي حساب
 * آلي. راجع Product::isInClearance() وPurchaseEligibilityFilter
 * للأثر الفعلي على محرك القرار.
 */
class ProductClearanceController extends Controller
{
    public function index()
    {
        $activeClearances = ProductPurchaseSetting::activelyInClearance()
            ->with('product')
            ->orderByDesc('updated_at')
            ->get();

        return view('purchasing.clearance-management', [
            'activeClearances' => $activeClearances,
        ]);
    }

    public function store(MarkProductClearanceRequest $request)
    {
        $data = $request->validated();

        ProductPurchaseSetting::updateOrCreate(
            ['product_id' => $data['product_id']],
            [
                'is_clearance'     => true,
                'clearance_until' => $data['clearance_until'] ?? null,
            ]
        );

        $product = Product::find($data['product_id']);

        return back()->with('success', "✅ تم وضع \"{$product->name}\" قيد التصفية — لن يُقتَرح بأي فاتورة شراء قادمة حتى يُلغى هذا العلم.");
    }

    public function destroy(ProductPurchaseSetting $productPurchaseSetting)
    {
        $productPurchaseSetting->update(['is_clearance' => false, 'clearance_until' => null]);

        return back()->with('success', '✅ تم إلغاء علم التصفية — سيعود هذا المنتج للترشيح الطبيعي بمحرك الشراء.');
    }
}
