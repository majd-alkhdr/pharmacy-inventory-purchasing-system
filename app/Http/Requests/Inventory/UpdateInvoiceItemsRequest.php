<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 🎯 (ب.2) تعديل فاتورة مناقلة: تغيير كميات، حذف بنود، إضافة بنود — بطلب واحد يُحفظ ذرّياً.
 *
 * ⚖️ 'numeric' لا 'integer' للكميات عمداً: قاعدة التدوير الموحَّدة (أ.5) تُطبَّق مرة واحدة
 * بالخدمة على القيمة الخام كما أدخلها المستخدم — رفضها هنا لأنها "ليست صحيحة" كان سيعني
 * أن الواجهة تدوّر بنفسها (خطوة تدوير ثانية بمكان آخر).
 */
class UpdateInvoiceItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items'                   => ['nullable', 'array'],
            'items.*.supplied_qty'    => ['required', 'numeric', 'min:0', 'max:1000000'],
            'remove_items'            => ['nullable', 'array'],
            'remove_items.*'          => ['integer'],
            'add_items'               => ['nullable', 'array'],
            'add_items.*.product_id'  => ['required_with:add_items.*.quantity', 'nullable', 'integer', 'exists:products,id'],
            'add_items.*.quantity'    => ['required_with:add_items.*.product_id', 'nullable', 'numeric', 'min:0', 'max:1000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.*.supplied_qty.required' => 'يرجى إدخال الكمية لكل صنف.',
            'items.*.supplied_qty.min'      => 'الكمية لا يمكن أن تكون سالبة.',
            'add_items.*.product_id.exists' => 'إحدى المواد المضافة غير موجودة.',
            'add_items.*.quantity.min'      => 'كمية المادة المضافة لا يمكن أن تكون سالبة.',
        ];
    }

    /** @return array<int, string|int|float> [item_id => الكمية الخام] */
    public function quantityChanges(): array
    {
        return collect($this->validated('items', []))
            ->mapWithKeys(fn (array $row, $itemId) => [(int) $itemId => $row['supplied_qty']])
            ->all();
    }

    /** @return list<int> */
    public function removals(): array
    {
        return array_map('intval', $this->validated('remove_items', []));
    }

    /** @return list<array{product_id: int, quantity: string|int|float}> صفوف الإضافة الفارغة تُتجاهل */
    public function additions(): array
    {
        return collect($this->validated('add_items', []))
            ->filter(fn (array $row) => !empty($row['product_id']) && ($row['quantity'] ?? '') !== '')
            ->map(fn (array $row) => ['product_id' => (int) $row['product_id'], 'quantity' => $row['quantity']])
            ->values()
            ->all();
    }
}
