<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

class UploadPurchaseInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'inventory_file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:20480'],
        ];
    }

    public function messages(): array
    {
        return [
            'inventory_file.required' => 'يرجى اختيار ملف جرد المشتريات.',
            'inventory_file.mimes'    => 'صيغة الملف غير مدعومة (xlsx, xls, csv فقط).',
            'inventory_file.max'      => 'حجم الملف يتجاوز الحد المسموح (20MB).',
        ];
    }
}
