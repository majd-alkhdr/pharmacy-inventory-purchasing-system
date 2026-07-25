<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

class ImportHistoricalPricesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'prices_file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:20480'],
        ];
    }

    public function messages(): array
    {
        return [
            'prices_file.required' => 'يرجى اختيار ملف الأسعار التاريخية.',
            'prices_file.max'       => 'حجم الملف يتجاوز الحد المسموح (20MB).',
        ];
    }
}
