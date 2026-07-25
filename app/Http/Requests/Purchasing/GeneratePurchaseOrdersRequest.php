<?php

namespace App\Http\Requests\Purchasing;

use App\Enums\PurchaseScenario;
use App\Services\Purchasing\GeneratePurchaseOrdersParams;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 🎯 يطبّق "مدخلات المشرف" الموصوفة بقسم 1 من مستندك: مدة التنبؤ، نطاق
 * الفاتورة (مورد محدد أو الكل)، السيناريو، وتحديد يدوي اختياري للميزانية.
 *
 * 🎓 لاحظ toParams(): بنفس فلسفة AbstractMappingRequest الموجودة أصلاً
 * بمشروعك (تقرير [002] بالمرحلة الأولى) — الكونترولر لا يحتاج معرفة
 * كيف يُبنى GeneratePurchaseOrdersParams من مصفوفة $request->validated()،
 * فقط يستدعي toParams() ويحصل على كائن جاهز ومُتحقَّق منه بالكامل.
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
            'scenario'                 => ['required', Rule::in(array_column(PurchaseScenario::cases(), 'value'))],
            'supplier_id'              => ['nullable', 'integer', 'exists:suppliers,id'],
            'manual_budget_override'  => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'forecast_period_days.required' => 'يرجى تحديد مدة التنبؤ بالأيام.',
            'forecast_period_days.min'       => 'مدة التنبؤ يجب أن تكون يوماً واحداً على الأقل.',
            'scenario.required'               => 'يرجى اختيار سيناريو التوليد.',
            'scenario.in'                      => 'السيناريو المختار غير معروف بالنظام.',
            'supplier_id.exists'              => 'المورد المختار غير موجود.',
            'manual_budget_override.numeric'  => 'سقف الميزانية اليدوي يجب أن يكون رقماً.',
        ];
    }

    public function toParams(): GeneratePurchaseOrdersParams
    {
        $data = $this->validated();

        return new GeneratePurchaseOrdersParams(
            forecastPeriodDays: (int) $data['forecast_period_days'],
            scenario: PurchaseScenario::from($data['scenario']),
            supplierId: isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
            manualBudgetOverride: isset($data['manual_budget_override']) ? (float) $data['manual_budget_override'] : null,
        );
    }
}
