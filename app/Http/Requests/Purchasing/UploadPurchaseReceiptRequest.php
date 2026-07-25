<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

class UploadPurchaseReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchase_order_id' => ['required', 'integer', 'exists:purchase_orders,id'],
            'receipt_file'        => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'purchase_order_id.required' => 'يرجى اختيار فاتورة الشراء المرتبطة بهذا الاستلام.',
            'receipt_file.required'       => 'يرجى اختيار ملف الاستلام.',
            'receipt_file.mimes'          => 'صيغة الملف غير مدعومة (xlsx, xls, csv فقط).',
        ];
    }
}
