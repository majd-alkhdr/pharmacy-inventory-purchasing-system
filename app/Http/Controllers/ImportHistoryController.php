<?php

namespace App\Http\Controllers;

use App\Enums\ImportFileType;
use App\Enums\ImportLogStatus;
use App\Http\Requests\ImportHistoryFilterRequest;
use App\Models\ImportLog;
use App\Services\Inventory\Import\Support\ImportDiagnostics;

/**
 * 🎯 (أ.1) شاشة "من رفع؟ متى؟ ماذا حدث؟" لكل أنواع الملفات بالقسمين — قراءة فقط.
 * آخر رفعة ناجحة لكل نوع تظهر بأعلى الشاشة كبطاقات (الجواب الأسرع للسؤال الأكثر تكراراً).
 */
class ImportHistoryController extends Controller
{
    public function index(ImportHistoryFilterRequest $request)
    {
        $filters = $request->filters();

        $logs = ImportLog::query()
            ->with('user:id,name')
            ->ofType($filters['file_type'])
            ->when($filters['status'], fn ($q, ImportLogStatus $s) => $q->where('status', $s))
            ->when($filters['date_from'], fn ($q, $d) => $q->whereDate('started_at', '>=', $d))
            ->when($filters['date_to'], fn ($q, $d) => $q->whereDate('started_at', '<=', $d))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        // آخر رفعة ناجحة لكل نوع — استعلام واحد مجمَّع لا استعلام لكل نوع.
        $latestIds = ImportLog::query()->successful()
            ->selectRaw('MAX(id) as id')
            ->groupBy('file_type')
            ->pluck('id');
        $latestByType = ImportLog::query()->whereIn('id', $latestIds)->get()->keyBy(fn (ImportLog $l) => $l->file_type->value);

        return view('import-history.index', [
            'logs' => $logs,
            'latestByType' => $latestByType,
            'fileTypes' => ImportFileType::cases(),
            'statuses' => ImportLogStatus::cases(),
        ]);
    }

    public function show(ImportHistoryFilterRequest $request, ImportLog $importLog)
    {
        return view('import-history.show', [
            'log' => $importLog->load('user:id,name'),
            'diagnostics' => $importLog->diagnostics ? ImportDiagnostics::fromArray($importLog->diagnostics) : null,
        ]);
    }
}
