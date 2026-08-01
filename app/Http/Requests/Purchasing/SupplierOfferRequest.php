<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SupplierOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'min_quantity'      => ['required', 'integer', 'min:1'],
            'bonus_quantity'    => ['nullable', 'integer', 'min:0'],
            'discount_percent'  => ['nullable', 'numeric', 'min:0', 'max:100'],
            'starts_at'          => ['nullable', 'date'],
            'ends_at'             => ['nullable', 'date', 'after_or_equal:starts_at'],
            'notes'               => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'min_quantity.required'  => 'يرجى تحديد الحد الأدنى من الكمية لتفعيل هذا العرض.',
            'discount_percent.max'   => 'نسبة الخصم لا يمكن أن تتجاوز 100%.',
            'ends_at.after_or_equal' => 'تاريخ الانتهاء يجب أن يكون بعد أو يساوي تاريخ البداية.',
        ];
    }

    /** 🎯 عرض بلا بونص وبلا خصم لا معنى له فعلياً — نمنعه هنا بدل السماح بإدخال عديم الفائدة */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $bonus = (int) ($this->input('bonus_quantity') ?? 0);
            $discount = (float) ($this->input('discount_percent') ?? 0);

            if ($bonus <= 0 && $discount <= 0) {
                $validator->errors()->add('bonus_quantity', 'يجب تحديد بونص أو نسبة خصم (أو كليهما) — لا يمكن حفظ عرض بلا أي فائدة.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'bonus_quantity'   => $this->input('bonus_quantity') ?: 0,
            'discount_percent' => $this->input('discount_percent') ?: 0,
        ]);
    }
}
