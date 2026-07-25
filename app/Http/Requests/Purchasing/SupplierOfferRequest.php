<?php

namespace App\Http\Requests\Purchasing;

use App\Enums\BonusType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupplierOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'min_quantity' => ['required', 'integer', 'min:1'],
            'bonus_type'    => ['required', Rule::in(array_column(BonusType::cases(), 'value'))],
            'bonus_value'  => ['required', 'numeric', 'min:0'],
            'starts_at'     => ['nullable', 'date'],
            'ends_at'       => ['nullable', 'date', 'after_or_equal:starts_at'],
            'notes'         => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'min_quantity.required' => 'يرجى تحديد الحد الأدنى من الكمية لتفعيل هذا العرض.',
            'bonus_type.required'    => 'يرجى اختيار نوع العرض.',
            'bonus_value.required'  => 'يرجى إدخال قيمة العرض (كمية أو نسبة).',
            'ends_at.after_or_equal' => 'تاريخ الانتهاء يجب أن يكون بعد أو يساوي تاريخ البداية.',
        ];
    }
}
