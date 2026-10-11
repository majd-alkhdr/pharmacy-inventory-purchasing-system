<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 🎯 (ب.1) بحث المخزون القياسي: مادة × صيدلية. كلاهما اختياري — مادة وحدها = قيمها بكل
 * الصيدليات، صيدلية وحدها = كل موادها، والاثنان = زوج واحد بالضبط.
 */
class StandardStockLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id'  => ['nullable', 'integer', 'exists:products,id'],
            'pharmacy_id' => ['nullable', 'integer', 'exists:pharmacies,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.exists'  => 'المادة المختارة غير موجودة.',
            'pharmacy_id.exists' => 'الصيدلية المختارة غير موجودة.',
        ];
    }

    public function productId(): ?int
    {
        return $this->filled('product_id') ? (int) $this->validated('product_id') : null;
    }

    public function pharmacyId(): ?int
    {
        return $this->filled('pharmacy_id') ? (int) $this->validated('pharmacy_id') : null;
    }

    public function hasCriteria(): bool
    {
        return $this->productId() !== null || $this->pharmacyId() !== null;
    }
}
