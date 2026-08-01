@extends('layouts.admin')

@section('page_title', 'نتيجة استيراد الربط')
@section('page_subtitle', 'المشتريات — تصنيف المنتجات')

@section('content')
<div class="surface-card p-4 shadow-sm" style="max-width: 700px; margin: 0 auto;">

    <div class="text-center mb-4">
        <i class="fa-solid fa-circle-check text-success" style="font-size: 2.5rem;"></i>
        <h5 class="fw-bold text-dark mt-2 mb-0">اكتمل استيراد الربط</h5>
    </div>

    <div class="row g-2 mb-4">
        <div class="col-4">
            <div class="p-3 rounded-2 text-center" style="background: var(--c-primary-soft);">
                <h4 class="fw-bold text-primary mb-0">{{ $result['linked'] }}</h4>
                <span class="small text-secondary">رابطة تصنيف تمت مباشرة</span>
            </div>
        </div>
        <div class="col-4">
            <div class="p-3 rounded-2 text-center" style="background: #fffbeb;">
                <h4 class="fw-bold mb-0" style="color:#b45309;">{{ $result['pending_links'] }}</h4>
                <span class="small text-secondary">بانتظار مطابقة يدوية</span>
            </div>
        </div>
        <div class="col-4">
            <div class="p-3 rounded-2 text-center" style="background: #fef2f2;">
                <h4 class="fw-bold text-danger mb-0">{{ $result['unmatched_products']->count() }}</h4>
                <span class="small text-secondary">باركود غير معروف بالنظام</span>
            </div>
        </div>
    </div>

    @if ($result['pending_links'] > 0)
        <div class="alert alert-warning small mb-4">
            <i class="fa-solid fa-triangle-exclamation me-1"></i>
            توجد {{ $result['pending_links'] }} منتج ورد لها تصنيف غير معروف بالقائمة الرسمية — بقيت بلا ربط مؤقتاً.
            <a href="{{ route('purchasing.categories.mapping', $sessionId) }}" class="fw-bold">اذهب لشاشة المطابقة اليدوية الآن &laquo;</a>
        </div>
    @endif

    @if ($result['unmatched_products']->isNotEmpty())
        <div class="mb-4">
            <h6 class="fw-bold text-dark small mb-2">صفوف باركودها غير معروف بالنظام (لا يمكن ربطها)</h6>
            <div class="border rounded-2 p-2 bg-light" style="max-height: 240px; overflow-y: auto;">
                <ul class="mb-0 small text-secondary">
                    @foreach ($result['unmatched_products'] as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <a href="{{ route('purchasing.categories.form') }}" class="btn btn-outline-secondary w-100 rounded-2">العودة لشاشة التصنيفات</a>
</div>
@endsection
