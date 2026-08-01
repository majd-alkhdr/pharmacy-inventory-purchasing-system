<?php

namespace App\Http\Requests\Purchasing;

use App\Enums\PurchaseScenario;
use App\Services\Purchasing\GeneratePurchaseOrdersParams;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 🆕 بعد إعادة هيكلة المشتريات: لم يعد المشرف يختار سيناريو التوليد
 * إطلاقاً — يُفرَض دائماً PurchaseScenario::PriorityBased تلقائياً
 * بـ toParams(). حقل 'scenario' أُزيل بالكامل من قواعد التحقق.
 *
 * supplier_overrides يُقرأ مباشرة من Session (وليس من مدخلات الفورم
 * العادية) — يُمرَّر هنا فقط تمريراً (Pass-through) لبناء الـ DTO.
 */
class GeneratePurchaseOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'forecast_period_days'    => ['required', 'integer', 'min:1', 'max:365'],
            'supplier_id'              => ['nullable', 'integer', 'exists:suppliers,id'],
            'manual_budget_override'  => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'forecast_period_days.required' => 'يرجى تحديد مدة التنبؤ بالأيام.',
            'forecast_period_days.min'       => 'مدة التنبؤ يجب أن تكون يوماً واحداً على الأقل.',
            'supplier_id.exists'              => 'المورد المختار غير موجود.',
            'manual_budget_override.numeric'  => 'سقف الميزانية اليدوي يجب أن يكون رقماً.',
        ];
    }

    public function toParams(): GeneratePurchaseOrdersParams
    {
        $data = $this->validated();

        return new GeneratePurchaseOrdersParams(
            forecastPeriodDays: (int) $data['forecast_period_days'],
            scenario: PurchaseScenario::PriorityBased,
            supplierId: isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
            manualBudgetOverride: isset($data['manual_budget_override']) ? (float) $data['manual_budget_override'] : null,
            supplierOverrides: session('purchase_supplier_overrides', []),
        );
    }
}
