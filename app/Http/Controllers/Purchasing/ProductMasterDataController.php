<?php

declare(strict_types=1);

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\ShowProductMasterDataRequest;
use App\Http\Requests\Purchasing\UpdateProductMasterDataRequest;
use App\Models\Product;
use App\Services\Inventory\ProductMasterData\ProductMasterDataEditingService;
use App\Services\Inventory\ProductMasterData\ProductMasterDataQuery;

/**
 * 🎯 شاشة التحقق من بيانات المادة (المشتريات): عرض كل حقل + آخر كاتب له، وتصحيح يدوي سريع يكتب
 * فوقه أول رفع لاحق لملف الحقل.
 */
class ProductMasterDataController extends Controller
{
    public function __construct(
        private readonly ProductMasterDataQuery $query,
        private readonly ProductMasterDataEditingService $editor,
    ) {
    }

    public function show(ShowProductMasterDataRequest $request)
    {
        $product = $request->productId() ? Product::findOrFail($request->productId()) : null;

        return view('purchasing.product-master-data', [
            'product' => $product,
            'fields' => $product ? $this->query->snapshot($product) : [],
            'corrections' => $product ? $this->query->corrections($product) : collect(),
        ]);
    }

    public function update(UpdateProductMasterDataRequest $request, Product $product)
    {
        $changed = $this->editor->apply($product, $request->corrections());

        return redirect()
            ->route('purchasing.product-master-data.show', ['product_id' => $product->id])
            ->with($changed === [] ? 'info' : 'success', $changed === []
                ? 'لم يتغيّر أي حقل — القيم المُدخلة مطابقة للمخزَّنة.'
                : 'صُحِّح: '.implode('، ', array_map(fn ($f) => $f->label(), $changed)).'. سيُكتب فوقها أول رفع لاحق لملف الحقل يحمل قيمة له.');
    }
}
