@extends('layouts.admin')

@section('page_title', 'إعدادات الميزانية الشهرية')
@section('page_subtitle', 'المشتريات — إعدادات النظام')

@section('content')
<div class="surface-card p-4 shadow-sm" style="max-width: 720px; margin: 0 auto;">

    <div class="mb-4">
        <h6 class="fw-bold text-dark mb-1"><i class="fa-solid fa-sliders text-primary me-1"></i> الحد الشهري العام للمشتريات</h6>
        <p class="text-muted small mb-0">
            هذا الحد يُطبَّق تلقائياً على أي توليد فواتير شراء لا يحدّد المشرف له سقفاً يدوياً خاصاً
            (راجع حقل "سقف ميزانية يدوي" بشاشة التوليد). عطّله تماماً لو أردت عدم تحديد أي سقف افتراضياً.
        </p>
    </div>

    <form action="{{ route('purchasing.budget-settings.update') }}" method="POST" class="p-3 rounded-2 mb-4" style="background: var(--c-bg); border: 1px solid var(--c-border);">
        @csrf
        <input type="hidden" name="effective_month" value="{{ \Illuminate\Support\Carbon::now()->startOfMonth()->toDateString() }}">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-bold text-dark mb-0">شهر {{ \Illuminate\Support\Carbon::now()->translatedFormat('F Y') }} (الحالي)</h6>
            <div class="form-check form-switch">
                <input type="checkbox" name="is_enabled" value="1" class="form-check-input" id="isEnabled" role="switch"
                    {{ old('is_enabled', $current->is_enabled) ? 'checked' : '' }}>
                <label class="form-check-label small fw-medium" for="isEnabled">مفعَّل</label>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label small fw-bold text-secondary mb-1">سقف الميزانية الشهرية</label>
            <input type="number" step="0.01" min="0" name="monthly_cap_amount"
                value="{{ old('monthly_cap_amount', $current->monthly_cap_amount) }}"
                class="form-control @error('monthly_cap_amount') is-invalid @enderror"
                placeholder="اتركه فارغاً = بلا سقف حتى لو فُعِّل الخيار أعلاه">
            @error('monthly_cap_amount') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <div class="mb-3">
            <label class="form-label small fw-bold text-secondary mb-1">ملاحظات (اختياري)</label>
            <textarea name="notes" rows="2" class="form-control">{{ old('notes', $current->notes) }}</textarea>
        </div>

        <button type="submit" class="btn btn-brand w-100 py-2 fw-medium rounded-2">
            <i class="fa-solid fa-check me-1"></i> حفظ إعدادات هذا الشهر
        </button>
    </form>

    {{-- ═══ سجل الأشهر السابقة (تاريخي، للاطلاع فقط) ═══ --}}
    @if ($history->isNotEmpty())
        <h6 class="fw-bold text-dark mb-2 small">سجل الأشهر السابقة</h6>
        <div class="border rounded-2 overflow-hidden shadow-sm bg-white">
            <table class="table table-sm mb-0 small">
                <thead>
                    <tr><th>الشهر</th><th class="text-center">الحالة</th><th class="text-end">السقف المحدَّد</th></tr>
                </thead>
                <tbody>
                    @foreach ($history as $month)
                        <tr>
                            <td>{{ $month->effective_month->translatedFormat('F Y') }}</td>
                            <td class="text-center">
                                @if ($month->is_enabled)
                                    <span class="badge bg-success-subtle text-success px-2 py-1 rounded-2">مفعَّل</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary px-2 py-1 rounded-2">معطَّل</span>
                                @endif
                            </td>
                            <td class="text-end fw-bold">{{ $month->monthly_cap_amount ? number_format($month->monthly_cap_amount, 2) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
