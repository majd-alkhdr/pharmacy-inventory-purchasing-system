<?php

namespace App\Http\Controllers\Purchasing\Reports;

use App\Http\Controllers\Controller;
use App\Services\Purchasing\Reports\PurchaseValueReportService;
use App\Http\Requests\ReportFilterRequest;

class PurchaseValueReportController extends Controller
{
    public function index(ReportFilterRequest $request, PurchaseValueReportService $service)
    {
        $dateFrom = $request->dateFrom();
        $dateTo = $request->dateTo();
        $report = $service->build($dateFrom, $dateTo);

        return view('purchasing.reports.purchase-value', [
            'rows' => $report->rows,
            'totals' => $report->totals,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ]);
    }
}
