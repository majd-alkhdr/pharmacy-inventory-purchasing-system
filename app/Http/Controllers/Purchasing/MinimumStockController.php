<?php

namespace App\Http\Controllers\Purchasing;

use App\Enums\ImportFileType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\UploadMinimumStockRequest;
use App\Imports\MinimumStockImport;
use App\Models\ImportLog;
use App\Services\Purchasing\MinimumStockUploadService;
use App\Services\Shared\ImportHistory\ImportHistoryService;
use App\Services\Shared\ImportHistory\ImportStats;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class MinimumStockController extends Controller
{
    public function __construct(
        private readonly MinimumStockUploadService $uploadService,
        private readonly ImportHistoryService $importHistory, // 🆕 (أ.1)
    ) {
    }

    public function showUploadForm()
    {
        return view('purchasing.minimum-stock-upload');
    }

    public function upload(UploadMinimumStockRequest $request)
    {
        $filePath = $request->file('min_stock_file')->store('imports');

        return $this->importHistory->track(ImportFileType::PurchasingMinimumStock, $request->file('min_stock_file'), function (ImportLog $log) use ($filePath) {
            $import = new MinimumStockImport();
            Excel::import($import, Storage::path($filePath));
            Storage::delete($filePath);

            if ($import->rows->isEmpty()) {
                $this->importHistory->fail($log, 'الملف فارغ أو لا يحتوي على بيانات صالحة.');

                return back()->with('error', 'الملف فارغ أو لا يحتوي على بيانات صالحة.');
            }

            $result = $this->uploadService->process($import->rows);
            $this->importHistory->complete($log, ImportStats::fromCounts($result['matched'], $result['unmatched']->count()));

            return view('purchasing.minimum-stock-result', ['result' => $result]);
        });
    }
}
