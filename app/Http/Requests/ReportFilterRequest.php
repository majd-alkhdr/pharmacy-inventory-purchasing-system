<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * 🩹 فلاتر التقارير (فترة + صيدلية اختيارية) — مشتركة بين تقارير المناقلات والمشتريات.
 *
 * قبلها كانت التواريخ تُمرَّر للاستعلام كما هي: "من" بعد "إلى" ⇒ تقرير فارغ صامت يبدو كأن لا بيانات،
 * ونص غير تاريخ ⇒ مقارنة نصية بلا معنى. هنا تُرفض صراحة.
 *
 * عند الفشل: إعادة توجيه لنفس التقرير **بلا فلاتر** مع رسالة `error` (الشريط الذي يعرضه التخطيط فعلاً
 * بكل الصفحات) — بدل الرجوع الافتراضي لـ Laravel، إذ لا تعرض هذه الشاشات حقيبة $errors.
 */
class ReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // الصلاحية على مستوى المسار (module:*_reports)
    }

    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'pharmacy_id' => ['nullable', 'integer', 'exists:pharmacies,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'date_from.date_format' => 'تاريخ "من" غير صالح.',
            'date_to.date_format' => 'تاريخ "إلى" غير صالح.',
            'date_to.after_or_equal' => 'تاريخ "إلى" يجب أن يكون في نفس يوم "من" أو بعده — عُرض التقرير بلا فلاتر.',
            'pharmacy_id.exists' => 'الصيدلية المختارة غير موجودة.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            redirect()->to($this->url())->with('error', $validator->errors()->first())
        );
    }

    public function dateFrom(?string $default = null): ?string
    {
        return $this->validated('date_from') ?? $default;
    }

    public function dateTo(?string $default = null): ?string
    {
        return $this->validated('date_to') ?? $default;
    }

    public function pharmacyId(): ?int
    {
        $id = $this->validated('pharmacy_id');

        return $id === null ? null : (int) $id;
    }
}
