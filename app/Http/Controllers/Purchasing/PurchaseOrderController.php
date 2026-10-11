<?php

namespace App\Http\Controllers\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Exports\AllPurchaseOrdersExport;
use App\Exports\PurchaseOrderExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\ApprovePurchaseOrderRequest;
use App\Http\Requests\Purchasing\CancelPurchaseOrderRequest;
use App\Http\Requests\Purchasing\GeneratePurchaseOrdersRequest;
use App\Http\Requests\Purchasing\UpdatePurchaseOrderItemsRequest;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierCompany;
use App\Services\Purchasing\PurchaseOrderCancellationService;
use App\Services\Purchasing\PurchaseOrderEditingService;
use App\Services\Purchasing\PurchaseOrderGenerationService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderGenerationService $generationService,
        private readonly PurchaseOrderCancellationService $cancellationService,
        private readonly PurchaseOrderEditingService $editingService,
        private readonly \App\Services\Inventory\Surplus\InventoryCycleResolver $cycleResolver, // 🆕 (ج.3)
    ) {}

    public function create()
    {
        return view('purchasing.generate', [
            'suppliers'      => Supplier::active()->orderBy('name')->get(),
            'lockedSupplierIds' => PurchaseOrder::where('status', PurchaseOrderStatus::Draft->value)
                ->pluck('supplier_id')->unique(),
            // 🆕 (ج.1) الشركات المرتبطة بمورد واحد على الأقل — مدخل استراتيجية "اختيار شركة".
            'companies'      => SupplierCompany::query()->distinct()->orderBy('company_name')->pluck('company_name'),
        ]);
    }

    public function store(GeneratePurchaseOrdersRequest $request)
    {
        $result = $this->generationService->generate($request->toParams());

        if ($result->hasSupplierConflicts()) {
            session([
                'purchase_generation_params' => $request->validated(),
                'purchase_conflicting_companies' => $result->conflictingCompanies->values()->all(),
            ]);

            return redirect()->route('purchasing.orders.resolve-conflicts');
        }

        if ($result->hasLockedSuppliers() && $result->createdOrders->isEmpty()) {
            $names = $result->lockedSupplierNames->implode('، ');
            return back()->with('error', "⛔ لم يتم توليد أي فاتورة — الموردون التاليون لديهم مسودة معلّقة بانتظار قرارك (اعتماد أو رفض): {$names}.");
        }

        if ($result->createdOrders->isEmpty()) {
            return back()->with('info', $this->buildEmptyResultMessage($result));
        }

        session()->forget(['purchase_supplier_overrides', 'purchase_generation_params', 'purchase_conflicting_companies']);

        return redirect()
            ->route('purchasing.orders.index')
            ->with('success', $this->buildSuccessMessage($result));
    }

    public function showResolveConflicts()
    {
        $companies = collect(session('purchase_conflicting_companies', []));

        if ($companies->isEmpty()) {
            return redirect()->route('purchasing.orders.create');
        }

        $suppliersByCompany = $companies->mapWithKeys(fn(string $company) => [
            $company => SupplierCompany::suppliersForCompany($company),
        ]);

        return view('purchasing.resolve-supplier-conflicts', [
            'suppliersByCompany' => $suppliersByCompany,
        ]);
    }

    public function resolveConflicts(Request $request)
    {
        $choices = $request->input('supplier_choice', []);

        session(['purchase_supplier_overrides' => array_merge(
            session('purchase_supplier_overrides', []),
            array_filter($choices)
        )]);

        $params = session('purchase_generation_params', []);

        return redirect()->route('purchasing.orders.create')
            ->with('info', 'تم حفظ اختيارك — يرجى الضغط على "فحص الاحتياج وتوليد الفواتير المقترحة" مرة أخرى لإكمال التوليد.')
            ->withInput($params);
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

    public function show(PurchaseOrder $purchaseOrder, \App\Services\Shared\Stock\CentralStockReader $centralStock)
    {
        $purchaseOrder->load(['supplier', 'items.product', 'items.supplierProduct.supplier']);
        $productIds = $purchaseOrder->items->pluck('product_id')->unique()->values()->all();

        return view('purchasing.order-details', [
            'order' => $purchaseOrder,
            // 🆕 (ج.2) "الكمية المتوفرة في الصناعة" = رصيد المستودع المركزي (ملف المصفوفة) — نفس قارئ الكمية المقترحة.
            'industryStock' => $centralStock->quantitiesFor($productIds),
            // 🆕 (ج.2) سجل تعديلات الكمية المعتمدة لهذه الفاتورة.
            'approvalHistory' => \App\Models\AuditLog::query()
                ->whereIn('action', [\App\Enums\AuditAction::PurchaseItemApprovedQtyChanged, \App\Enums\AuditAction::PurchaseItemRemoved])
                ->whereContext('purchase_order_id', $purchaseOrder->id)
                ->with('user:id,name')->latest('id')->get(),
            // 🆕 (ج.3) شارة "آخر تحديث للبيانات" بلوحة "مناقلات؟".
            'surplusCycle' => $this->cycleResolver->current(),
        ]);
    }

    /**
     * 🆕 تعديل بنود فاتورة بحالة مسودة قبل اتخاذ قرار الاعتماد/الرفض —
     * يحفظ الكميات المعدَّلة (approved_qty) ويحذف أي أسطر أُزيلت، بلا
     * تغيير حالة الفاتورة نفسها (تبقى "مسودة" بعد الحفظ، جاهزة للاعتماد
     * لاحقاً بضغطة منفصلة).
     */
    public function updateItems(UpdatePurchaseOrderItemsRequest $request, PurchaseOrder $purchaseOrder)
    {
        try {
            $this->editingService->updateItems(
                $purchaseOrder,
                $request->validated('items'),
                $request->validated('remove_items', [])
            );
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', '✅ تم حفظ التعديلات على الفاتورة — راجعها ثم اعتمدها متى كنت جاهزاً.');
    }

    public function exportExcel(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load(['supplier', 'items.product']);

        $fileName = 'فاتورة-شراء-' . $purchaseOrder->id . '-' . $purchaseOrder->supplier->name . '.xlsx';

        return Excel::download(new PurchaseOrderExport($purchaseOrder), $fileName);
    }

    public function exportAllExcel(Request $request)
    {
        $filters = $request->only(['status', 'supplier_id']);

        $suffix = '';
        if (!empty($filters['status'])) {
            $suffix .= '-' . PurchaseOrderStatus::from($filters['status'])->label();
        }

        return Excel::download(
            new AllPurchaseOrdersExport($filters),
            'فواتير-الشراء-المقترحة' . $suffix . '-' . now()->format('Y-m-d') . '.xlsx'
        );
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

    public function reject(CancelPurchaseOrderRequest $request, PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status !== PurchaseOrderStatus::Draft) {
            return back()->with('error', 'الرفض متاح فقط للمسودات التي لم تُعتمَد بعد.');
        }

        try {
            $this->cancellationService->cancelFully($purchaseOrder, $request->validated('reason'));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('purchasing.orders.create')
            ->with('info', "🚫 تم رفض المسودة #{$purchaseOrder->id}. يمكنك الآن توليد مسودة جديدة لهذا المورد.");
    }

    public function cancel(CancelPurchaseOrderRequest $request, PurchaseOrder $purchaseOrder)
    {
        try {
            $this->cancellationService->cancelFully($purchaseOrder, $request->validated('reason'));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "🚫 تم إلغاء فاتورة الشراء #{$purchaseOrder->id} بالكامل.");
    }

    public function cancelRemaining(CancelPurchaseOrderRequest $request, PurchaseOrder $purchaseOrder)
    {
        try {
            $this->cancellationService->cancelRemaining($purchaseOrder, $request->validated('reason'));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "🚫 تم إلغاء الكمية المتبقية غير المستلَمة من فاتورة #{$purchaseOrder->id}، وتم تقييمها تلقائياً ضمن قنص نواقص السوق إن انطبق.");
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

        if ($result->hasLockedSuppliers()) {
            $names = $result->lockedSupplierNames->implode('، ');
            $msg .= " ⛔ تم تخطّي التوليد للموردين التالين لوجود مسودة معلّقة لهم: {$names}.";
        }

        return $msg;
    }

    private function buildEmptyResultMessage($result): string
    {
        return "لا توجد حالياً أي مواد تحتاج شراءً فعلياً (تم فحص {$result->eligibleProductsScanned} مادة مؤهَّلة، "
            . "{$result->productsWithNoRequirement} منها لا يوجد احتياج فعلي حالياً، "
            . "و{$result->productsWithNoSupplier} منها بلا مورد مفضِّل مرتبط بشركتها بعد).";
    }
}
