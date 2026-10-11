<?php
namespace App\Http\Controllers\Purchasing\Reports;

use App\Http\Controllers\Controller;
use App\Services\Purchasing\Reports\PurchaseShortageValueReportService;
use Illuminate\Http\Request;

class PurchaseShortageValueReportController extends Controller
{
    public function index(Request $request, PurchaseShortageValueReportService $service)
    {
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $report = $service->build($dateFrom, $dateTo);

        return view('purchasing.reports.purchase-shortage-value', [
            'rows' => $report->rows, 'totals' => $report->totals,
            'dateFrom' => $dateFrom, 'dateTo' => $dateTo,
        ]);
    }
}
