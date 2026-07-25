<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

class ApprovePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 🩹 نص حر مؤقتاً بدل foreignId('approved_by_user_id') — نفس
            // الملاحظة الموثَّقة بمايجريشن purchase_orders: سيتغيّر هذا
            // عند تفعيل نظام صلاحيات المستخدمين مستقبلاً بالمشروع.
            'approved_by' => ['required', 'string', 'max:255'],
            'notes'       => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'approved_by.required' => 'يرجى إدخال اسم المعتمِد قبل تأكيد الاعتماد.',
        ];
    }
}
