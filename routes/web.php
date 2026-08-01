<?php

use App\Http\Controllers\InventoryController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Purchasing\PurchaseOrderController;
use App\Http\Controllers\Purchasing\SupplierController;
use App\Http\Controllers\Purchasing\SupplierOfferController;
use App\Http\Controllers\Purchasing\SupplierProductController;
use App\Http\Controllers\Purchasing\SupplierCompanyController;
use App\Http\Controllers\Purchasing\BudgetSettingController;
use App\Http\Controllers\Purchasing\CostVarianceController;
use App\Http\Controllers\Purchasing\HistoricalPurchaseImportController;
use App\Http\Controllers\Purchasing\ReceivingController;
use App\Http\Controllers\Purchasing\PurchaseInventoryController;
use App\Http\Controllers\Purchasing\CategoryController;


Route::redirect('/', '/upload-sales');

Route::get('/upload-sales', [InventoryController::class, 'showUploadForm'])->name('inventory.upload.form');
Route::post('/upload-sales', [InventoryController::class, 'upload'])->name('inventory.upload');

Route::post('/save-mapping', [InventoryController::class, 'saveMapping'])->name('inventory.save-mapping');

Route::get('/shortages', [InventoryController::class, 'shortagesDashboard'])->name('inventory.shortages.dashboard');
Route::post('/shortages/fulfill', [InventoryController::class, 'fulfillShortages'])->name('inventory.shortages.fulfill');

// 🆕 قائمة الفواتير وتفاصيلها
Route::get('/invoices', [InventoryController::class, 'invoicesIndex'])->name('inventory.invoices.index');
Route::get('/invoice/{id}', [InventoryController::class, 'showInvoice'])->name('inventory.invoice.show');

Route::get('/invoice/{id}/export-excel', [InventoryController::class, 'exportInvoiceExcel'])->name('inventory.invoice.excel');

Route::get('/upload-inventory', [InventoryController::class, 'showInventoryUploadForm'])->name('inventory.stock.upload.form');
Route::post('/upload-inventory', [InventoryController::class, 'uploadInventory'])->name('inventory.stock.upload');

Route::post('/pending-inventory/create-all', [InventoryController::class, 'createPendingProducts'])->name('inventory.pending-inventory.create-all');

Route::get('/invoices/export-all', [InventoryController::class, 'exportAllInvoices'])->name('inventory.invoices.export-all');

Route::get('/api/products-search', [InventoryController::class, 'searchProducts'])->name('api.products.search');
Route::get('/api/pharmacies-search', [InventoryController::class, 'searchPharmacies'])->name('api.pharmacies.search');

Route::get('/shortages/export', [InventoryController::class, 'exportShortages'])->name('inventory.shortages.export');
Route::get('/shortages/upload-form', [InventoryController::class, 'showShortagesImportForm'])->name('inventory.shortages.upload.form');
Route::get('/shortages/import', function () {
    return redirect()->route('inventory.shortages.upload.form')
        ->with('warning', '⚠️ تنبيه: انتهت جلسة المطابقة، يرجى إعادة رفع الملف إذا لم يتم الحفظ.');
});
Route::post('/shortages/import', [InventoryController::class, 'importShortages'])->name('inventory.shortages.import');
Route::post('/shortages/save-mapping', [InventoryController::class, 'saveShortagesMapping'])->name('inventory.shortages.save-mapping');

Route::post('/shortages/{id}/resolve', [InventoryController::class, 'resolveShortage'])->name('inventory.shortages.resolve');
Route::post('/shortages/{id}/note', [InventoryController::class, 'updateShortageNote'])->name('inventory.shortages.note');


Route::prefix('inventory')->name('inventory.')->group(function () {
    Route::get('import-status/{sessionId}', [InventoryController::class, 'importStatus'])
        ->name('import.status');
    Route::get('pending-inventory/{sessionId}', [InventoryController::class, 'showPendingInventory'])
        ->name('pending-inventory.show');
    Route::get('mapping/{sessionId}', [InventoryController::class, 'showMapping'])
        ->name('mapping.show');
    Route::get('shortages/mapping/{sessionId}', [InventoryController::class, 'showShortagesMapping'])
        ->name('shortages.mapping.show');
});


