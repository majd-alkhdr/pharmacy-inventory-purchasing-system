<?php

namespace App\Http\Controllers;

use App\Models\Pharmacy;
use App\Services\Inventory\Reports\InventoryTurnoverReportService;
use App\Services\Inventory\Reports\InventoryValueReportService;
use App\Services\Inventory\Reports\ProductMovementReportService;
use App\Services\Inventory\Reports\ShortageValueReportService;
use App\Services\Inventory\Reports\TransfersValueReportService;
use Illuminate\Http\Request;

/**
 * 🎯 كونترولر منفصل عن InventoryController عمداً — راجع تقرير-تعليمي-
 * معمارية-نظام-المناقلات.md: "سبب واحد للتغيير" ينطبق على الكونترولرات
 * أيضاً، لا الخدمات فقط. InventoryController مسؤول عن تنسيق الرفع
 * والترميم؛ هذا الكونترولر مسؤول فقط عن استدعاء خدمة تقرير وعرض نتيجتها.
 * لا شيء بأي منهما يستدعي الآخر أو يعرف بوجوده.
 */
class TransfersReportController extends Controller
{
    public function __construct(
        private readonly InventoryValueReportService $inventoryValueReport,
        private readonly TransfersValueReportService $transfersValueReport,
        private readonly ShortageValueReportService $shortageValueReport,
        private readonly InventoryTurnoverReportService $turnoverReport,
        private readonly ProductMovementReportService $movementReport,
    ) {}

    public function inventoryValue()
    {
        $report = $this->inventoryValueReport->build();

        return view('inventory.reports.inventory-value', [
            'rows'   => $report->rows,
            'totals' => $report->totals,
        ]);
    }

    public function transfersValue(Request $request)
    {
        // 🎯 افتراضي معقول لتقرير إداري: الشهر الحالي حتى اليوم — لا "كل
        // البيانات منذ بداية النظام" افتراضياً (استعلام أثقل بلا داعٍ، وأقل
        // فائدة إدارية من نظرة سريعة على الفترة الحالية).
        $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo   = $request->input('date_to', now()->toDateString());
        $pharmacyId = $request->input('pharmacy_id');

        $report = $this->transfersValueReport->build($dateFrom, $dateTo, $pharmacyId ? (int) $pharmacyId : null);

        return view('inventory.reports.transfers-value', [
            'rows'       => $report->rows,
            'totals'     => $report->totals,
            'dateFrom'   => $dateFrom,
            'dateTo'     => $dateTo,
            'pharmacies' => Pharmacy::orderBy('name')->get(),
        ]);
    }

    public function shortageValue(Request $request)
    {
        $dateFrom = $request->input('date_from');
        $dateTo   = $request->input('date_to');
        $pharmacyId = $request->input('pharmacy_id');

        $report = $this->shortageValueReport->build($dateFrom, $dateTo, $pharmacyId ? (int) $pharmacyId : null);

        return view('inventory.reports.shortage-value', [
            'rows'       => $report->rows,
            'totals'     => $report->totals,
            'dateFrom'   => $dateFrom,
            'dateTo'     => $dateTo,
            'pharmacies' => Pharmacy::orderBy('name')->get(),
        ]);
    }

    public function inventoryTurnover()
    {
        $report = $this->turnoverReport->build();

        return view('inventory.reports.inventory-turnover', [
            'central'    => $report->central,
            'pharmacies' => $report->pharmacies,
        ]);
    }

    public function productMovement(Request $request)
    {
        $productId = $request->input('product_id') ? (int) $request->input('product_id') : null;

        return view('inventory.reports.product-movement', [
            'watchedProducts' => $this->movementReport->watchedProductsList(),
            'rows'            => $this->movementReport->build($productId),
            'selectedProductId' => $productId,
        ]);
    }
}
