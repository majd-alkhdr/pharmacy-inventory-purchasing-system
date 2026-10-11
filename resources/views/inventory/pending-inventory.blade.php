@extends('layouts.admin')

@section('page_title', 'مواد جديدة بالجرد')
@section('page_subtitle', 'العمليات (المناقلات)')

@push('styles')
    <style>
        /* 🩹 [2026-09] كانت خلفية صفراء فاتحة ثابتة — بينما العنوان
           (.text-dark) والنص (.text-secondary) بداخلها يتبدّلان حسب الثيم
           ويصبحان فاتحين بالوضع الداكن، فيختفيان فوق هذه الخلفية الفاتحة
           الثابتة. */
        .alert-card {
            background-color: var(--c-surface-hover);
            border: 1px solid rgba(245, 166, 35, .35);
            border-radius: 10px;
        }

        .info-box {
            background-color: var(--c-primary-soft);
            border: 1px solid #bae6fd;
            border-radius: 10px;
        }
    </style>
@endpush

@section('content')
    <div class="surface-card p-4 shadow-sm" style="max-width: 640px; margin: 0 auto;">

        <div class="alert-card p-3 mb-3 d-flex align-items-start gap-3">
            <i class="fa-solid fa-triangle-exclamation text-warning fa-2x mt-1"></i>
            <div>
                <h5 class="fw-bold text-dark mb-1">تم العثور على {{ $count }} مادة جديدة بملف الجرد</h5>
                <p class="text-secondary small mb-0">
                    هذه المواد غير مسجلة في النظام مسبقاً (باركود جديد). سيقوم النظام بإنشائها تلقائياً كمنتجات
                    جديدة لمتابعة المعالجة.
                </p>
            </div>
        </div>

        @if ($hasSales)
            <div class="info-box p-3 mb-4">
                <h6 class="fw-bold small mb-2" style="color: var(--c-primary-dark);">
                    <i class="fa-solid fa-circle-info me-1"></i> تم رصد ملف مبيعات مرفق — بعد التأكيد سيتم تنفيذ ما
                    يلي:
                </h6>
                <ol class="text-secondary small mb-0 ps-3">
                    <li class="mb-1">تحديث وترميم النواقص القديمة المعلقة بناءً على الجرد الجديد.</li>
                    <li>معالجة مبيعات اليوم الحالية وإصدار مسودات الفواتير الأساسية للصيدليات.</li>
                </ol>
            </div>
        @else
            <div class="info-box p-3 mb-4">
                <h6 class="fw-bold small mb-0" style="color: var(--c-primary-dark);">
                    <i class="fa-solid fa-box-open me-1"></i> لم يتم رفع ملف مبيعات — بعد التأكيد سيقتصر النظام على
                    ترميم وتحديث سجل النواقص القديمة فقط.
                </h6>
            </div>
        @endif

        <form action="{{ route('inventory.pending-inventory.create-all') }}" method="POST">
            @csrf
            <input type="hidden" name="session_id" value="{{ $sessionId }}">
            <button type="submit" class="btn btn-brand btn-lg w-100 rounded-2 fs-6 fw-medium shadow-sm">
                <i class="fa-solid fa-square-check me-1"></i> إنشاء جميع المواد الجديدة ({{ $count }})
                ومتابعة العملية
            </button>
        </form>

    </div>
@endsection
