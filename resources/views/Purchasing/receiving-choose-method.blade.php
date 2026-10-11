@extends('layouts.admin')

@section('page_title', 'تسجيل استلام فعلي')
@section('page_subtitle', 'المشتريات — اختر طريقة التسجيل')

@push('styles')
<style>
    .method-card {
        border: 2px solid var(--c-border); border-radius: 12px; padding: 28px;
        text-align: center; height: 100%; transition: all .15s ease; display: block;
    }
    .method-card:hover { border-color: var(--c-primary); background: var(--c-primary-soft); }
    .method-icon { font-size: 2.2rem; color: var(--c-primary); margin-bottom: 12px; }
</style>
@endpush

@section('content')
<div class="surface-card p-4 shadow-sm" style="max-width: 800px; margin: 0 auto;">
    <div class="text-center mb-4">
        <h5 class="fw-bold text-dark mb-1">كيف تريد تسجيل الاستلام الفعلي؟</h5>
        <p class="text-muted small mb-0">كلا الأسلوبين يُحدِّثان نفس البيانات بنفس الدقة — اختر الأنسب لحجم عملية الاستلام هذه.</p>
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <a href="{{ route('purchasing.receiving.upload-form') }}" class="method-card">
                <div class="method-icon"><i class="fa-solid fa-file-excel"></i></div>
                <h6 class="fw-bold text-dark mb-2">رفع ملف Excel</h6>
                <p class="text-muted small mb-0">مناسب لعدد كبير من الأصناف — ارفع ملف الاستلام وسيُطابِق النظام الأصناف تلقائياً.</p>
            </a>
        </div>
        <div class="col-md-6">
            <a href="{{ route('purchasing.receiving.manual.select') }}" class="method-card">
                <div class="method-icon"><i class="fa-solid fa-list-check"></i></div>
                <h6 class="fw-bold text-dark mb-2">تدقيق يدوي من الجدول</h6>
                <p class="text-muted small mb-0">مناسب لعدد قليل من الأصناف — راجع كل صنف مباشرة وحدِّد حالته (كامل/جزئي/لم يصل) بضغطة واحدة.</p>
            </a>
        </div>
    </div>
</div>
@endsection
