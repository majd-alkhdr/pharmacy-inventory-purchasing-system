<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

class FinalizeManualReceivingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items'                    => ['required', 'array', 'min:1'],
            'items.*.status'          => ['required', 'in:full,partial,none'],
            'items.*.received_qty'   => ['nullable', 'integer', 'min:0'],
            'items.*.note'             => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'لا يمكن تأكيد استلام بلا أي أصناف.',
        ];
    }
}
