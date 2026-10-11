@extends('layouts.admin')

@section('page_title', 'نتيجة استيراد الحد الأدنى للصيدليات')
@section('page_subtitle', 'العمليات (المناقلات)')

@section('content')
    <div class="surface-card p-4 shadow-sm" style="max-width: 700px; margin: 0 auto;">
        <div class="text-center mb-4">
            <i class="fa-solid fa-circle-check text-success" style="font-size: 2.5rem;"></i>
            <h5 class="fw-bold text-dark mt-2 mb-0">اكتمل الاستيراد</h5>
        </div>
        <div class="row g-2 mb-4">
            <div class="col-6">
                <div class="p-3 rounded-2 text-center" style="background: var(--c-primary-soft);">
                    <h4 class="fw-bold text-primary mb-0">{{ $result['matched'] }}</h4>
                    <span class="small text-secondary">زوج (صيدلية-مادة) تم تحديثه</span>
                </div>
            </div>
            <div class="col-6">
                <div class="p-3 rounded-2 text-center" style="background: #fef2f2;">
                    <h4 class="fw-bold text-danger mb-0">{{ $result['unmatched']->count() }}</h4>
                    <span class="small text-secondary">صف غير مطابَق (باركود غير معروف)</span>
                </div>
            </div>
        </div>
        {{-- 🆕 (أ.4) تحذيرات تطابق الأكواد بين الملفات — مرئية بنتيجة الرفع لا باللوج فقط --}}
        @if (isset($result['diagnostics']) && $result['diagnostics']->hasWarnings())
            <div class="alert alert-warning small mb-4">
                <div class="fw-bold mb-1"><i class="fa-solid fa-triangle-exclamation me-1"></i> تحذيرات هذا الرفع</div>
                @foreach ($result['diagnostics']->summaryLines() as $line)
                    <div>{{ $line }}</div>
                @endforeach
                <a href="{{ route('import-history.index', ['file_type' => 'pharmacy_minimum_stock']) }}" class="small">التفاصيل بسجل الرفع ←</a>
            </div>
        @endif

        @if ($result['unmatched']->isNotEmpty())
            <div class="mb-4">
                <h6 class="fw-bold text-dark small mb-2">صفوف لم تُطابَق</h6>
                <div class="border rounded-2 p-2 bg-light" style="max-height: 240px; overflow-y: auto;">
                    <ul class="mb-0 small text-secondary">
                        @foreach ($result['unmatched'] as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif
        <a href="{{ route('inventory.pharmacy-minimum-stock.form') }}" class="btn btn-outline-secondary w-100 rounded-2">رفع
            ملف آخر</a>
    </div>
@endsection
