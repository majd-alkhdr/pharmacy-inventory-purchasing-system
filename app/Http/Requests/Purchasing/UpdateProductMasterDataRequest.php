<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchasing;

use App\Enums\ProductMasterField;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 🎯 تصحيح يدوي لبيانات مادة. كل الحقول اختيارية: الفراغ = "لا تغيير" (نفس قاعدة ملف الأصناف).
 * حدود القيم مطابقة لما يقبله الرفع، مع استثناء واحد: حجم العبوة ≥ 1 هنا (عبوة بصفر وحدة لا معنى
 * لها كتصحيح مقصود). رصيد المركزي غير قابل للتعديل هنا إطلاقاً (مصدره ملف المصفوفة وحده).
 */
class UpdateProductMasterDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // الصلاحية على مستوى المسار: module:purchasing_operations
    }

    public function rules(): array
    {
        return [
            ProductMasterField::Name->value => ['nullable', 'string', 'max:255'],
            ProductMasterField::Company->value => ['nullable', 'string', 'max:255'],
            ProductMasterField::Price->value => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            ProductMasterField::PackSize->value => ['nullable', 'integer', 'min:1', 'max:1000000'],
            ProductMasterField::MinReorderGap->value => ['nullable', 'integer', 'min:0', 'max:1000000'],
            // للعرض فقط — أي محاولة إرسال (نموذج معدَّل يدوياً مثلاً) تُرفض صراحة بدل تجاهلها بصمت.
            ProductMasterField::CentralQuantity->value => ['prohibited'],
        ];
    }

    public function attributes(): array
    {
        return collect(ProductMasterField::cases())->mapWithKeys(fn (ProductMasterField $f) => [$f->value => $f->label()])->all();
    }

    /** @return array<string, string|int|float|null> */
    public function corrections(): array
    {
        return $this->safe()->only(array_map(fn (ProductMasterField $f) => $f->value, ProductMasterField::editable()));
    }
}
