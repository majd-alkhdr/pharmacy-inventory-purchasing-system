<?php

namespace App\Http\Controllers\Purchasing\Reports;

use App\Http\Controllers\Controller;
use App\Services\Purchasing\Reports\SupplierPurchaseSummaryReportService;
use Illuminate\Http\Request;

class SupplierPurchaseSummaryReportController extends Controller
{
    public function index(Request $request, SupplierPurchaseSummaryReportService $service)
    {
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $report = $service->build($dateFrom, $dateTo);

        return view('purchasing.reports.supplier-summary', [
            'rows' => $report->rows,
            'totals' => $report->totals,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ]);
    }
}
