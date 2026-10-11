<?php

use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Inventory\TransferExecutionController;
use App\Http\Controllers\Inventory\StandardStockLookupController;
use App\Http\Controllers\Inventory\InvoiceEditController;
use App\Http\Controllers\Inventory\TransferReconciliationController;
use App\Http\Controllers\Inventory\FinanceSettlementController;
use App\Http\Controllers\Purchasing\SupplierScopeController;
use App\Http\Controllers\Purchasing\PurchaseTransferOptionsController;
use App\Http\Controllers\ImportHistoryController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\PharmacyMinimumStockController;
use App\Http\Controllers\Purchasing\BudgetSettingController;
use App\Http\Controllers\Purchasing\CategoryController;
use App\Http\Controllers\Purchasing\CostVarianceController;
use App\Http\Controllers\Purchasing\HistoricalPurchaseImportController;
use App\Http\Controllers\Purchasing\MinimumStockController;
use App\Http\Controllers\Purchasing\ProductClearanceController;
use App\Http\Controllers\Purchasing\PurchaseOrderController;
use App\Http\Controllers\Purchasing\ReceivingController;
use App\Http\Controllers\Purchasing\SupplierCompanyController;
use App\Http\Controllers\Purchasing\SupplierController;
use App\Http\Controllers\Purchasing\SupplierOfferController;
use App\Http\Controllers\Purchasing\SupplierProductController;
use App\Http\Controllers\Purchasing\ManualSalesRateController;
use App\Http\Controllers\Purchasing\ProductSearchController;
use App\Http\Controllers\TransfersReportController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Purchasing\Reports\PurchaseValueReportController;
use App\Http\Controllers\Purchasing\Reports\PurchaseShortageValueReportController;
use App\Http\Controllers\Purchasing\Reports\SupplierPurchaseSummaryReportController;

// 🆕 يستبدل Route::redirect('/', '/upload-sales') الثابت — ذاك كان
// يوجّه أي زائر لصفحة خاصة بالمناقلات حصراً قبل أي فحص هوية، فيصطدم
// بها مستخدم "مشتريات" عبر آلية intended() فور تسجيل دخوله (راجع نقاش
// التشخيص). هنا: غير المسجَّل → صفحة الدخول مباشرة (بلا حفظ intended
// لصفحة لا علاقة لها بنيّته الفعلية)، المسجَّل → نفس منطق الفرز حسب
// الدور المستخدَم أصلاً بعد تسجيل الدخول، بدل تكراره بمكان مختلف.
Route::get('/', function () {
    if (!auth()->check()) {
        return redirect()->route('login');
    }

    return redirect(\App\Http\Controllers\AuthController::defaultRedirectFor(auth()->user()));
})->name('home');

/*
|--------------------------------------------------------------------------
| المصادقة — بلا أي طريق تسجيل ذاتي (registration) إطلاقاً، بقرار صريح.
| الحسابات تُنشأ فقط عبر شاشة "إدارة المستخدمين" (قسم admin. أدناه).
|--------------------------------------------------------------------------
*/
Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login')->middleware('guest');
Route::post('/login', [AuthController::class, 'login'])->name('login.post')->middleware('guest');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

