<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupplierCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_name' => [
                'required', 'string', 'max:255',
                Rule::unique('supplier_companies')->where('supplier_id', $this->route('supplier')?->id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'company_name.required' => 'يرجى إدخال اسم الشركة.',
            'company_name.unique'    => 'هذه الشركة مضافة مسبقاً لهذا المورد.',
        ];
    }
}