Route::prefix('purchasing')->name('purchasing.')->group(function () {

    // ══ فواتير الشراء المقترحة ══
    Route::get('generate', [PurchaseOrderController::class, 'create'])->name('orders.create');
    Route::post('generate', [PurchaseOrderController::class, 'store'])->name('orders.store');
    // 🆕 شاشة حل تعارض الموردين (أكثر من مورد يفضّل نفس الشركة)
    Route::get('generate/resolve-conflicts', [PurchaseOrderController::class, 'showResolveConflicts'])->name('orders.resolve-conflicts');
    Route::post('generate/resolve-conflicts', [PurchaseOrderController::class, 'resolveConflicts'])->name('orders.resolve-conflicts.save');
    Route::get('orders', [PurchaseOrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->name('orders.show');
    Route::post('orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])->name('orders.approve');

    // ══ إدارة الموردين ══
    Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    Route::get('suppliers/create', [SupplierController::class, 'create'])->name('suppliers.create');
    Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
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

    // ══ الاستلام الفعلي (قنص نواقص السوق) — لا يؤثر على أي جرد بعد اليوم ══
    Route::get('receiving', [ReceivingController::class, 'showUploadForm'])->name('receiving.form');
    Route::post('receiving', [ReceivingController::class, 'upload'])->name('receiving.upload');
    Route::get('receiving/mapping/{sessionId}', [ReceivingController::class, 'showMapping'])->name('receiving.mapping');
    Route::post('receiving/finalize', [ReceivingController::class, 'finalize'])->name('receiving.finalize');

    // ══ استيراد تاريخي لأسعار الموردين — يبقى بلا حذف ══
    Route::get('historical-import', [HistoricalPurchaseImportController::class, 'showForm'])->name('historical-import.form');
    Route::post('historical-import', [HistoricalPurchaseImportController::class, 'upload'])->name('historical-import.upload');

    // ══ مراقب تذبذب التكلفة ══
    Route::get('cost-variance', [CostVarianceController::class, 'index'])->name('cost-variance.index');

    // ══ إلغاء/رفض فواتير الشراء ══
    Route::post('orders/{purchaseOrder}/reject', [PurchaseOrderController::class, 'reject'])->name('orders.reject');
    Route::post('orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('orders.cancel');
    Route::post('orders/{purchaseOrder}/cancel-remaining', [PurchaseOrderController::class, 'cancelRemaining'])->name('orders.cancel-remaining');

    // 🆕 ══ جرد المشتريات الموحّد — يستبدل شاشة "أسعار البيع الرسمية" ══
    Route::get('purchase-inventory', [PurchaseInventoryController::class, 'showUploadForm'])->name('purchase-inventory.form');
    Route::post('purchase-inventory', [PurchaseInventoryController::class, 'upload'])->name('purchase-inventory.upload');

    // ⚠️ مسارات "أسعار البيع الرسمية" القديمة أُزيلت من هنا بالكامل —
    // الكونترولر والـ Views تبقى بالكود بلا حذف (بقرار صريح: التنظيف لاحقاً)
    // لكن لا وصول فعلياً إليها من الواجهة بعد الآن.

   // ══ التصنيفات/الاستطباب — بلا أي تعديل، كما هي تماماً ══
   Route::get('categories', [CategoryController::class, 'showForm'])->name('categories.form');
   Route::post('categories/upload-master', [CategoryController::class, 'uploadMasterList'])->name('categories.upload-master');
   Route::post('categories/upload-links', [CategoryController::class, 'uploadLinks'])->name('categories.upload-links');
   Route::get('categories/mapping/{sessionId}', [CategoryController::class, 'showMapping'])->name('categories.mapping');
   Route::post('categories/save-mapping', [CategoryController::class, 'saveMapping'])->name('categories.save-mapping');
});
