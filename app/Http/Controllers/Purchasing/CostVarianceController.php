<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Services\Purchasing\CostVarianceMonitorService;

class CostVarianceController extends Controller
{
    public function index(CostVarianceMonitorService $monitor)
    {
        return view('purchasing.cost-variance', [
            'variances' => $monitor->detectVariances(),
        ]);
    }
}
