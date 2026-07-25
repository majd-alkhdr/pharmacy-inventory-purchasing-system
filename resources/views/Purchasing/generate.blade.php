@extends('layouts.admin')

@section('page_title', 'توليد فواتير شراء مقترحة')
@section('page_subtitle', 'المشتريات — محرك التنبؤ')

@push('styles')
<style>
    .scenario-choice {
        border: 2px solid var(--c-border);
        border-radius: 10px;
        padding: 16px;
        cursor: pointer;
        transition: all .15s ease;
        height: 100%;
    }
    .scenario-choice:hover {
        border-color: var(--c-primary);
        background-color: var(--c-primary-soft);
    }
    .scenario-choice.is-selected {
        border-color: var(--c-primary);
        background-color: var(--c-primary-soft);
        box-shadow: 0 0 0 .15rem rgba(2, 132, 199, .12);
    }
    .scenario-choice input[type="radio"] { display: none; }
    .scenario-icon-badge {
        width: 34px; height: 34px; border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        background: var(--c-primary-soft); color: var(--c-primary);
        margin-bottom: 8px;
    }
</style>
@endpush

@section('content')
<div class="surface-card p-4 shadow-sm" style="max-width: 820px; margin: 0 auto;">

    <div class="mb-4">
        <h5 class="fw-bold text-dark mb-1"><i class="fa-solid fa-wand-magic-sparkles text-primary me-1"></i> توليد فواتير شراء مقترحة تلقائياً</h5>
        <p class="text-muted small mb-0">
            سيقوم النظام بفحص كل المواد المؤهَّلة (باستثناء الراكدة)، حساب احتياج كل واحدة، واقتراح
            أفضل مورد وكمية حسب السيناريو المختار — مع شرح مبسّط لسبب كل اقتراح.
        </p>
    </div>

    <form action="{{ route('purchasing.orders.store') }}" method="POST">
        @csrf

        {{-- مدة التنبؤ --}}
        <div class="mb-4">
            <label class="form-label fw-bold text-dark small">مدة التنبؤ (بالأيام)</label>
            <input type="number" name="forecast_period_days" min="1" max="365"
                value="{{ old('forecast_period_days', 90) }}"
                class="form-control @error('forecast_period_days') is-invalid @enderror" style="max-width: 200px;">
            @error('forecast_period_days')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
            <div class="form-text small">مثال: 90 يوماً = يغطي النظام احتياج 3 أشهر قادمة تقريباً.</div>
        </div>

        {{-- اختيار السيناريو --}}
        <div class="mb-4">
            <label class="form-label fw-bold text-dark small d-block mb-2">سيناريو التوليد</label>
            <div class="row g-2">
                @foreach ($scenarios as $index => $scenario)
                    <div class="col-md-4">
                        <label class="scenario-choice d-block {{ old('scenario', 'balanced') === $scenario->value ? 'is-selected' : '' }}">
                            <input type="radio" name="scenario" value="{{ $scenario->value }}"
                                {{ old('scenario', 'balanced') === $scenario->value ? 'checked' : '' }}
                                onchange="document.querySelectorAll('.scenario-choice').forEach(el => el.classList.remove('is-selected')); this.closest('.scenario-choice').classList.add('is-selected');">
                            <div class="scenario-icon-badge">
                                <i class="fa-solid {{ ['fa-bolt', 'fa-chart-line', 'fa-scale-balanced'][$index] ?? 'fa-circle' }}"></i>
                            </div>
                            <div class="fw-bold text-dark small mb-1">{{ $scenario->label() }}</div>
                            <div class="text-muted" style="font-size: 0.75rem; line-height: 1.4;">{{ $scenario->description() }}</div>
                        </label>
                    </div>
                @endforeach
            </div>
            @error('scenario')
                <div class="text-danger small mt-1">{{ $message }}</div>
            @enderror
        </div>

        <div class="row g-3 mb-4">
            {{-- نطاق التوليد: مورد محدد أو الكل --}}
            <div class="col-md-6">
                <label class="form-label fw-bold text-dark small">نطاق التوليد</label>
                <select name="supplier_id" class="form-select @error('supplier_id') is-invalid @enderror">
                    <option value="">كل الموردين معاً</option>
                    @foreach ($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected(old('supplier_id') == $supplier->id)>{{ $supplier->name }}</option>
                    @endforeach
                </select>
                @error('supplier_id')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            {{-- تجاوز يدوي للميزانية --}}
            <div class="col-md-6">
                <label class="form-label fw-bold text-dark small">سقف ميزانية يدوي (اختياري)</label>
                <input type="number" step="0.01" min="0" name="manual_budget_override"
                    value="{{ old('manual_budget_override') }}"
                    class="form-control @error('manual_budget_override') is-invalid @enderror"
                    placeholder="اتركه فارغاً لاستخدام الميزانية الشهرية العامة">
                @error('manual_budget_override')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <div class="form-text small">لو تُرك فارغاً وكانت الميزانية الشهرية العامة مفعَّلة بإعدادات النظام، ستُطبَّق تلقائياً.</div>
            </div>
        </div>

        <button type="submit" class="btn btn-brand w-100 py-2 fw-medium rounded-2 shadow-sm">
            <i class="fa-solid fa-gears me-1"></i> فحص الاحتياج وتوليد الفواتير المقترحة
        </button>
    </form>
</div>
@endsection
