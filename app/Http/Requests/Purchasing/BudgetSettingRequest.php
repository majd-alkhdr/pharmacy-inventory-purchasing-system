<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

class BudgetSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'effective_month'    => ['required', 'date'],
            'monthly_cap_amount' => ['nullable', 'numeric', 'min:0'],
            'is_enabled'          => ['nullable', 'boolean'],
            'notes'               => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'monthly_cap_amount.numeric' => 'سقف الميزانية يجب أن يكون رقماً.',
            'monthly_cap_amount.min'      => 'سقف الميزانية لا يمكن أن يكون سالباً.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_enabled' => $this->boolean('is_enabled')]);
    }
}
