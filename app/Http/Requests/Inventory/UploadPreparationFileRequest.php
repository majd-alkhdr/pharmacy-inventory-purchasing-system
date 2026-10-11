<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 🎯 (ب.3) ملف التحضير الفعلي — إكسل فقط (لا صور: OCR استُبعد لضعف الدقة).
 * xlsx/csv فقط لأن القارئ المتدفق (OpenSpout) لا يقرأ xls الثنائي القديم؛ txt لأن Laravel
 * قد يخمّن نوع CSV كنص — نفس قواعد كشف المستودعات بالضبط.
 */
class UploadPreparationFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preparation_file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'preparation_file.required' => 'يرجى اختيار ملف التحضير.',
            'preparation_file.mimes'    => 'صيغة الملف غير مدعومة (xlsx أو csv فقط).',
            'preparation_file.max'      => 'حجم الملف يتجاوز الحد المسموح (10MB).',
        ];
    }
}
