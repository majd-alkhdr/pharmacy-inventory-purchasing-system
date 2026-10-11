{{-- resources/views/purchasing/manual-sales-rate-upload.blade.php --}}
@extends('layouts.admin')

@section('page_title', 'معدّل مبيعات المستودع')
@section('page_subtitle', 'المشتريات — بديل مؤقت عن التنبؤ بالفواتير التاريخية')

@section('content')
    <div class="surface-card p-4 shadow-sm" style="max-width: 760px; margin: 0 auto;">

        <div class="mb-4">
            <h6 class="fw-bold text-dark mb-1"><i class="fa-solid fa-chart-line text-primary me-1"></i> رفع ملف مبيعات يدوي
            </h6>
            <p class="text-muted small mb-0">
                يُستخدَم كمصدر بديل لمعدّل الاستهلاك اليومي لحين توفّر تاريخ فواتير كافٍ — يُستهلَك مباشرة بنمط "تحقيق
                المخزون القياسي للمستودع" بشاشة توليد فواتير الشراء. كل رفعة جديدة لنفس المادة **تستبدل** معدّلها المحفوظ
                سابقاً.
            </p>
        </div>

        <div class="mb-4">
            <h6 class="fw-bold text-dark mb-2 small"><i class="fa-solid fa-table-list text-secondary me-1"></i> التنسيق
                المطلوب لأعمدة الملف (بهذا الترتيب بالضبط)</h6>
            <div class="border rounded-2 overflow-hidden shadow-sm">
                <table class="table table-bordered table-hover align-middle mb-0 text-center small">
                    <thead>
                        <tr>
                            <th>اسم المادة</th>
                            <th>اسم الشركة</th>
                            <th>الكمية المباعة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="text-muted">
                            <td>سيتامول</td>
                            <td>ابن زهر</td>
                            <td class="fw-bold text-dark">1000</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="form-text small mt-1">
                ⚠️ المطابقة بالاسم + الشركة، لا الباركود — أي صف لا يُطابِق منتجاً موجوداً سيُعرَض عليك لاحقاً لربطه بمنتج
                موجود أو إنشاء منتج جديد.<br>
                ⚠️ تكرار نفس المادة بعدة أسطر بنفس الملف: الكميات تُجمَع تلقائياً.
            </div>
        </div>

        <form action="{{ route('purchasing.manual-sales-rate.upload') }}" method="POST" enctype="multipart/form-data"
            data-loading-message="جاري معالجة ملف المبيعات ومطابقته...">
            @csrf

            <div class="mb-3">
                <label class="form-label fw-bold text-dark small">المدة التي تمثّلها الكمية المرفوعة (بالأشهر)</label>
                <input type="number" name="period_months" min="1" value="{{ old('period_months') }}"
                    class="form-control @error('period_months') is-invalid @enderror" style="max-width: 200px;">
                @error('period_months')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <div class="form-text small">مثال: لو الكمية المرفوعة تمثّل مبيعات 5 أشهر، أدخل 5 — يحوّلها النظام داخلياً
                    إلى 150 يوماً.</div>
            </div>

            <x-file-upload-zone name="sales_file" label="ملف المبيعات" :required="true" accept=".xlsx,.xls,.csv"
                hint="حتى 20MB" />

            <button type="submit" class="btn btn-brand w-100 py-2 fw-medium rounded-2 mt-2">
                <i class="fa-solid fa-upload me-1"></i> رفع ومعالجة الملف
            </button>
        </form>
    </div>
@endsection
