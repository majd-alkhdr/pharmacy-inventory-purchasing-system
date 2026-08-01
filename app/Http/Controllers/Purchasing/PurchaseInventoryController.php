<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\UploadPurchaseInventoryRequest;
use App\Imports\PurchaseInventoryImport;
use App\Services\Purchasing\PurchaseInventoryUploadService;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * 🎯 يستبدل عملياً شاشتَي "أسعار البيع الرسمية" و"استيراد تاريخي" كمصدر
 * تغذية منفصل — كل شيء الآن عبر ملف جرد مشتريات موحّد واحد. راجع وثيقة
 * التصميم §5 لملخص الأثر الكامل على الكونترولرات.
 */
class PurchaseInventoryController extends Controller
{
    public function __construct(
        private readonly PurchaseInventoryUploadService $uploadService,
    ) {
    }

    public function showUploadForm()
    {
        return view('purchasing.purchase-inventory-upload');
    }

    public function upload(UploadPurchaseInventoryRequest $request)
    {
        $filePath = $request->file('inventory_file')->store('imports');

        $import = new PurchaseInventoryImport();
        Excel::import($import, Storage::path($filePath));
        Storage::delete($filePath);

        if ($import->rows->isEmpty()) {
            return back()->with('error', 'الملف فارغ أو لا يحتوي على بيانات صالحة.');
        }

        $result = $this->uploadService->process($import->rows);

        return view('purchasing.purchase-inventory-result', ['result' => $result]);
    }
}
