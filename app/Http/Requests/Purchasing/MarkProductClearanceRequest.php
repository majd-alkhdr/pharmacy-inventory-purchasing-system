<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

class MarkProductClearanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id'        => ['required', 'integer', 'exists:products,id'],
            'clearance_until'  => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.required'          => 'يرجى اختيار المادة.',
            'clearance_until.after_or_equal' => 'تاريخ الانتهاء يجب أن يكون اليوم أو بعده.',
        ];
    }
}
