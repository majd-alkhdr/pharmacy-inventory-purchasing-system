<?php

namespace App\Http\Controllers\Inventory;

use App\Exports\FinanceSettlementExport;
use App\Http\Controllers\Controller;
use App\Models\FinanceSettlementItem;
use App\Services\Inventory\Reconciliation\FinanceSettlementExportService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * 🎯 (ب.3) "مواد تحتاج تسوية من المالية" — ضمن قسم العمليات فقط (لا دور/حساب مالية بالنظام).
 * موظف العمليات يصدّر الملف يدوياً ويرسله للمالية.
 */
class FinanceSettlementController extends Controller
{
    public function __construct(
        private readonly FinanceSettlementExportService $exportService,
    ) {
    }

    public function index(Request $request)
    {
        $showExported = $request->boolean('show_exported');

        $items = FinanceSettlementItem::query()
            ->with(['invoice:id,date', 'pharmacy:id,name', 'product:id,name,barcode,company', 'reason:id,label'])
            ->when(!$showExported, fn ($q) => $q->pendingExport())
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('inventory.finance-settlements', [
            'items' => $items,
            'showExported' => $showExported,
            'pendingCount' => FinanceSettlementItem::pendingExport()->count(),
        ]);
    }

    public function export(Request $request)
    {
        $items = $this->exportService->takeForExport($request->boolean('all'));

        if ($items->isEmpty()) {
            return back()->with('info', 'لا توجد مواد جديدة بانتظار الإرسال للمالية.');
        }

        return Excel::download(new FinanceSettlementExport($items), 'مواد-تحتاج-تسوية-مالية-' . now()->format('Y-m-d') . '.xlsx');
    }
}
