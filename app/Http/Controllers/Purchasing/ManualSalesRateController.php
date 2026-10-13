<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\SaveManualSalesRateMappingRequest;
use App\Http\Requests\Purchasing\UploadManualSalesRatesRequest;
use App\Imports\ManualSalesRatesImport;
use App\Models\PendingManualSalesRate;
use App\Services\Purchasing\ManualSalesRateMappingService;
use App\Enums\ImportFileType;
use App\Models\ImportLog;
use App\Services\Purchasing\ManualSalesRateUploadService;
use App\Services\Shared\ImportHistory\ImportHistoryService;
use App\Services\Shared\ImportHistory\ImportStats;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * 🆕 يستبدل MinimumStockController كمصدر "الهدف" لتوليد فواتير الشراء
 * (نمط "تحقيق المخزون القياسي للمستودع") — راجع
 * StandardStockRequiredQuantityProvider (الطبقة القادمة) للاستهلاك
 * الفعلي. MinimumStockController نفسه لم يُحذَف (تجميد لا حذف)، فقط لم
 * يعد مرتبطاً بأي رابط بالواجهة بعد الآن.
 */
class ManualSalesRateController extends Controller
{
    public function __construct(
        private readonly ManualSalesRateUploadService $uploadService,
        private readonly ImportHistoryService $importHistory, // 🆕 (أ.1)
        private readonly ManualSalesRateMappingService $mappingService,
    ) {}

    public function showUploadForm()
    {
        return view('purchasing.manual-sales-rate-upload');
    }

    public function upload(UploadManualSalesRatesRequest $request)
    {
        $filePath = $request->file('sales_file')->store('imports');

        // 🆕 (أ.1) "بانتظار مطابقة يدوية" تُسجَّل نجاحاً بتحذيرات: الملف قُرئ وطُبِّق ما طابق،
        // والمعلَّق ظاهر كعدّاد متجاهَل حتى تُكمل شاشة المطابقة.
        $history = $this->importHistory;
        $outcome = $history->track(ImportFileType::ManualSalesRates, $request->file('sales_file'), function (ImportLog $log) use ($filePath, $request, $history) {
            $import = new ManualSalesRatesImport();
            Excel::import($import, Storage::path($filePath));
            Storage::delete($filePath);

            if ($import->rows->isEmpty()) {
                $history->fail($log, 'الملف فارغ أو لا يحتوي على بيانات صالحة.');

                return null;
            }

            $result = $this->uploadService->process($import->rows, $request->periodDays());
            $history->complete($log, ImportStats::fromCounts($result['matched'], $result['pending']));

            return $result;
        });

        if ($outcome === null) {
            return back()->with('error', 'الملف فارغ أو لا يحتوي على بيانات صالحة.');
        }

        $result = $outcome;

        if ($result['session_id']) {
            return redirect()->route('purchasing.manual-sales-rate.mapping', ['sessionId' => $result['session_id']])
                ->with('info', "✅ تمت مطابقة {$result['matched']} مادة تلقائياً. تبقّى {$result['pending']} مادة تحتاج مراجعتك يدوياً أدناه.");
        }

        return back()->with('success', "✅ تم تحديث معدّل مبيعات {$result['matched']} مادة بنجاح.");
    }

    public function showMapping(string $sessionId)
    {
        $pending = PendingManualSalesRate::forSession($sessionId)->get();

        if ($pending->isEmpty()) {
            return redirect()->route('purchasing.manual-sales-rate.form')->with('info', 'لا توجد صفوف معلّقة بهذه الجلسة.');
        }

        return view('purchasing.manual-sales-rate-mapping', [
            'sessionId' => $sessionId,
            'pending'    => $pending,
        ]);
    }

    public function saveMapping(SaveManualSalesRateMappingRequest $request)
    {
        $result = $this->mappingService->resolve($request->sessionId(), $request->decisions(), $request->barcodes());

        return redirect()->route('purchasing.manual-sales-rate.form')
            ->with('success', "✅ تمت معالجة {$result['resolved']} صف ({$result['created']} منتج جديد أُنشئ).");
    }
}
