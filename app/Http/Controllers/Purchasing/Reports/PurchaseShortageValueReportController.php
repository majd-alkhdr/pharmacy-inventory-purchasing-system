<?php
namespace App\Http\Controllers\Purchasing\Reports;

use App\Http\Controllers\Controller;
use App\Services\Purchasing\Reports\PurchaseShortageValueReportService;
use App\Http\Requests\ReportFilterRequest;

class PurchaseShortageValueReportController extends Controller
{
    public function index(ReportFilterRequest $request, PurchaseShortageValueReportService $service)
    {
        $dateFrom = $request->dateFrom();
        $dateTo = $request->dateTo();
        $report = $service->build($dateFrom, $dateTo);

        return view('purchasing.reports.purchase-shortage-value', [
            'rows' => $report->rows, 'totals' => $report->totals,
            'dateFrom' => $dateFrom, 'dateTo' => $dateTo,
        ]);
    }
}
