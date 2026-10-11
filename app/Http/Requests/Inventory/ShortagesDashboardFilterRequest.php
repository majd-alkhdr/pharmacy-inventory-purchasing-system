<?php

namespace App\Http\Requests\Inventory;

use App\Enums\ShortageSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShortagesDashboardFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status'      => ['nullable', 'in:pending,fulfilled'],
            'pharmacy_id' => ['nullable', 'integer', 'exists:pharmacies,id'],
            'source'      => ['nullable', Rule::in(array_column(ShortageSource::cases(), 'value'))], // 🆕
            'date_from'   => ['nullable', 'date'],
            'date_to'     => ['nullable', 'date', 'after_or_equal:date_from'],
        ];
    }

    public function filters(): array
    {
        return array_filter($this->validated(), fn($v) => $v !== null && $v !== '');
    }
}
