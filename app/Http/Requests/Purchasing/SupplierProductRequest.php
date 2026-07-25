<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupplierProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id'   => [
                'required', 'integer', 'exists:products,id',
                // 🎯 يطبّق قيد unique(['supplier_id','product_id']) بالمايجريشن
                // على مستوى التحقق أيضاً — رسالة عربية واضحة بدل خطأ SQL خام.
                Rule::unique('supplier_products')->where('supplier_id', $this->route('supplier')?->id),
            ],
            'unit_cost'    => ['required', 'numeric', 'min:0'],
            'is_preferred' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.required' => 'يرجى اختيار المادة.',
            'product_id.unique'    => 'هذه المادة مرتبطة بهذا المورد مسبقاً.',
            'unit_cost.required'  => 'يرجى إدخال سعر الوحدة.',
            'unit_cost.min'        => 'السعر لا يمكن أن يكون سالباً.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_preferred' => $this->boolean('is_preferred')]);
    }
}
