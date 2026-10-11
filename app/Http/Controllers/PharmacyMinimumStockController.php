<?php

namespace App\Http\Controllers;

use App\Http\Requests\Inventory\UploadPharmacyMinimumStockRequest;
use App\Imports\PharmacyMinimumStockImport;
use App\Enums\ImportFileType;
use App\Models\ImportLog;
use App\Services\Inventory\PharmacyMinimumStockUploadService;
use App\Services\Shared\ImportHistory\ImportHistoryService;
use App\Services\Shared\ImportHistory\ImportStats;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * 🎯 شاشة مستقلة (بنفس فلسفة شاشة الحد الأدنى بقسم المشتريات، لكن هذه
 * تخص الحد الأدنى **لكل زوج صيدلية+مادة** بقسم المناقلات) — يُرفَع
 * بمعزل تام عن دورة "رفع المبيعات والجرد اليومي"، لأنه مرجع نادر
 * التحديث، بخلاف جرد الصيدليات نفسه الذي يُرفَع يومياً مع دفعة المبيعات.
 */
class PharmacyMinimumStockController extends Controller
{
    public function __construct(
        private readonly PharmacyMinimumStockUploadService $uploadService,
        private readonly ImportHistoryService $importHistory, // 🆕 (أ.1)
    ) {}

    public function showUploadForm()
    {
        return view('inventory.pharmacy-minimum-stock-upload', [
            // 🆕 (أ.1) "هل نجح آخر رفع للمخزون القياسي؟" — يُجاب عنه فوق نموذج الرفع مباشرة.
            'lastUpload' => ImportLog::latestSuccessful(ImportFileType::PharmacyMinimumStock),
        ]);
    }

    public function upload(UploadPharmacyMinimumStockRequest $request)
    {
        $filePath = $request->file('min_stock_file')->store('imports');

        return $this->importHistory->track(ImportFileType::PharmacyMinimumStock, $request->file('min_stock_file'), function (ImportLog $log) use ($filePath) {
            $import = new PharmacyMinimumStockImport();
            Excel::import($import, Storage::path($filePath));
            Storage::delete($filePath);

            if ($import->rows->isEmpty()) {
                $this->importHistory->fail($log, 'الملف فارغ أو لا يحتوي على بيانات صالحة.');

                return back()->with('error', 'الملف فارغ أو لا يحتوي على بيانات صالحة.');
            }

            $result = $this->uploadService->process($import->rows);

            $this->importHistory->complete($log, ImportStats::fromDiagnostics(
                succeeded: $result['matched'],
                diagnostics: $result['diagnostics'],
                extraSkipped: $result['unmatched']->count(),
            ));

            return view('inventory.pharmacy-minimum-stock-result', ['result' => $result]);
        });
    }
}
