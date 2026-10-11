<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

class UploadManualSalesRatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sales_file'    => ['required', 'file', 'mimes:xlsx,xls,csv'],
            'period_months' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'sales_file.required'    => 'يرجى اختيار ملف المبيعات.',
            'sales_file.mimes'       => 'صيغة الملف غير مدعومة — يُقبَل Excel أو CSV فقط.',
            'period_months.required' => 'يرجى تحديد عدد الأشهر التي تمثّلها الكمية المرفوعة.',
            'period_months.min'      => 'المدة يجب أن تكون شهراً واحداً على الأقل.',
        ];
    }

    /** 🎯 نقطة التحويل الوحيدة (أشهر × 30 = أيام) — لا مكان آخر بالنظام يكرّرها */
    public function periodDays(): int
    {
        return ((int) $this->validated('period_months')) * 30;
    }
}
