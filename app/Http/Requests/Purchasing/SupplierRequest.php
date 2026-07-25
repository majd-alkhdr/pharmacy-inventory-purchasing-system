<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // 🎯 supplier نفسه (route model binding) متاح هنا لتفادي تعارض
        // قاعدة unique مع السطر الحالي عند التعديل (Edit) - نفس النمط
        // الشائع بـ Laravel لتفادي "لا يمكن حفظ نفس الاسم" عند تعديل مورد قائم.
        $supplierId = $this->route('supplier')?->id;

        return [
            'name'            => ['required', 'string', 'max:255', Rule::unique('suppliers', 'name')->ignore($supplierId)],
            'contact_person'  => ['nullable', 'string', 'max:255'],
            'phone'           => ['nullable', 'string', 'max:50'],
            'email'           => ['nullable', 'email', 'max:255'],
            'lead_time_days'  => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes'           => ['nullable', 'string', 'max:2000'],
            'is_active'       => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'يرجى إدخال اسم المورد.',
            'name.unique'    => 'يوجد مورد آخر بنفس هذا الاسم مسبقاً.',
            'email.email'    => 'صيغة البريد الإلكتروني غير صحيحة.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // 🎯 checkbox HTML لا يُرسِل قيمة إطلاقاً عند عدم التحديد — نُطبّع
        // ذلك لقيمة false صريحة بدل ترك العمود بلا تحديث بصمت.
        $this->merge(['is_active' => $this->boolean('is_active', true)]);
    }
}
