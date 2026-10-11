{{--
    🎨 إعادة تصميم بصري فقط — نفس الـ @props بلا أي تغيير:
    <x-stat-card icon="fa-capsules" label="عدد المواد الناقصة الفريدة" :value="$stats['unique_products']" unit="صنف" />
--}}
@props(['icon', 'label', 'value', 'unit' => null])

<div class="dt-stat-card tw-flex tw-flex-row tw-items-center tw-justify-between tw-p-4 tw-rounded-2xl tw-h-full">
    <div>
        <span class="tw-block tw-text-xs tw-text-[var(--c-text-muted)] tw-mb-1.5">{{ $label }}</span>
        <h4 class="tw-font-extrabold tw-text-2xl tw-mb-0 tw-text-[var(--c-text)]">
            {{ $value }}
            @if ($unit)
                <span class="tw-text-sm tw-font-normal tw-text-[var(--c-text-muted)]">{{ $unit }}</span>
            @endif
        </h4>
    </div>
    <div class="dt-stat-icon">
        <i class="fa-solid {{ $icon }}"></i>
    </div>
</div>

@once
    @push('styles')
        <style>
            .dt-stat-card {
                background-color: var(--c-surface);
                border: 1px solid var(--c-border);
                box-shadow: var(--shadow-card);
                transition: border-color .2s ease, transform .2s ease, background-color .2s ease;
            }

            .dt-stat-card:hover {
                border-color: rgba(255, 107, 0, .35);
                transform: translateY(-2px);
            }

            /* 🩹 دائرة مفرَّغة بحدّ نحاسي بدل مربّع ممتلئ — يطابق أسلوب
                       أيقونات التصميم المرجعي (خفيف/outline بدل filled). */
            .dt-stat-icon {
                width: 46px;
                height: 46px;
                border-radius: 999px;
                flex-shrink: 0;
                display: flex;
                align-items: center;
                justify-content: center;
                background: transparent;
                border: 1.5px solid var(--c-primary);
                color: var(--c-primary);
                font-size: 1.05rem;
            }
        </style>
    @endpush
@endonce
