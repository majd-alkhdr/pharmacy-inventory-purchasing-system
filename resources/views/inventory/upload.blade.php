@extends('layouts.admin')

@section('page_title', 'رفع المبيعات والجرد اليومي')
@section('page_subtitle', 'العمليات (المناقلات)')

@push('styles')
    <style>
        /* 🎯 CSS الخاص بـ .file-upload-wrapper و .upload-zone انتقل بالكامل
           داخل مكوّن file-upload-zone نفسه، فلم يعد داعياً لتكراره هنا. */

        .scenario-card {
            border: 1px solid var(--c-border);
            background-color: #fff;
            border-radius: 8px;
            height: 100%;
        }

        .scenario-icon {
            color: var(--c-primary);
            background-color: var(--c-primary-soft);
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
        }
    </style>
@endpush

@section('content')
    <div class="surface-card p-4 shadow-sm" style="max-width: 760px; margin: 0 auto;">

        <form action="{{ route('inventory.upload') }}" method="POST" enctype="multipart/form-data"
            data-loading-message="جاري معالجة ملفات الجرد والمبيعات ومطابقتها، قد يستغرق هذا حتى دقيقتين...">
            @csrf

            <x-file-upload-zone
                name="inventory_file"
                label="1️⃣ ملف جرد المستودع المركزي"
                :required="true"
                accept=".xlsx,.xls,.csv"
                hint="الترتيب الإجباري لأعمدة الملف: رقم المادة ← اسم الشركة ← اسم المادة ← الكمية" />

            {{-- 2️⃣ ملف المبيعات: اختياري --}}
            <x-file-upload-zone
                name="sales_file"
                label="2️⃣ ملف مبيعات الصيدليات اليومي"
                :required="false"
                accept=".xlsx,.xls,.csv"
                hint="اتركه فارغاً لترميم النواقص القديمة المسجلة فقط. ترتيب الأعمدة في حال رفعه: رقم المادة ← اسم الشركة ← اسم المادة ← اسم الصيدلية ← الكمية" />

            {{-- توضيح آلية عمل النظام (السيناريوهات) --}}
            <h6 class="fw-bold text-dark mb-2 small"><i class="fa-solid fa-gears text-secondary me-1"></i> مسار
                معالجة البيانات الذكي:</h6>
            <div class="row mb-4 g-2">
                <div class="col-sm-6">
                    <div class="card scenario-card p-2.5 shadow-sm">
                        <div class="d-flex align-items-center gap-2 mb-1.5">
                            <div class="scenario-icon"><i class="fa-solid fa-warehouse fa-xs"></i></div>
                            <span class="fw-bold text-dark small">السيناريو الأول: جرد فقط</span>
                        </div>
                        <p class="text-muted small mb-0 lh-sm" style="font-size: 0.75rem;">
                            يقوم النظام بسد وترميم خانات النواقص القديمة المعلقة بناءً على الكميات المتوفرة بالجرد
                            الجديد، مع إصدار فواتير إلحاقية للصيدليات فوراً.
                        </p>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="card scenario-card p-2.5 shadow-sm">
                        <div class="d-flex align-items-center gap-2 mb-1.5">
                            <div class="scenario-icon"><i class="fa-solid fa-receipt fa-xs"></i></div>
                            <span class="fw-bold text-dark small">السيناريو الثاني: جرد ومبيعات</span>
                        </div>
                        <p class="text-muted small mb-0 lh-sm" style="font-size: 0.75rem;">
                            يرمم النظام العجز القديم أولاً، ثم ينتقل تلقائياً لمعالجة مبيعات اليوم الجديدة لإنشاء
                            مسودات الفواتير الأساسية وتحديث سجل النواقص.
                        </p>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-brand w-100 rounded-2 py-2 fw-medium shadow-sm">
                <i class="fa-solid fa-rocket me-1"></i> بدء معالجة ومطابقة البيانات
            </button>
        </form>
    </div>
@endsection
