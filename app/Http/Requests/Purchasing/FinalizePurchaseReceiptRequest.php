<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

class FinalizePurchaseReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'session_id'        => ['required', 'string'],
            'mappings'           => ['nullable', 'array'],
            'mappings.*'         => ['nullable', 'string'], // معرّف purchase_order_item أو 'skip'
            'order_is_finalized' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['order_is_finalized' => $this->boolean('order_is_finalized')]);
    }
}
