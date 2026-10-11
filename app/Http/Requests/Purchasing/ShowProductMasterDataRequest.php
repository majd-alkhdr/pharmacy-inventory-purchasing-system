<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

class ShowProductMasterDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // الصلاحية على مستوى المسار: module:purchasing_operations
    }

    public function rules(): array
    {
        return [
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
        ];
    }

    public function productId(): ?int
    {
        return $this->validated('product_id') === null ? null : (int) $this->validated('product_id');
    }
}
