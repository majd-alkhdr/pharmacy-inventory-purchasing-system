@extends('layouts.admin')

@section('page_title', 'الحد الأدنى للمخزون')
@section('page_subtitle', 'المشتريات')

@section('content')
<div class="surface-card p-4 shadow-sm" style="max-width: 700px; margin: 0 auto;">

    <div class="mb-4">
        <h6 class="fw-bold text-dark mb-1"><i class="fa-solid fa-gauge-high text-primary me-1"></i> استيراد الحد الأدنى لكل مادة</h6>
        <p class="text-muted small mb-0">
            يُعتمَد تاريخ رفع هذا الملف تلقائياً كمصدر الحقيقة الوحيد للحد
            الأدنى لكل مادة، ويبقى محفوظاً حتى تعديله برفع ملف جديد لاحقاً.
        </p>
    </div>

    <div class="mb-4">
        <h6 class="fw-bold text-dark mb-2 small"><i class="fa-solid fa-table-list text-secondary me-1"></i> التنسيق المطلوب لأعمدة الملف</h6>
        <div class="border rounded-2 overflow-hidden shadow-sm">
            <table class="table table-bordered table-hover align-middle mb-0 text-center small">
                <thead><tr><th>رمز المادة</th><th>اسم المادة</th><th>الحد الأدنى</th></tr></thead>
                <tbody>
                    <tr class="text-muted"><td>2365</td><td>يونادول 500 ملغ</td><td class="fw-bold text-dark">20</td></tr>
                </tbody>
            </table>
        </div>
        <div class="form-text small mt-1">⚠️ ضع <code>-</code> بخانة الحد الأدنى لإلغاء أي حد أدنى محفوظ سابقاً لهذه المادة.</div>
    </div>

    <form action="{{ route('purchasing.minimum-stock.upload') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <x-file-upload-zone name="min_stock_file" label="ملف الحد الأدنى" :required="true" accept=".xlsx,.xls,.csv" hint="حتى 10MB" />
        <button type="submit" class="btn btn-brand w-100 py-2 fw-medium rounded-2 mt-2">
            <i class="fa-solid fa-upload me-1"></i> رفع ومعالجة الملف
        </button>
    </form>
</div>
@endsection
