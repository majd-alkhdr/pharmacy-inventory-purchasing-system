<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\SupplierCompanyRequest;
use App\Models\Supplier;
use App\Models\SupplierCompany;
use App\Http\Requests\Purchasing\ImportSupplierCompanyLinksRequest;
use App\Imports\SupplierCompanyLinksImport;
use App\Services\Purchasing\SupplierCompanyBulkImportService;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

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

        $supplierCompany->delete($supplier->id);

        return back()->with('success', 'تم حذف الشركة من قائمة الشركات المفضّلة.');
    }

    public function showBulkImportForm()
    {
        return view('purchasing.supplier-companies-bulk-import');
    }

    public function bulkImport(
        ImportSupplierCompanyLinksRequest $request,
        SupplierCompanyBulkImportService $service,
        \App\Services\Shared\ImportHistory\ImportHistoryService $history, // 🆕 (أ.1) — نفس أسلوب حقن $service بالدالة
    ) {
        $filePath = $request->file('links_file')->store('imports');

        $result = $history->track(\App\Enums\ImportFileType::SupplierCompanyLinks, $request->file('links_file'), function (\App\Models\ImportLog $log) use ($filePath, $service, $history) {
            $import = new SupplierCompanyLinksImport();
            Excel::import($import, Storage::path($filePath));
            Storage::delete($filePath);

            if ($import->rows->isEmpty()) {
                $history->fail($log, 'الملف فارغ أو لا يحتوي على بيانات صالحة.');

                return null;
            }

            $result = $service->process($import->rows);
            $history->complete($log, \App\Services\Shared\ImportHistory\ImportStats::fromCounts($result['suppliers_updated']));

            return $result;
        });

        if ($result === null) {
            return back()->with('error', 'الملف فارغ أو لا يحتوي على بيانات صالحة.');
        }

        return back()->with('success', "✅ تم تحديث {$result['suppliers_updated']} مورد ({$result['suppliers_created']} مورد جديد أُنشئ)، بإجمالي {$result['companies_linked']} رابط شركة.");
    }
}
