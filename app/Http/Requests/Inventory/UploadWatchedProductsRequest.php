<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class UploadWatchedProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'watched_products_file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'watched_products_file.required' => 'يرجى إرفاق ملف الأصناف المطلوب مراقبتها.',
            'watched_products_file.mimes'     => 'صيغة الملف غير مدعومة (xlsx, xls, csv فقط).',
            'watched_products_file.max'       => 'حجم الملف يتجاوز الحد المسموح (10MB).',
        ];
    }
}
