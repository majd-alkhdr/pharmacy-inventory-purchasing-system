<?php

declare(strict_types=1);

namespace App\Services\Purchasing\SupplierScope;

use App\Models\Supplier;
use App\Models\SupplierCompany;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * 🎯 (ج.1) الاستراتيجية الجديدة: اختيار **شركة** أولاً ← النظام يقترح الموردين الذين يحملونها
 * (suggestFor) ← المستخدم يختار واحداً أو أكثر منهم. النطاق الناتج: الموردون المختارون، والمواد
 * محصورة بتلك الشركة (المستخدم طلب "فواتير لهذه الشركة"، لا لكل ما يحمله المورد).
 *
 * ⚖️ لو اختار مورِّدَين لنفس الشركة: كل مادة من الشركة تحتاج مورداً واحداً — فيظهر تعارض
 * يُحَل بشاشة حل التعارض القائمة (قرار صاحب المشروع الأصلي: التعارض يُحسم يدوياً). لم نُعد
 * اختراع آلية تقسيم: نفس مسار SupplierSelectionService بعد حصره بالموردين المختارين.
 */
final class CompanyDerivedSupplierScopeStrategy implements SupplierScopeStrategy
{
    public function resolve(array $selectedSupplierIds, ?string $company): SupplierScope
    {
        $company = trim((string) $company);
        if ($company === '') {
            throw new InvalidArgumentException('يرجى اختيار الشركة أولاً.');
        }

        $ids = array_values(array_unique(array_map('intval', $selectedSupplierIds)));
        if ($ids === []) {
            throw new InvalidArgumentException('اختر مورداً واحداً على الأقل من الموردين المقترحين لهذه الشركة.');
        }

        $carriers = $this->suggestFor($company)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (array_diff($ids, $carriers) !== []) {
            throw new InvalidArgumentException("بعض الموردين المختارين لا يحملون شركة «{$company}».");
        }

        return new SupplierScope($ids, $company);
    }

    /**
     * الموردون المفعَّلون الذين يحملون الشركة — مصدر واحد للاقتراح بالواجهة وللتحقق هنا.
     *
     * @return Collection<int, Supplier>
     */
    public function suggestFor(string $company): Collection
    {
        return SupplierCompany::suppliersForCompany($company)
            ->filter(fn (Supplier $s) => $s->is_active)
            ->sortBy('name')
            ->values();
    }
}
