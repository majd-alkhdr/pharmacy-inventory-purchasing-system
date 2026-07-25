@extends('layouts.admin')

@section('page_title', 'نتيجة الاستيراد')
@section('page_subtitle', 'المشتريات')

@section('content')
<div class="surface-card p-4 shadow-sm" style="max-width: 700px; margin: 0 auto;">

    <div class="text-center mb-4">
        <i class="fa-solid fa-circle-check text-success" style="font-size: 2.5rem;"></i>
        <h5 class="fw-bold text-dark mt-2 mb-0">اكتمل الاستيراد</h5>
    </div>

    <div class="row g-2 mb-4">
        <div class="col-4">
            <div class="p-3 rounded-2 text-center" style="background: var(--c-primary-soft);">
                <h4 class="fw-bold text-primary mb-0">{{ $result['matched'] }}</h4>
                <span class="small text-secondary">رابطة مورد-مادة</span>
            </div>
        </div>
        <div class="col-4">
            <div class="p-3 rounded-2 text-center" style="background: #f0fdf4;">
                <h4 class="fw-bold mb-0" style="color:#166534;">{{ $result['suppliers_created'] }}</h4>
                <span class="small text-secondary">مورد جديد أُنشئ</span>
            </div>
        </div>
        <div class="col-4">
            <div class="p-3 rounded-2 text-center" style="background: #fef2f2;">
                <h4 class="fw-bold text-danger mb-0">{{ $result['unmatched']->count() }}</h4>
                <span class="small text-secondary">صف غير مطابَق</span>
            </div>
        </div>
    </div>

    @if ($result['unmatched']->isNotEmpty())
        <div class="mb-4">
            <h6 class="fw-bold text-dark small mb-2">صفوف لم تُطابَق (باركود غير معروف بالنظام)</h6>
            <div class="border rounded-2 p-2 bg-light" style="max-height: 240px; overflow-y: auto;">
                <ul class="mb-0 small text-secondary">
                    @foreach ($result['unmatched'] as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            </div>
            <div class="form-text small mt-1">راجع هذه المواد يدوياً — إما تصحيح الباركود بالملف وإعادة الرفع، أو إضافتها أولاً كمنتجات من شاشات الجرد المعتادة.</div>
        </div>
    @endif

    <div class="d-flex gap-2">
        <a href="{{ route('purchasing.suppliers.index') }}" class="btn btn-brand w-100 rounded-2">عرض الموردين</a>
        <a href="{{ route('purchasing.historical-import.form') }}" class="btn btn-outline-secondary w-100 rounded-2">استيراد ملف آخر</a>
    </div>
</div>
@endsection
