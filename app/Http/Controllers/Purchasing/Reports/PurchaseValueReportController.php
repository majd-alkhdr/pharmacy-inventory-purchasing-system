<?php

namespace App\Http\Controllers\Purchasing\Reports;

use App\Http\Controllers\Controller;
use App\Services\Purchasing\Reports\PurchaseValueReportService;
use Illuminate\Http\Request;

class PurchaseValueReportController extends Controller
{
    public function index(Request $request, PurchaseValueReportService $service)
    {
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $report = $service->build($dateFrom, $dateTo);

        return view('purchasing.reports.purchase-value', [
            'rows' => $report->rows,
            'totals' => $report->totals,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ]);
    }
}
