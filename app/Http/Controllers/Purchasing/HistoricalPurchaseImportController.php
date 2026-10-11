<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\ImportHistoricalPricesRequest;
use App\Imports\HistoricalSupplierPricesImport;
use App\Enums\ImportFileType;
use App\Models\ImportLog;
use App\Services\Purchasing\HistoricalPurchaseImportService;
use App\Services\Shared\ImportHistory\ImportHistoryService;
use App\Services\Shared\ImportHistory\ImportStats;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class HistoricalPurchaseImportController extends Controller
{
    public function __construct(
        private readonly HistoricalPurchaseImportService $importService,
        private readonly ImportHistoryService $importHistory, // 🆕 (أ.1)
    ) {
    }

    public function showForm()
    {
        return view('purchasing.historical-import');
    }

    public function upload(ImportHistoricalPricesRequest $request)
    {
        $filePath = $request->file('prices_file')->store('imports');

        return $this->importHistory->track(ImportFileType::HistoricalPrices, $request->file('prices_file'), function (ImportLog $log) use ($filePath) {
            $import = new HistoricalSupplierPricesImport();
            Excel::import($import, Storage::path($filePath));
            Storage::delete($filePath);

            if ($import->rows->isEmpty()) {
                $this->importHistory->fail($log, 'الملف فارغ أو لا يحتوي على بيانات صالحة.');

                return back()->with('error', 'الملف فارغ أو لا يحتوي على بيانات صالحة.');
            }

            $result = $this->importService->process($import->rows);
            $this->importHistory->complete($log, ImportStats::fromCounts($result['matched'], $result['unmatched']->count()));

            return view('purchasing.historical-import-result', ['result' => $result]);
        });
    }
}