/*
|--------------------------------------------------------------------------
| قسم العمليات (المناقلات)
|--------------------------------------------------------------------------
| 🎯 إعادة تنظيم بحتة على مستوى الأسماء — بلا أي تغيير على مسار URL أو
| اسم Route واحد. كل route('inventory.xxx') بأي Blade يستمر بالعمل حرفياً.
|
| 🆕 [صلاحيات] هذا القسم انقسم الآن إلى مجموعتين بميدلوير مختلف:
| - "عمليات المناقلات" الفعلية (رفع، فواتير، نواقص...) تحتاج
|   module:transfers_operations.
| - "تقارير المناقلات" (نطاق فرعي منفصل بنفس بادئة الاسم) تحتاج
|   module:transfers_reports فقط — مستخدم دوره "تقارير" يصل لهذه
|   المجموعة تحديداً بلا الوصول للمجموعة الأولى إطلاقاً.
| كلا المجموعتين لا يزالان تحت نفس Route::name('inventory.') الخارجي،
| فالأسماء النهائية (inventory.upload.form، inventory.reports.inventory-value)
| تبقى تماماً كما كانت — لا شيء بأي Blade يحتاج تعديلاً بسبب هذا التقسيم.
*/
Route::name('inventory.')->middleware(['auth', 'active'])->group(function () {

    Route::middleware('module:transfers_operations')->group(function () {

        // ── رفع المبيعات والجرد اليومي (تدفق الرفع الرئيسي) ──
        Route::get('/upload-sales', [InventoryController::class, 'showUploadForm'])->name('upload.form');
        Route::post('/upload-sales', [InventoryController::class, 'upload'])->name('upload');
        Route::post('/save-mapping', [InventoryController::class, 'saveMapping'])->name('save-mapping');
        Route::post('/pending-inventory/create-all', [InventoryController::class, 'createPendingProducts'])->name('pending-inventory.create-all');

        // ── جرد المستودع المركزي (شاشة مستقلة) ──
        Route::get('/upload-inventory', [InventoryController::class, 'showInventoryUploadForm'])->name('stock.upload.form');
        Route::post('/upload-inventory', [InventoryController::class, 'uploadInventory'])->name('stock.upload');

        // ── المخزون القياسي للصيدليات (كونترولر مستقل، لكن نفس نطاق الأسماء) ──
        Route::get('/pharmacy-minimum-stock', [PharmacyMinimumStockController::class, 'showUploadForm'])->name('pharmacy-minimum-stock.form');
        Route::post('/pharmacy-minimum-stock', [PharmacyMinimumStockController::class, 'upload'])->name('pharmacy-minimum-stock.upload');
        // 🆕 (ب.1) عرض/بحث المخزون القياسي: مادة × صيدلية + آخر رفع ناجح.
        Route::get('/standard-stock', [StandardStockLookupController::class, 'index'])->name('standard-stock.lookup');

        // 🆕 ── بيانات المواد (رمز/اسم/شركة/سعر/حجم عبوة/حد أدنى للفرق — ملف مستقل، لا علاقة له بدفعة الترميم) ──
        Route::get('/material-master', [InventoryController::class, 'showMaterialMasterUploadForm'])->name('material-master.form');
        Route::post('/material-master', [InventoryController::class, 'uploadMaterialMaster'])->name('material-master.upload');

        // 🆕 ── أصناف للمراقبة ──
        Route::get('/watched-products', [InventoryController::class, 'showWatchedProductsUploadForm'])->name('watched-products.form');
        Route::post('/watched-products', [InventoryController::class, 'uploadWatchedProducts'])->name('watched-products.upload');
        Route::delete('/watched-products/{id}', [InventoryController::class, 'removeWatchedProduct'])->name('watched-products.remove');

        // 🆕 ── شركات مستثناة من الترميم (قائمة دائمة، لا شاشة اختيار لكل دفعة بعد الآن) ──
        Route::get('/excluded-companies', [InventoryController::class, 'showExcludedCompaniesForm'])->name('excluded-companies.form');
        Route::post('/excluded-companies', [InventoryController::class, 'addExcludedCompany'])->name('excluded-companies.add');
        Route::delete('/excluded-companies/{id}', [InventoryController::class, 'removeExcludedCompany'])->name('excluded-companies.remove');

        // ── حالة المعالجة الخلفية + شاشات المطابقة ──
        // 🩹 بادئة URL حرفية /inventory/ هنا فقط — كما كانت بالملف الأصلي
        // بالضبط، مجموعة فرعية متداخلة، لا نطاق URL جديد.
        Route::prefix('inventory')->group(function () {
            Route::get('import-status/{sessionId}', [InventoryController::class, 'importStatus'])->name('import.status');
            Route::get('pending-inventory/{sessionId}', [InventoryController::class, 'showPendingInventory'])->name('pending-inventory.show');
            Route::get('mapping/{sessionId}', [InventoryController::class, 'showMapping'])->name('mapping.show');
            Route::get('shortages/mapping/{sessionId}', [InventoryController::class, 'showShortagesMapping'])->name('shortages.mapping.show');
        });

        // ── الفواتير الصادرة ──
        Route::get('/invoices', [InventoryController::class, 'invoicesIndex'])->name('invoices.index');
        Route::get('/invoice/{id}', [InventoryController::class, 'showInvoice'])->name('invoice.show');
        Route::get('/invoice/{id}/export-excel', [InventoryController::class, 'exportInvoiceExcel'])->name('invoice.excel');
        // 🆕 (ب.2) تعديل الفاتورة قبل التصدير (كميات/حذف/إضافة) — يُقفل بعد أول تصدير.
        Route::put('/invoice/{id}/items', [InvoiceEditController::class, 'update'])->whereNumber('id')->name('invoice.items.update');
        // 🆕 (ب.3) مطابقة الفاتورة مع التحضير الفعلي — خطوة مستقلة بعد التحضير المادي.
        Route::get('/invoice/{id}/reconciliation', [TransferReconciliationController::class, 'show'])->whereNumber('id')->name('invoice.reconciliation');
        Route::get('/invoice/{id}/reconciliation/template', [TransferReconciliationController::class, 'template'])->whereNumber('id')->name('invoice.reconciliation.template');
        Route::post('/invoice/{id}/reconciliation', [TransferReconciliationController::class, 'upload'])->whereNumber('id')->name('invoice.reconciliation.upload');
        Route::post('/reconciliation-items/{itemId}/reason', [TransferReconciliationController::class, 'setReason'])->whereNumber('itemId')->name('reconciliation-item.reason');
        // 🆕 (ب.3) مواد تحتاج تسوية من المالية — ضمن العمليات فقط (لا دور مالية بالنظام).
        Route::get('/finance-settlements', [FinanceSettlementController::class, 'index'])->name('finance-settlements.index');
        Route::get('/finance-settlements/export', [FinanceSettlementController::class, 'export'])->name('finance-settlements.export');
        Route::get('/invoices/export-all', [InventoryController::class, 'exportAllInvoices'])->name('invoices.export-all');
        Route::get('/invoices/export-preparation', [InventoryController::class, 'exportPreparationList'])->name('invoices.export-preparation');

        // ── النواقص والعجز ──
        Route::get('/shortages', [InventoryController::class, 'shortagesDashboard'])->name('shortages.dashboard');
        Route::get('/shortages/export', [InventoryController::class, 'exportShortages'])->name('shortages.export');
        Route::post('/shortages/fulfill', [InventoryController::class, 'fulfillShortages'])->name('shortages.fulfill');
        Route::post('/shortages/{id}/resolve', [InventoryController::class, 'resolveShortage'])->name('shortages.resolve');
        Route::post('/shortages/{id}/note', [InventoryController::class, 'updateShortageNote'])->name('shortages.note');

        // ── استيراد نواقص تاريخية ──
        Route::get('/shortages/upload-form', [InventoryController::class, 'showShortagesImportForm'])->name('shortages.upload.form');
        Route::get('/shortages/import', function () {
            return redirect()->route('inventory.shortages.upload.form')
                ->with('warning', '⚠️ تنبيه: انتهت جلسة المطابقة، يرجى إعادة رفع الملف إذا لم يتم الحفظ.');
        }); // 🩹 بلا name() — نفس الأصل بالضبط: رابط أمان فقط، غير مُستهدَف بالاسم من أي مكان
        Route::post('/shortages/import', [InventoryController::class, 'importShortages'])->name('shortages.import');
        Route::post('/shortages/save-mapping', [InventoryController::class, 'saveShortagesMapping'])->name('shortages.save-mapping');

        // ── المناقلات العكسية ──
        Route::get('/reverse-transfers', [InventoryController::class, 'reverseTransfersReport'])->name('reverse-transfers.report');
        Route::get('/reverse-transfers/export', [InventoryController::class, 'exportReverseTransfers'])->name('reverse-transfers.export');
        // 🆕 مستند مناقلة عكسية واحدة — يجب أن تبقى بعد /export بالترتيب (راجع تعليق أعلاه ما ينطبق هنا أيضاً: مسار حرفي قبل {id}).
        Route::get('/reverse-transfers/{id}', [InventoryController::class, 'showReverseTransfer'])->name('reverse-transfer.show');
        Route::get('/reverse-transfers/{id}/export-excel', [InventoryController::class, 'exportReverseTransferExcel'])->name('reverse-transfer.excel');
        // 🆕 (ب.6) تنفيذ/رفض مقترحة عكسية — نفس مسار التنفيذ الموحَّد (TransferExecutionService).
        Route::post('/reverse-transfers/{id}/execute', [TransferExecutionController::class, 'executeReverse'])->whereNumber('id')->name('reverse-transfer.execute');
        Route::post('/reverse-transfers/{id}/dismiss', [TransferExecutionController::class, 'dismissReverse'])->whereNumber('id')->name('reverse-transfer.dismiss');

        // 🆕 ── الطلب رقم 4: مناقلات مباشرة مقترَحة بين صيدليتين (بلا مرور بالمستودع) ──
        Route::get('/pharmacy-transfers', [InventoryController::class, 'pharmacyTransfersReview'])->name('pharmacy-transfers.review');
        Route::post('/pharmacy-transfers/refresh', [InventoryController::class, 'refreshPharmacyTransferSuggestions'])->name('pharmacy-transfers.refresh');
        // 🆕 (ب.7) نفس أسماء المسارات القديمة (لا Blade يتغيّر بسببها) — موجَّهة الآن لمسار التنفيذ
        // الموحَّد الذي يحجز الفائض. الدالتان القديمتان بـ InventoryController مُجمَّدتان بلا حذف.
        Route::post('/pharmacy-transfers/{id}/approve', [TransferExecutionController::class, 'executePharmacy'])->whereNumber('id')->name('pharmacy-transfer.approve');
        Route::post('/pharmacy-transfers/{id}/dismiss', [TransferExecutionController::class, 'dismissPharmacy'])->whereNumber('id')->name('pharmacy-transfer.dismiss');
        // مسار حرفي {id} أخيراً لنفس سبب /reverse-transfers/{id} أعلاه — بعد كل المسارات الحرفية الأخرى بهذه المجموعة.
        Route::get('/pharmacy-transfers/{id}', [InventoryController::class, 'showPharmacyTransfer'])->name('pharmacy-transfer.show');
        Route::get('/pharmacy-transfers/{id}/export-excel', [InventoryController::class, 'exportPharmacyTransferExcel'])->name('pharmacy-transfer.excel');
    });

    // 🆕 [صلاحيات] تقارير المناقلات — module منفصل عمداً عن العمليات
    // أعلاه، راجع تعليق أعلى الملف للسبب الكامل.
    Route::name('reports.')->middleware('module:transfers_reports')->group(function () {
        Route::get('/reports/inventory-value', [TransfersReportController::class, 'inventoryValue'])->name('inventory-value');
        Route::get('/reports/transfers-value', [TransfersReportController::class, 'transfersValue'])->name('transfers-value');
        Route::get('/reports/shortage-value', [TransfersReportController::class, 'shortageValue'])->name('shortage-value');
        Route::get('/reports/inventory-turnover', [TransfersReportController::class, 'inventoryTurnover'])->name('inventory-turnover');
        Route::get('/reports/product-movement', [TransfersReportController::class, 'productMovement'])->name('product-movement');
    });
});

