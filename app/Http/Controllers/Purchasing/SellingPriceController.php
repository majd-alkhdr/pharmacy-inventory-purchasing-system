<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\ImportSellingPricesRequest;
use App\Imports\SellingPricesImport;
use App\Services\Purchasing\SellingPriceImportService;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class SellingPriceController extends Controller
{
    public function __construct(
        private readonly SellingPriceImportService $importService,
    ) {
    }

    public function showForm()
    {
        return view('purchasing.selling-prices-import');
    }

    public function upload(ImportSellingPricesRequest $request)
    {
        $filePath = $request->file('prices_file')->store('imports');

        $import = new SellingPricesImport();
        Excel::import($import, Storage::path($filePath));
        Storage::delete($filePath);

        if ($import->rows->isEmpty()) {
            return back()->with('error', 'الملف فارغ أو لا يحتوي على بيانات صالحة.');
        }

        $result = $this->importService->process($import->rows);

        return view('purchasing.selling-prices-import-result', ['result' => $result]);
    }
}
