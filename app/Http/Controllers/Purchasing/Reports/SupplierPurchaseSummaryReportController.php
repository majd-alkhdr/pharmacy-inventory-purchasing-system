<?php

namespace App\Http\Controllers\Purchasing\Reports;

use App\Http\Controllers\Controller;
use App\Services\Purchasing\Reports\SupplierPurchaseSummaryReportService;
use App\Http\Requests\ReportFilterRequest;

class SupplierPurchaseSummaryReportController extends Controller
{
    public function index(ReportFilterRequest $request, SupplierPurchaseSummaryReportService $service)
    {
        $dateFrom = $request->dateFrom();
        $dateTo = $request->dateTo();
        $report = $service->build($dateFrom, $dateTo);

        return view('purchasing.reports.supplier-summary', [
            'rows' => $report->rows,
            'totals' => $report->totals,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ]);
    }
}
