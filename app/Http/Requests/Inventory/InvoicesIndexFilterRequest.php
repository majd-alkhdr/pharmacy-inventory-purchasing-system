<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class InvoicesIndexFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date'         => ['nullable', 'date'],
            'availability' => ['nullable', 'in:available,shortage'],
            'status'       => ['nullable', 'in:processed,supplementary_invoice'],
            'pharmacy_id'  => ['nullable', 'integer', 'exists:pharmacies,id'],
        ];
    }

    /**
     * 🎯 نُقل من InventoryController::invoicesIndex():
     *     $date = $request->filled('date') ? $request->date : now()->toDateString();
     * الافتراضي (تاريخ اليوم) أصبح جزءاً من "شكل الطلب المتحقق منه" بدل أن
     * يكون منطقاً متناثراً بمنتصف الكونترولر.
     */
    public function filters(): array
    {
        $data = $this->validated();
        $data['date'] = $data['date'] ?? Carbon::now()->toDateString();

        return array_filter($data, fn($v) => $v !== null && $v !== '');
    }
}
