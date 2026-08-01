@extends('layouts.admin')

@section('page_title', 'توليد فواتير شراء مقترحة')
@section('page_subtitle', 'المشتريات — محرك التنبؤ حسب الأولوية والدوران')

@section('content')
<div class="surface-card p-4 shadow-sm" style="max-width: 820px; margin: 0 auto;">

    <div class="mb-4">
        <h5 class="fw-bold text-dark mb-1"><i class="fa-solid fa-wand-magic-sparkles text-primary me-1"></i> توليد فواتير شراء مقترحة تلقائياً</h5>
        <p class="text-muted small mb-0">
            سيقوم النظام بفحص كل المواد المؤهَّلة (باستثناء الراكدة ودوراناً بطيء الحركة)، حساب احتياج كل واحدة، واقتراح
            أفضل مورد حسب الشركة المفضّلة — وفق ترتيب الأولوية: تارجت ← نقص حرج ← دوران عالٍ ← عادي.
        </p>
    </div>

    @if ($lockedSupplierIds->isNotEmpty())
        <div class="alert alert-warning small mb-4">
            <i class="fa-solid fa-lock me-1"></i>
            الموردون التالون لديهم مسودة معلّقة بانتظار قرارك (اعتماد أو رفض)، ولن يُولَّد لهم أي فاتورة جديدة حتى يُبتّ بأمرها:
            <strong>{{ $suppliers->whereIn('id', $lockedSupplierIds)->pluck('name')->implode('، ') }}</strong>.
            راجع <a href="{{ route('purchasing.orders.index', ['status' => 'draft']) }}" class="fw-bold">المسودات المعلّقة</a> أولاً.
        </div>
    @endif

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

        {{-- 🆕 لا يوجد اختيار سيناريو بعد الآن — سيناريو الأولوية الواحد
             يُطبَّق دائماً تلقائياً (تارجت ← نقص حرج ← دوران عالٍ ← عادي). --}}

        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <label class="form-label fw-bold text-dark small">نطاق التوليد</label>
                <select name="supplier_id" class="form-select @error('supplier_id') is-invalid @enderror">
                    <option value="">كل الموردين معاً (سيُتخطّى المقفولون تلقائياً)</option>
                    @foreach ($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected(old('supplier_id') == $supplier->id) {{ $lockedSupplierIds->contains($supplier->id) ? 'disabled' : '' }}>
                            {{ $supplier->name }} {{ $lockedSupplierIds->contains($supplier->id) ? '— 🔒 لديه مسودة معلّقة' : '' }}
                        </option>
                    @endforeach
                </select>
                @error('supplier_id')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

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
