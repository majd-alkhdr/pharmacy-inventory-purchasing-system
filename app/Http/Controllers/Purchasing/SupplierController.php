<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\SupplierRequest;
use App\Models\Supplier;

class SupplierController extends Controller
{
    public function index()
    {
        $suppliers = Supplier::withCount('products')
            ->when(request('search'), fn ($q) => $q->search(request('search')))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('purchasing.suppliers-index', ['suppliers' => $suppliers]);
    }

    public function create()
    {
        return view('purchasing.supplier-form', ['supplier' => new Supplier()]);
    }

    public function store(SupplierRequest $request)
    {
        $supplier = Supplier::create($request->validated());

        return redirect()->route('purchasing.suppliers.show', $supplier->id)
            ->with('success', "✅ تم إضافة المورد \"{$supplier->name}\" بنجاح. الآن أضف المواد وأسعارها.");
    }

    public function show(Supplier $supplier)
    {
        $supplier->load(['products.product', 'products.offers']);

        return view('purchasing.supplier-details', ['supplier' => $supplier]);
    }

    public function edit(Supplier $supplier)
    {
        return view('purchasing.supplier-form', ['supplier' => $supplier]);
    }

    public function update(SupplierRequest $request, Supplier $supplier)
    {
        $supplier->update($request->validated());

        return redirect()->route('purchasing.suppliers.show', $supplier->id)
            ->with('success', '✅ تم تحديث بيانات المورد بنجاح.');
    }
}
