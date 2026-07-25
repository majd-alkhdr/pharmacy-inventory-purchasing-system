@extends('layouts.admin')

@section('page_title', 'استيراد أسعار موردين تاريخية')
@section('page_subtitle', 'المشتريات — تهيئة أولية')

@section('content')
<div class="surface-card p-4 shadow-sm" style="max-width: 700px; margin: 0 auto;">

    <div class="mb-4">
        <h6 class="fw-bold text-dark mb-1"><i class="fa-solid fa-clock-rotate-left text-primary me-1"></i> استيراد بيانات موردين وأسعار تاريخية دفعة واحدة</h6>
        <p class="text-muted small mb-0">
            بدل إدخال كل مورد ومادة يدوياً من شاشة "الموردون والأسعار"، ارفع ملف إكسل واحد يحوي
            سجل مشترياتك السابقة — سيُنشئ النظام الموردين الجدد تلقائياً، ويربط كل مادة معروفة بسعرها.
        </p>
    </div>

    <div class="mb-4">
        <h6 class="fw-bold text-dark mb-2 small"><i class="fa-solid fa-table-list text-secondary me-1"></i> التنسيق المطلوب لأعمدة الملف</h6>
        <div class="border rounded-2 overflow-hidden shadow-sm">
            <table class="table table-bordered table-hover align-middle mb-0 text-center small">
                <thead><tr><th>اسم المورد</th><th>الباركود</th><th>اسم المادة</th><th>الشركة</th><th>سعر الوحدة</th></tr></thead>
                <tbody>
                    <tr class="text-muted">
                        <td>شركة الدواء الحديثة</td><td>2365</td><td>يونادول 500 ملغ</td><td>يونيفارما</td><td class="fw-bold text-dark">4.25</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="form-text small mt-1">
            ⚠️ الباركود يجب أن يطابق باركود مادة **موجودة أصلاً** بالنظام — الملف لا يُنشئ مواد جديدة، فقط يربط الموجود بسعره من كل مورد.
        </div>
    </div>

    <form action="{{ route('purchasing.historical-import.upload') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <x-file-upload-zone name="prices_file" label="ملف الأسعار التاريخية" :required="true" accept=".xlsx,.xls,.csv" hint="حتى 20MB" />
        <button type="submit" class="btn btn-brand w-100 py-2 fw-medium rounded-2 mt-2">
            <i class="fa-solid fa-upload me-1"></i> رفع ومعالجة الملف
        </button>
    </form>
</div>
@endsection
