<?php

namespace App\Http\Controllers\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\Purchasing\SupplierScope\CompanyDerivedSupplierScopeStrategy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 🎯 (ج.1) الخطوة الثانية باستراتيجية "اختيار شركة": من يحمل هذه الشركة؟ (AJAX لشاشة التوليد).
 * المصدر نفسه الذي تتحقق به الاستراتيجية عند الإرسال (suggestFor) — الاقتراح والتحقق لا يختلفان.
 */
class SupplierScopeController extends Controller
{
    public function __construct(
        private readonly CompanyDerivedSupplierScopeStrategy $byCompany,
    ) {
    }

    public function suppliersForCompany(Request $request): JsonResponse
    {
        $company = trim((string) $request->query('company', ''));
        if ($company === '') {
            return response()->json(['suppliers' => []]);
        }

        $locked = PurchaseOrder::where('status', PurchaseOrderStatus::Draft->value)->pluck('supplier_id')->map(fn ($id) => (int) $id)->all();

        return response()->json([
            'suppliers' => $this->byCompany->suggestFor($company)->map(fn (Supplier $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'locked' => in_array((int) $s->id, $locked, true),
            ])->values(),
        ]);
    }
}
