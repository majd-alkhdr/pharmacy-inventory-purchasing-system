<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class UploadPharmacyMinimumStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'min_stock_file' => [
                'required',
                'file',
                'max:20480', // 🆕 20MB (كانت 10MB) — بطلب صريح، ملفات المخزون القياسي الكبيرة تتجاوز الحد القديم
                // 🩹 mimetypes: من البداية — درس مستفاد من مشكلة ملف
                // التصنيفات ذي العمود الواحد المُكتشَف كـ text/plain سابقاً.
                'mimetypes:' . implode(',', [
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/vnd.ms-excel',
                    'text/csv',
                    'text/plain',
                    'application/csv',
                    'text/comma-separated-values',
                ]),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'min_stock_file.required'  => 'يرجى اختيار الملف.',
            'min_stock_file.mimetypes' => 'صيغة الملف غير مدعومة — يرجى رفع ملف Excel (xlsx/xls) أو CSV صحيح.',
            'min_stock_file.max'        => 'حجم الملف يتجاوز الحد المسموح (20MB).',
        ];
    }
}
