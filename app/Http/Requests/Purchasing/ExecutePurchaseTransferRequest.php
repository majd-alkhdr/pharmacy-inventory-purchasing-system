<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 🎯 (ج.3) تنفيذ مناقلة عكسية من لوحة "مناقلات؟". الكمية هنا عدد صحيح فعلاً (لا خام): هي
 * فائض فعّال محسوب سلفاً بـ SurplusEngine (مقرَّب لعبوة كاملة) — لا شيء يُدوَّر.
 */
class ExecutePurchaseTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pharmacy_id' => ['required', 'integer', 'exists:pharmacies,id'],
            'quantity'    => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'pharmacy_id.required' => 'اختر الصيدلية صاحبة الفائض.',
            'quantity.min'         => 'الكمية يجب أن تكون أكبر من صفر.',
        ];
    }
}
