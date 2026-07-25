<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\ImportHistoricalPricesRequest;
use App\Imports\HistoricalSupplierPricesImport;
use App\Services\Purchasing\HistoricalPurchaseImportService;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class HistoricalPurchaseImportController extends Controller
{
    public function __construct(
        private readonly HistoricalPurchaseImportService $importService,
    ) {
    }

    public function showForm()
    {
        return view('purchasing.historical-import');
    }

    public function upload(ImportHistoricalPricesRequest $request)
    {
        $filePath = $request->file('prices_file')->store('imports');

        $import = new HistoricalSupplierPricesImport();
        Excel::import($import, Storage::path($filePath));
        Storage::delete($filePath);

        if ($import->rows->isEmpty()) {
            return back()->with('error', 'الملف فارغ أو لا يحتوي على بيانات صالحة.');
        }

        $result = $this->importService->process($import->rows);

        return view('purchasing.historical-import-result', ['result' => $result]);
    }
}
