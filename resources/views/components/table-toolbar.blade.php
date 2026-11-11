{{--
    🎯 شريط بحث وفلترة موحّد لجداول المواد (المنطق كله بـ public/js/table-tools.js، بالمتصفح بلا طلبات):
    <x-table-toolbar table="po-items" :filters="[
        ['key' => 'priority', 'label' => 'الأولوية', 'options' => ['market_shortage' => 'قنص نواقص سوق', ...]],
    ]" />
    يرتبط بالجدول <table data-tt-table="po-items">. القيمة الفارغة بكل قائمة = الكل.
--}}
@props(['table', 'filters' => [], 'placeholder' => 'بحث بالاسم أو الشركة أو الباركود...'])

<div {{ $attributes->merge(['class' => 'tt-toolbar d-flex flex-wrap align-items-center gap-2 small no-print']) }} data-tt-toolbar="{{ $table }}">
    <input type="search" dir="auto" class="form-control form-control-sm tt-search" data-tt-search-input
        placeholder="{{ $placeholder }}" aria-label="بحث بالجدول" autocomplete="off">
    @foreach ($filters as $filter)
        <select class="form-select form-select-sm tt-filter" data-tt-filter="{{ $filter['key'] }}" aria-label="{{ $filter['label'] }}">
            <option value="">{{ $filter['label'] }}: الكل</option>
            @foreach ($filter['options'] as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    @endforeach
    <span class="text-muted text-nowrap" data-tt-count aria-live="polite"></span>
    <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" data-tt-clear hidden>
        <i class="fa-solid fa-xmark fa-xs"></i> مسح الكل
    </button>
</div>

@once
    @push('styles')
        <style>
            .tt-toolbar { margin-bottom: .5rem; }
            .tt-toolbar .tt-search { width: 260px; max-width: 100%; }
            .tt-toolbar .tt-filter { width: auto; max-width: 220px; }
            .tt-toolbar [data-tt-clear] { color: var(--c-primary); }
            /* hidden يغلب أي display صريح (مثل .why-panel.is-open) — بالشاشة والطباعة */
            [data-tt-table] [hidden] { display: none !important; }
            th[data-tt-sort] { cursor: pointer; user-select: none; white-space: normal; }
            th[data-tt-sort]:hover, th[data-tt-sort]:focus-visible { color: var(--c-primary); }
            th[data-tt-sort]::after { content: '⇅'; margin-inline-start: .25rem; font-size: .7em; color: var(--c-text-faint); }
            th[data-tt-sort][aria-sort=ascending]::after { content: '▲'; color: var(--c-primary); }
            th[data-tt-sort][aria-sort=descending]::after { content: '▼'; color: var(--c-primary); }
            @media print { th[data-tt-sort]::after { content: none !important; } }
        </style>
    @endpush
    @push('scripts')
        <script src="{{ asset('js/table-tools.js') }}?v={{ filemtime(public_path('js/table-tools.js')) }}" defer></script>
    @endpush
@endonce
