<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseOrderItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items'                    => ['required', 'array', 'min:1'],
            // 🆕 (ج.2) numeric لا integer: التدوير يحدث مرة واحدة بالخدمة (QuantityRounder، أ.5) — لا هنا.
            'items.*.approved_qty'   => ['required', 'numeric', 'min:0', 'max:1000000'],
            'remove_items'             => ['nullable', 'array'],
            'remove_items.*'           => ['integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.*.approved_qty.required' => 'يرجى إدخال الكمية المعتمَدة لكل صنف.',
            'items.*.approved_qty.min'       => 'الكمية لا يمكن أن تكون سالبة.',
        ];
    }
}