/*
|--------------------------------------------------------------------------
| 🆕 (أ.1) سجل الرفع الموحَّد — مشترك بين القسمين. الصلاحية "أي قسم عمليات"
| تُفحص بـ ImportHistoryFilterRequest::authorize() (الـ middleware الأحادي لا يعبّر عن "أو").
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/import-history', [ImportHistoryController::class, 'index'])->name('import-history.index');
    Route::get('/import-history/{importLog}', [ImportHistoryController::class, 'show'])->whereNumber('importLog')->name('import-history.show');
});

/*
|--------------------------------------------------------------------------
| نقاط بحث AJAX — تخدم شاشات المناقلات حصراً، فبنفس صلاحيتها
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'active', 'module:transfers_operations'])->group(function () {
    Route::get('/api/products-search', [InventoryController::class, 'searchProducts'])->name('api.products.search');
    Route::get('/api/pharmacies-search', [InventoryController::class, 'searchPharmacies'])->name('api.pharmacies.search');
});

/*
|--------------------------------------------------------------------------
| قسم المشتريات — بلا أي تعديل على محتواه، فقط ميدلوير صلاحيات إضافي
|--------------------------------------------------------------------------
| ⚠️ [ملاحظة لمن يعيد هيكلة المشتريات لاحقاً] هذا القسم بأكمله يحمل
| module:purchasing_operations حالياً — لا يوجد module:purchasing_reports
| بعد لأن تقارير المشتريات غير موجودة بهذا الكود أصلاً. حين تُبنى، يجب أن
| تُفصَل لمجموعة فرعية بنفس أسلوب "تقارير المناقلات" أعلاه بالضبط، لا أن
| تبقى ضمن نفس مجموعة العمليات — وإلا سيرى دور "مشتريات" العمليات بينما
| يُفترض أن يراها دور "تقارير" فقط أيضاً.
|--------------------------------------------------------------------------
*/
Route::prefix('purchasing')->name('purchasing.')->middleware(['auth', 'active', 'module:purchasing_operations'])->group(function () {

    // ══ فواتير الشراء المقترحة ══
    Route::get('generate', [PurchaseOrderController::class, 'create'])->name('orders.create');
    Route::post('generate', [PurchaseOrderController::class, 'store'])->name('orders.store');
    // 🆕 شاشة حل تعارض الموردين (أكثر من مورد يفضّل نفس الشركة)
    Route::get('generate/resolve-conflicts', [PurchaseOrderController::class, 'showResolveConflicts'])->name('orders.resolve-conflicts');
    Route::post('generate/resolve-conflicts', [PurchaseOrderController::class, 'resolveConflicts'])->name('orders.resolve-conflicts.save');
    // 🆕 (ج.1) استراتيجية "اختيار شركة": الموردون الذين يحملونها (AJAX).
    Route::get('generate/suppliers-for-company', [SupplierScopeController::class, 'suppliersForCompany'])->name('orders.suppliers-for-company');
    Route::get('orders', [PurchaseOrderController::class, 'index'])->name('orders.index');
    // ══════════════════════════════════════════════════════════════
    // 🩹 تصحيح ذاتي: النسخة الأولى من هذا المقتطف كانت ستضع
    // 'orders/export-all' *بعد* 'orders/{purchaseOrder}' — وهذا خطأ توجيه
    // كلاسيكي بـ Laravel (نفس النمط الموثَّق أصلاً بمشروعك بتعليق
    // "shortages/export" بملف web.php الأصلي): كلمة "export-all" كانت
    // ستُفهَم كأنها {purchaseOrder} (رقم فاتورة)، فتفشل بخطأ 404 عند البحث
    // عن فاتورة برقم "export-all" غير الموجود.
    //
    // الترتيب الصحيح: المسار الثابت (بلا {متغيّر}) يجب أن يُكتب *قبل* أي
    // مسار بنفس عدد الأجزاء يحتوي {متغيّر}. لذلك:
    // ══════════════════════════════════════════════════════════════
    // 1) ضع هذا السطر *قبل* سطر orders.show الموجود أصلاً (وليس بعده):
    Route::get('orders/export-all', [PurchaseOrderController::class, 'exportAllExcel'])->name('orders.export-all');
    Route::get('orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->name('orders.show');
    // 2) هذا السطر آمن أن يُضاف في أي مكان بعد orders.show (لا تعارض إطلاقاً،
    //    لأن له جزءاً إضافياً بالمسار "export-excel" يميّزه عن orders/{id} المفردة):
    Route::get('orders/{purchaseOrder}/export-excel', [PurchaseOrderController::class, 'exportExcel'])->name('orders.export-excel');
    Route::post('orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])->name('orders.approve');
    Route::post('orders/{purchaseOrder}/update-items', [PurchaseOrderController::class, 'updateItems'])->name('orders.update-items');
    // 🆕 (ج.3) زر "مناقلات؟": خيارات الفائض (قراءة) + تنفيذ مناقلة عكسية (نفس مسار ب.6).
    Route::get('orders/{purchaseOrder}/items/{purchaseOrderItem}/transfer-options', [PurchaseTransferOptionsController::class, 'options'])->name('orders.items.transfer-options');
    Route::post('orders/{purchaseOrder}/items/{purchaseOrderItem}/transfer', [PurchaseTransferOptionsController::class, 'execute'])->name('orders.items.transfer');

    // ══ إدارة الموردين ══
    Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    Route::get('suppliers/create', [SupplierController::class, 'create'])->name('suppliers.create');
    Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');

    Route::get('suppliers/bulk-import-companies', [SupplierCompanyController::class, 'showBulkImportForm'])->name('suppliers.companies.bulk-import-form');
    Route::post('suppliers/bulk-import-companies', [SupplierCompanyController::class, 'bulkImport'])->name('suppliers.companies.bulk-import');

    Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show');
    Route::get('suppliers/{supplier}/edit', [SupplierController::class, 'edit'])->name('suppliers.edit');
    Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');

    // 🆕 ══ الشركات المفضّلة لكل مورد — أساس اختيار المورد الجديد ══
    Route::post('suppliers/{supplier}/companies', [SupplierCompanyController::class, 'store'])->name('suppliers.companies.store');
    Route::delete('suppliers/{supplier}/companies/{supplierCompany}', [SupplierCompanyController::class, 'destroy'])->name('suppliers.companies.destroy');

    // ══ ربط مادة بمورد (سعر) — يبقى بلا حذف، تمهيداً لعودة الأسعار لاحقاً ══
    Route::post('suppliers/{supplier}/products', [SupplierProductController::class, 'store'])->name('suppliers.products.store');
    Route::delete('suppliers/{supplier}/products/{supplierProduct}', [SupplierProductController::class, 'destroy'])->name('suppliers.products.destroy');

    // ══ عروض/بونص لكل رابطة مورد-مادة — يبقى بلا حذف ══
    Route::post('supplier-products/{supplierProduct}/offers', [SupplierOfferController::class, 'store'])->name('supplier-products.offers.store');
    Route::delete('supplier-products/{supplierProduct}/offers/{offer}', [SupplierOfferController::class, 'destroy'])->name('supplier-products.offers.destroy');

    // ══ إعدادات الميزانية الشهرية ══
    Route::get('budget-settings', [BudgetSettingController::class, 'index'])->name('budget-settings.index');
    Route::post('budget-settings', [BudgetSettingController::class, 'update'])->name('budget-settings.update');

    // ── الاستلام الفعلي: شاشة الاختيار أولاً (نقطة الدخول الجديدة) ──
    Route::get('receiving', [ReceivingController::class, 'chooseMethod'])->name('receiving.choose');

    // ── السيناريو 1: رفع ملف (نفس الدوال القديمة تماماً، فقط تغيّر مسار الرابط) ──
    Route::get('receiving/upload', [ReceivingController::class, 'showUploadForm'])->name('receiving.upload-form');
    Route::post('receiving/upload', [ReceivingController::class, 'upload'])->name('receiving.upload');
    Route::get('receiving/mapping/{sessionId}', [ReceivingController::class, 'showMapping'])->name('receiving.mapping');
    Route::post('receiving/finalize', [ReceivingController::class, 'finalize'])->name('receiving.finalize');

    // ── 🆕 السيناريو 2: تدقيق يدوي مباشر ──
    Route::get('receiving/manual', [ReceivingController::class, 'selectOrderManual'])->name('receiving.manual.select');
    Route::get('receiving/manual/{purchaseOrder}', [ReceivingController::class, 'showManualForm'])->name('receiving.manual.show');
    Route::post('receiving/manual/{purchaseOrder}', [ReceivingController::class, 'finalizeManual'])->name('receiving.manual.finalize');

    // ══ استيراد تاريخي لأسعار الموردين — يبقى بلا حذف ══
    Route::get('historical-import', [HistoricalPurchaseImportController::class, 'showForm'])->name('historical-import.form');
    Route::post('historical-import', [HistoricalPurchaseImportController::class, 'upload'])->name('historical-import.upload');

    // 🆕 يستبدل مسار cost-variance المفرد القديم — بنفس أسلوب inventory.reports.*
    // المتداخل بالضبط. اسمه الكامل يصبح purchasing.reports.cost-variance (كان
    // purchasing.cost-variance) — لم يكن مرتبطاً بأي رابط شريط جانبي أصلاً
    // (تحققتُ بـ grep منذ بداية المراجعة)، فلا رابط قديم يُكسَر.
    Route::middleware('module:purchasing_reports')->name('reports.')->prefix('reports')->group(function () {
        Route::get('cost-variance', [CostVarianceController::class, 'index'])->name('cost-variance');
        Route::get('purchase-value', [PurchaseValueReportController::class, 'index'])->name('purchase-value');
        Route::get('purchase-shortage-value', [PurchaseShortageValueReportController::class, 'index'])->name('purchase-shortage-value');
        Route::get('supplier-summary', [SupplierPurchaseSummaryReportController::class, 'index'])->name('supplier-summary');
    });

    // ══ إلغاء/رفض فواتير الشراء ══
    Route::post('orders/{purchaseOrder}/reject', [PurchaseOrderController::class, 'reject'])->name('orders.reject');
    Route::post('orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('orders.cancel');
    Route::post('orders/{purchaseOrder}/cancel-remaining', [PurchaseOrderController::class, 'cancelRemaining'])->name('orders.cancel-remaining');

    // ⚠️ (2026-10) "جرد المشتريات" أُلغي: رصيد المستودع من ملف المصفوفة، والسعر من ملف الأصناف.

    Route::get('minimum-stock', [MinimumStockController::class, 'showUploadForm'])->name('minimum-stock.form');
    Route::post('minimum-stock', [MinimumStockController::class, 'upload'])->name('minimum-stock.upload');
    // ⚠️ مسارات "أسعار البيع الرسمية" القديمة أُزيلت من هنا بالكامل —
    // الكونترولر والـ Views تبقى بالكود بلا حذف (بقرار صريح: التنظيف لاحقاً)
    // لكن لا وصول فعلياً إليها من الواجهة بعد الآن.

    // ══ التصنيفات/الاستطباب — بلا أي تعديل، كما هي تماماً ══
    Route::get('categories', [CategoryController::class, 'showForm'])->name('categories.form');
    Route::post('categories/upload-master', [CategoryController::class, 'uploadMasterList'])->name('categories.upload-master');
    Route::post('categories/upload-links', [CategoryController::class, 'uploadLinks'])->name('categories.upload-links');
    Route::get('categories/mapping/{sessionId}', [CategoryController::class, 'showMapping'])->name('categories.mapping');
    Route::post('categories/save-mapping', [CategoryController::class, 'saveMapping'])->name('categories.save-mapping');

    // ── 2) 🆕 إدارة الأصناف قيد التصفية — أضف كمجموعة مستقلة بأي مكان بقسم purchasing ──
    Route::get('clearance', [ProductClearanceController::class, 'index'])->name('clearance.index');
    Route::post('clearance', [ProductClearanceController::class, 'store'])->name('clearance.store');
    Route::delete('clearance/{productPurchaseSetting}', [ProductClearanceController::class, 'destroy'])->name('clearance.destroy');

    Route::get('manual-sales-rate', [ManualSalesRateController::class, 'showUploadForm'])->name('manual-sales-rate.form');
    Route::post('manual-sales-rate', [ManualSalesRateController::class, 'upload'])->name('manual-sales-rate.upload');
    Route::get('manual-sales-rate/mapping/{sessionId}', [ManualSalesRateController::class, 'showMapping'])->name('manual-sales-rate.mapping');
    Route::post('manual-sales-rate/save-mapping', [ManualSalesRateController::class, 'saveMapping'])->name('manual-sales-rate.save-mapping');

    Route::get('products-search', [ProductSearchController::class, 'search'])->name('products-search');
});

/*
|--------------------------------------------------------------------------
| 🆕 إدارة المستخدمين — مدير النظام فقط، بصرف النظر عن أي module آخر
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->name('admin.')->middleware(['auth', 'active', 'admin'])->group(function () {
    Route::get('users', [UserManagementController::class, 'index'])->name('users.index');
    Route::get('users/create', [UserManagementController::class, 'create'])->name('users.create');
    Route::post('users', [UserManagementController::class, 'store'])->name('users.store');
    Route::get('users/{user}/edit', [UserManagementController::class, 'edit'])->name('users.edit');
    Route::put('users/{user}', [UserManagementController::class, 'update'])->name('users.update');
    Route::post('users/{user}/reset-password', [UserManagementController::class, 'resetPassword'])->name('users.reset-password');
    Route::delete('users/{user}', [UserManagementController::class, 'destroy'])->name('users.destroy');
});
