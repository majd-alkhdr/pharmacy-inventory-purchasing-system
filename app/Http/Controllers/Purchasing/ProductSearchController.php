<?php
// app/Http/Controllers/Purchasing/ProductSearchController.php
namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductSearchController extends Controller
{
    public function search(Request $request)
    {
        $products = Product::select('id', 'name', 'company')
            ->search($request->input('q', ''))
            ->limit(30)
            ->get()
            ->map(fn(Product $p) => $p->toSelect2Option());

        return response()->json(['results' => $products]);
    }
}
