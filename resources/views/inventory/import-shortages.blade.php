@extends('layouts.admin')

@section('page_title', 'استيراد نواقص تاريخية')
@section('page_subtitle', 'العمليات (المناقلات)')

@push('styles')
    <style>
        .info-box {
            background-color: var(--c-bg);
            border: 1px solid var(--c-border);
            border-radius: 10px;
        }
        /* 🎯 CSS الخاص بـ .upload-zone انتقل بالكامل داخل مكوّن file-upload-zone
           نفسه (resources/views/components/file-upload-zone.blade.php)
           فلم يعد داعياً لتكراره هنا. */
    </style>
@endpush

@section('topbar_actions')
    <a href="{{ route('inventory.shortages.dashboard') }}" class="btn btn-outline-secondary btn-sm rounded-2 px-3">
        <i class="fa-solid fa-arrow-left me-1"></i> العودة للنواقص
    </a>
@endsection

@section('content')
    <div class="surface-card p-4 shadow-sm" style="max-width: 850px; margin: 0 auto;">

        {{-- معلومات هامة قبل الرفع --}}
        <div class="info-box p-3 mb-4">
            <h6 class="fw-bold text-dark mb-2">
                <i class="fa-solid fa-circle-info text-primary me-1"></i> ملاحظات هامة قبل الرفع:
            </h6>
            <ul class="text-secondary small mb-0 ps-3">
                <li class="mb-1">هذا الملف **اختياري** ويُستخدم لتغذية النظام بنواقص سابقة وتسهيل عملية الشراء
                    الحالية.</li>
                <li class="mb-1">إذا لم تكن الصيدلية أو المادة مسجلة، ستُنقل تلقائياً إلى **شاشة المطابقة الذكية**
                    لتوجيهها.</li>
                <li>التاريخ اختياري؛ في حال تركه فارغاً، سيتم اعتماد **تاريخ اليوم** تلقائياً.</li>
            </ul>
        </div>

        {{-- التنسيق المطلوب لأعمدة الملف --}}
        <div class="mb-4">
            <h6 class="fw-bold text-dark mb-2">
                <i class="fa-solid fa-table-list text-secondary me-1"></i> التنسيق المطلوب لأعمدة الملف
            </h6>
            <div class="border rounded-2 overflow-hidden shadow-sm">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle mb-0 text-center small">
                        <thead>
                            <tr>
                                <th>اسم الصيدلية</th>
                                <th>رقم المادة (الباركود)</th>
                                <th>اسم المادة</th>
                                <th>الشركة</th>
                                <th>الكمية</th>
                                <th>التاريخ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="text-muted">
                                <td>صيدلية هناء</td>
                                <td>2365</td>
                                <td>يونادول 500 ملغ</td>
                                <td>يونيفارما</td>
                                <td class="fw-bold text-dark">50</td>
                                <td>2026-06-01</td>
                            </tr>
                            <tr class="text-muted">
                                <td>صيدلية سهاد</td>
                                <td>2758</td>
                                <td>ديكلون 100 ملغ</td>
                                <td>ابن حيان</td>
                                <td class="fw-bold text-dark">30</td>
                                <td>2026-06-15</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- منطقة رفع الملف --}}
        <form action="{{ route('inventory.shortages.import') }}" method="POST" enctype="multipart/form-data"
            data-loading-message="جاري قراءة ومطابقة ملف النواقص، هذا قد يستغرق دقيقة أو أكثر حسب حجم الملف...">
            @csrf

            <x-file-upload-zone
                name="shortages_file"
                label="ملف النواقص التاريخية"
                :required="true"
                accept=".xlsx,.xls,.csv"
                hint="يدعم صيغ فقط: xlsx, xls, csv (الحد الأقصى 10MB)" />

            <div class="d-grid">
                <button type="submit" class="btn btn-brand rounded-2 py-2 fw-medium">
                    <i class="fa-solid fa-upload me-1"></i> بدء معالجة واستيراد الملف
                </button>
            </div>
        </form>

    </div>
@endsection
