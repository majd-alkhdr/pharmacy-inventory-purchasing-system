<?php

namespace App\Http\Controllers\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseScenario;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\ApprovePurchaseOrderRequest;
use App\Http\Requests\Purchasing\GeneratePurchaseOrdersRequest;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\Purchasing\PurchaseOrderGenerationService;

/**
 * 🎓 لاحظ حجم هذا الكنترولر مقارنةً بـ InventoryController الأصلي قبل
 * إعادة الهيكلة (المرحلة الأولى، تقرير [001]): كل دالة هنا ≤ 10 أسطر
 * تقريباً، لأن كل المنطق الثقيل (وهو ضخم جداً هنا فعلياً) يعيش بالكامل
 * بطبقة الخدمات. هذا الكنترولر لا "يعرف" كيف يُحسَب الاحتياج أو كيف
 * تُختار السيناريوهات — فقط يستقبل الطلب، يُفوّض، ويُرجع النتيجة.
 */
class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderGenerationService $generationService,
    ) {
    }

    public function create()
    {
        return view('purchasing.generate', [
            'scenarios' => PurchaseScenario::cases(),
            'suppliers' => Supplier::active()->orderBy('name')->get(),
        ]);
    }

    public function store(GeneratePurchaseOrdersRequest $request)
    {
        $result = $this->generationService->generate($request->toParams());

        if ($result->createdOrders->isEmpty()) {
            return back()->with('info', $this->buildEmptyResultMessage($result));
        }

        return redirect()
            ->route('purchasing.orders.index')
            ->with('success', $this->buildSuccessMessage($result));
    }

    public function index()
    {
        $orders = PurchaseOrder::with('supplier')
            ->filter(request()->only(['status', 'supplier_id']))
            ->orderByDesc('generated_at')
            ->paginate(20)
            ->withQueryString();

        return view('purchasing.orders-index', [
            'orders'    => $orders,
            'suppliers' => Supplier::orderBy('name')->get(),
            'statuses'  => PurchaseOrderStatus::cases(),
        ]);
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load(['supplier', 'items.product', 'items.supplierProduct.supplier']);

        return view('purchasing.order-details', ['order' => $purchaseOrder]);
    }

    public function approve(ApprovePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder)
    {
        if (!$purchaseOrder->isEditable()) {
            return back()->with('error', 'لا يمكن اعتماد فاتورة ليست بحالة "مسودة".');
        }

        $purchaseOrder->update([
            'status'      => PurchaseOrderStatus::Approved,
            'approved_at' => now(),
            'approved_by' => $request->validated('approved_by'),
            'notes'       => $request->validated('notes'),
        ]);

        return back()->with('success', "✅ تم اعتماد فاتورة الشراء #{$purchaseOrder->id} بنجاح.");
    }

    private function buildSuccessMessage($result): string
    {
        $ordersCount = $result->createdOrders->count();
        $totalCost = number_format($result->totalEstimatedCost(), 2);
        $msg = "🎉 تم توليد {$ordersCount} فاتورة شراء مقترحة بإجمالي تقديري {$totalCost}.";

        if ($result->deferredCandidates->isNotEmpty()) {
            $deferredCount = $result->deferredCandidates->count();
            $msg .= " ⚠️ تم ترحيل {$deferredCount} مادة لطلبية قادمة بسبب تجاوزها سقف الميزانية المحدَّد.";
        }

        return $msg;
    }

    private function buildEmptyResultMessage($result): string
    {
        return "لا توجد حالياً أي مواد تحتاج شراءً فعلياً (تم فحص {$result->eligibleProductsScanned} مادة مؤهَّلة، "
            . "{$result->productsWithNoRequirement} منها لا يوجد احتياج فعلي حالياً، "
            . "و{$result->productsWithNoSupplier} منها بلا مورد مرتبط بالنظام بعد).";
    }
}
