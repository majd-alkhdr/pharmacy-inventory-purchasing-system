<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 🎯 يجمّع منطق json_decode() الذي كان مكرراً حرفياً (سطراً بسطر) في كل من
 * InventoryController::saveMapping() و InventoryController::saveShortagesMapping():
 *
 *     $pharmacies = json_decode($request->input('pharmacies_json', '[]'), true) ?? [];
 *     $products   = json_decode($request->input('products_json', '[]'), true) ?? [];
 *
 * الفائدة الحقيقية: لو غداً بدك تتحقق من شكل كل عنصر بالمصفوفة (مثلاً
 * التأكد أن كل عنصر فيه mapped_id)، تعدّل بمكان واحد بدل مكانين.
 */
abstract class AbstractMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'session_id'      => ['required', 'string'],
            'pharmacies_json' => ['nullable', 'string'],
            'products_json'   => ['nullable', 'string'],
        ];
    }

    public function sessionId(): string
    {
        return $this->validated('session_id');
    }

    public function mappedPharmacies(): array
    {
        return json_decode($this->input('pharmacies_json', '[]'), true) ?? [];
    }

    public function mappedProducts(): array
    {
        return json_decode($this->input('products_json', '[]'), true) ?? [];
    }

    public function hasAnyMappingData(): bool
    {
        return !empty($this->mappedPharmacies()) || !empty($this->mappedProducts());
    }
}
