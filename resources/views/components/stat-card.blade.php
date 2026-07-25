{{--
    🎯 نُقلت من shortages.blade.php حيث كانت نفس بنية الـ card تتكرر 3 مرات
    (عدد المواد، إجمالي القطع، الصيدليات المتأثرة) بفرق فقط بالنص والقيمة.
    الاستخدام:
    <x-stat-card icon="fa-capsules" label="عدد المواد الناقصة الفريدة" :value="$stats['unique_products']" unit="صنف" />
--}}
@props(['icon', 'label', 'value', 'unit' => null])

<div class="card stat-card p-2.5 d-flex flex-row align-items-center justify-content-between shadow-sm h-100">
    <div>
        <span class="small text-muted d-block mb-1" style="font-size: 0.8rem;">{{ $label }}</span>
        <h4 class="fw-bold mb-0 text-dark">
            {{ $value }}
            @if ($unit)
                <span class="fs-6 fw-normal text-muted">{{ $unit }}</span>
            @endif
        </h4>
    </div>
    <i class="fa-solid {{ $icon }} fa-lg stat-icon"></i>
</div>
