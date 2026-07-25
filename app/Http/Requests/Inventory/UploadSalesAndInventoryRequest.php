<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class UploadSalesAndInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // 🩹 لا يوجد نظام صلاحيات مفعّل بالمشروع بعد
    }

    public function rules(): array
    {
        return [
            'inventory_file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
            'sales_file'     => ['nullable', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'inventory_file.required' => 'يرجى إرفاق ملف جرد المستودع المركزي.',
            'inventory_file.mimes'    => 'صيغة ملف الجرد غير مدعومة (xlsx, xls, csv فقط).',
            'inventory_file.max'      => 'حجم ملف الجرد يتجاوز الحد المسموح (10MB).',
            'sales_file.mimes'        => 'صيغة ملف المبيعات غير مدعومة (xlsx, xls, csv فقط).',
        ];
    }
}
