<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 🎯 ملف "بيانات المواد" — الطلب رقم 2 المتفق عليه: منفصل تماماً عن كشف
 * المستودعات (upload.blade.php)، يُرفع مرة ويُحدَّث عند الحاجة، لا علاقة له
 * بدفعة الترميم اليومية. xlsx وcsv فقط (لا xls — راجع OpenSpoutTabularFileReader).
 */
final class UploadMaterialMasterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'material_master_file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'material_master_file.required' => 'يرجى إرفاق ملف بيانات المواد.',
            'material_master_file.mimes' => 'صيغة الملف غير مدعومة (xlsx أو csv فقط — احفظ ملفات xls بصيغة xlsx).',
            'material_master_file.max' => 'حجم الملف يتجاوز الحد المسموح (10MB).',
        ];
    }
}
