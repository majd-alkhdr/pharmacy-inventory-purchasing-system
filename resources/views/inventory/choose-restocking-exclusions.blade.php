@extends('layouts.admin')

@section('page_title', 'استثناء شركات من الترميم')
@section('page_subtitle', 'العمليات (المناقلات) — قبل بدء المعالجة')

@push('styles')
    <style>
        /* 🩹 [2026-09] كانت background:#fff ثابتة — النص الافتراضي بداخل
           الـ<span> (غير المحدَّد بعد) يرث لون النص العام للصفحة، والذي
           يصبح فاتحاً بالوضع الداكن فيختفي فوق هذه الخلفية البيضاء. */
        .company-check-item {
            display: flex;
            align-items: center;
            gap: 10px;
            border: 1px solid var(--c-border);
            border-radius: 8px;
            padding: 10px 14px;
            margin-bottom: 6px;
            background: var(--c-surface);
            transition: all .12s ease;
            cursor: pointer;
        }

        .company-check-item:hover {
            border-color: var(--c-primary);
            background: var(--c-primary-soft);
        }

        .company-check-item input:checked~span {
            font-weight: 700;
            color: #b45309;
        }

        .company-check-item.is-checked {
            background: #fffbeb;
            border-color: #f59e0b;
        }
    </style>
@endpush

@section('content')
    <div class="surface-card p-4 shadow-sm" style="max-width: 760px; margin: 0 auto;">

        <div class="mb-4">
            <h5 class="fw-bold text-dark mb-1"><i class="fa-solid fa-filter text-primary me-1"></i> استثناء شركات من ترميم
                النواقص</h5>
            <p class="text-muted small mb-0">
                هذه الشركات مستخرَجة من ملف الجرد الذي رفعته الآن. حدِّد أي شركة تريد <strong>استثناءها من الترميم التلقائي
                    بهذه الدفعة تحديداً</strong> (مثل الإكسسوار) — رصيدها سيُحدَّث بالمخزون بشكل طبيعي، لكن أي نقص معلَّق
                لمنتجاتها **سيبقى معلَّقاً كما هو**، ولن يُنشئ فاتورة ترميم تلقائية هذه المرة.
            </p>
        </div>

        <form action="{{ route('inventory.confirm-restocking-exclusions') }}" method="POST" id="exclusionForm">
            @csrf
            <input type="hidden" name="session_id" value="{{ $sessionId }}">

            <div class="mb-3">
                @foreach ($companies as $company)
                    <label class="company-check-item"
                        onclick="this.classList.toggle('is-checked', event.target.checked || this.querySelector('input').checked)">
                        <input type="checkbox" name="excluded_companies[]" value="{{ $company }}"
                            class="form-check-input m-0"
                            onclick="this.closest('.company-check-item').classList.toggle('is-checked', this.checked)">
                        <span>{{ $company }}</span>
                    </label>
                @endforeach
            </div>

            <div class="alert alert-light border small text-secondary">
                <i class="fa-solid fa-circle-info text-primary me-1"></i>
                لا تريد استثناء أي شركة؟ اترك كل الخانات فارغة واضغط "متابعة المعالجة" مباشرة — السلوك سيكون كالمعتاد
                تماماً.
            </div>

            <button type="submit" class="btn btn-brand w-100 py-2 fw-medium rounded-2">
                <i class="fa-solid fa-arrow-left me-1"></i> متابعة المعالجة بالاختيار الحالي
            </button>
        </form>
    </div>
@endsection
