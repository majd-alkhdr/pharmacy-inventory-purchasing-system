<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\SupplierCompanyRequest;
use App\Models\Supplier;
use App\Models\SupplierCompany;

/**
 * 🎯 يدير قائمة "الشركات المفضّلة" لكل مورد — أساس اختيار المورد الجديد
 * بمحرك توليد فواتير الشراء (SupplierSelectionService). راجع وثيقة
 * التصميم §3.1 و§4.6 للسياق الكامل.
 */
class SupplierCompanyController extends Controller
{
    public function store(SupplierCompanyRequest $request, Supplier $supplier)
    {
        $supplier->preferredCompanies()->create($request->validated());

        return back()->with('success', '✅ تم إضافة الشركة لقائمة الشركات المفضّلة لهذا المورد.');
    }

    public function destroy(Supplier $supplier, SupplierCompany $supplierCompany)
    {
        if ($supplierCompany->supplier_id !== $supplier->id) {
            abort(404);
        }

        $supplierCompany->delete();

        return back()->with('success', 'تم حذف الشركة من قائمة الشركات المفضّلة.');
    }
}
