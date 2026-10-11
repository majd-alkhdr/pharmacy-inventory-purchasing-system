<?php

namespace App\Http\Requests\Inventory;

use App\Models\DiscrepancyReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** 🎯 (ب.3) اختيار سبب فرق مطابقة من القائمة المرجعية (الفارغ = إزالة السبب). */
class SetDiscrepancyReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'discrepancy_reason_id' => ['nullable', 'integer', Rule::exists('discrepancy_reasons', 'id')->where('is_active', true)],
        ];
    }

    public function messages(): array
    {
        return ['discrepancy_reason_id.exists' => 'السبب المختار غير موجود أو غير مفعَّل.'];
    }

    public function reason(): ?DiscrepancyReason
    {
        return $this->filled('discrepancy_reason_id') ? DiscrepancyReason::find($this->validated('discrepancy_reason_id')) : null;
    }
}
