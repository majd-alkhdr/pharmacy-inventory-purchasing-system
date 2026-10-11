<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\ImportFileType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StandardStockLookupRequest;
use App\Models\ImportLog;
use App\Models\Pharmacy;
use App\Models\Product;
use App\Services\Inventory\StandardStockLookupQuery;

/**
 * 🎯 (ب.1) شاشة بحث المخزون القياسي: مادة × صيدلية ← القيمة الحالية + آخر رفعة ناجحة
 * لملف المخزون القياسي (من سجل الرفع الموحَّد، أ.1). تجيب السؤالين المتكررين بشاشة واحدة:
 * "ما قيمة X بـ Y؟" و"هل نجح آخر رفع للمخزون القياسي ومتى؟".
 */
class StandardStockLookupController extends Controller
{
    public function __construct(
        private readonly StandardStockLookupQuery $lookup,
    ) {
    }

    public function index(StandardStockLookupRequest $request)
    {
        return view('inventory.standard-stock-lookup', [
            'results' => $request->hasCriteria() ? $this->lookup->search($request->productId(), $request->pharmacyId()) : null,
            'lastUpload' => ImportLog::latestSuccessful(ImportFileType::PharmacyMinimumStock),
            'lastAttempt' => ImportLog::query()->ofType(ImportFileType::PharmacyMinimumStock)->latest('id')->first(),
            // لإعادة تعبئة حقلي select2 بعد البحث (القيمة المختارة تحتاج نصها لا رقمها فقط).
            'selectedProduct' => $request->productId() ? Product::find($request->productId()) : null,
            'selectedPharmacy' => $request->pharmacyId() ? Pharmacy::find($request->pharmacyId()) : null,
        ]);
    }
}
