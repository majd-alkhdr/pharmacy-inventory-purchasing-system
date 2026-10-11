<?php
// app/Http/Requests/Purchasing/GeneratePurchaseOrdersRequest.php
namespace App\Http\Requests\Purchasing;

use App\Enums\PurchaseGenerationMode;
use App\Enums\PurchaseScenario;
use App\Enums\ShortageSource;
use App\Enums\SupplierScopeMode;
use App\Services\Purchasing\GeneratePurchaseOrdersParams;
use App\Services\Purchasing\SupplierScope\SupplierScopeStrategyResolver;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * 🆕 (ج.1) نطاق الموردين صار استراتيجيتين (supplier_scope_mode) وكلتاهما تدعم أكثر من مورد
 * (supplier_ids[]). supplier_id المفرد القديم يبقى مقبولاً (مُجمَّد) — نموذج قديم مخزَّن بالمتصفح
 * أو مسار "حل التعارض" الذي يعيد إرسال المدخلات المحفوظة يظل يعمل.
 *
 * التحقق الدلالي (هل الموردون يحملون الشركة؟) مسؤولية الاستراتيجية نفسها، لا قواعد هنا — يُترجم
 * InvalidArgumentException منها لخطأ تحقق عادي على الحقل (withValidator) فيُعرض بجانبه.
 */
class GeneratePurchaseOrdersRequest extends FormRequest
{
    private ?\App\Services\Purchasing\SupplierScope\SupplierScope $resolvedScope = null;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'forecast_period_days'    => ['required', 'integer', 'min:1', 'max:365'],
            'generation_mode'          => ['required', Rule::in(array_column(PurchaseGenerationMode::cases(), 'value'))],
            'shortage_source'          => ['nullable', Rule::in(array_column(ShortageSource::cases(), 'value'))],
            'supplier_scope_mode'      => ['nullable', Rule::enum(SupplierScopeMode::class)],
            'supplier_ids'             => ['nullable', 'array'],
            'supplier_ids.*'           => ['integer', 'exists:suppliers,id'],
            'company'                  => ['nullable', 'string', 'max:255', Rule::requiredIf(fn () => $this->input('supplier_scope_mode') === SupplierScopeMode::ByCompany->value)],
            'supplier_id'              => ['nullable', 'integer', 'exists:suppliers,id'], // 🧊 قديم
            'manual_budget_override'  => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'forecast_period_days.required' => 'يرجى تحديد مدة التنبؤ بالأيام.',
            'forecast_period_days.min'       => 'مدة التنبؤ يجب أن تكون يوماً واحداً على الأقل.',
            'generation_mode.required'        => 'يرجى تحديد نمط توليد الفواتير.',
            'supplier_id.exists'              => 'المورد المختار غير موجود.',
            'supplier_ids.*.exists'           => 'أحد الموردين المختارين غير موجود.',
            'company.required'                => 'يرجى اختيار الشركة.',
            'manual_budget_override.numeric'  => 'سقف الميزانية اليدوي يجب أن يكون رقماً.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            try {
                $this->resolvedScope = app(SupplierScopeStrategyResolver::class)
                    ->resolve($this->scopeMode())
                    ->resolve($this->selectedSupplierIds(), $this->input('company'));
            } catch (InvalidArgumentException $e) {
                $validator->errors()->add('supplier_ids', $e->getMessage());
            }
        });
    }

    public function scopeMode(): SupplierScopeMode
    {
        return SupplierScopeMode::tryFrom((string) $this->input('supplier_scope_mode')) ?? SupplierScopeMode::DirectSuppliers;
    }

    /** @return list<int> */
    private function selectedSupplierIds(): array
    {
        $ids = array_map('intval', (array) $this->input('supplier_ids', []));

        // 🧊 النموذج القديم بمورد مفرد.
        if ($ids === [] && $this->filled('supplier_id')) {
            $ids = [(int) $this->input('supplier_id')];
        }

        return array_values(array_unique($ids));
    }

    public function toParams(): GeneratePurchaseOrdersParams
    {
        $data = $this->validated();

        return new GeneratePurchaseOrdersParams(
            forecastPeriodDays: (int) $data['forecast_period_days'],
            scenario: PurchaseScenario::PriorityBased,
            generationMode: PurchaseGenerationMode::from($data['generation_mode']),
            shortageSourceFilter: isset($data['shortage_source']) ? ShortageSource::from($data['shortage_source']) : null,
            supplierId: isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
            manualBudgetOverride: isset($data['manual_budget_override']) ? (float) $data['manual_budget_override'] : null,
            supplierOverrides: session('purchase_supplier_overrides', []),
            supplierScope: $this->resolvedScope,
        );
    }
}
