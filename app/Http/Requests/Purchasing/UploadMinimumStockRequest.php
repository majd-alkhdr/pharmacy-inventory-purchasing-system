<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 🩹 نستخدم mimetypes: من البداية (وليس mimes:) — تعلَّمنا من مشكلة ملف
 * التصنيفات ذات العمود الواحد أن ملفات CSV البسيطة قد تُكتشَف كـ
 * text/plain بدل text/csv، فنتفادى تكرار نفس المشكلة هنا مسبقاً.
 */
class UploadMinimumStockRequest extends FormRequest
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
                'max:10240',
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
            'min_stock_file.required'  => 'يرجى اختيار ملف الحد الأدنى.',
            'min_stock_file.mimetypes' => 'صيغة الملف غير مدعومة — يرجى رفع ملف Excel (xlsx/xls) أو CSV صحيح.',
            'min_stock_file.max'        => 'حجم الملف يتجاوز الحد المسموح (10MB).',
        ];
    }
}
