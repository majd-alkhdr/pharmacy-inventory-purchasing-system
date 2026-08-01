@extends('layouts.admin')

@section('page_title', 'استيراد أسعار البيع الرسمية')
@section('page_subtitle', 'المشتريات — الربحية')

@section('content')
<div class="surface-card p-4 shadow-sm" style="max-width: 700px; margin: 0 auto;">

    <div class="mb-4">
        <h6 class="fw-bold text-dark mb-1"><i class="fa-solid fa-tags text-primary me-1"></i> استيراد سعر البيع الرسمي (التعرفة) لكل مادة</h6>
        <p class="text-muted small mb-0">
            هذا السعر هو الأساس الذي تُحسَب عليه ربحية كل مادة (سعر البيع − التكلفة الصافية بعد بونص/خصم المورد).
            يُعتمَد تاريخ رفع هذا الملف تلقائياً كـ"تاريخ سريان" لكل الأسعار الواردة فيه — أي سعر سابق لنفس المادة يبقى محفوظاً تاريخياً ولا يُحذَف.
        </p>
    </div>

    <div class="mb-4">
        <h6 class="fw-bold text-dark mb-2 small"><i class="fa-solid fa-table-list text-secondary me-1"></i> التنسيق المطلوب لأعمدة الملف</h6>
        <div class="border rounded-2 overflow-hidden shadow-sm">
            <table class="table table-bordered table-hover align-middle mb-0 text-center small">
                <thead><tr><th>الباركود</th><th>سعر البيع</th></tr></thead>
                <tbody>
                    <tr class="text-muted"><td>2365</td><td class="fw-bold text-dark">5.75</td></tr>
                </tbody>
            </table>
        </div>
        <div class="form-text small mt-1">⚠️ الباركود يجب أن يطابق باركود مادة موجودة أصلاً بالنظام — الملف لا يُنشئ مواد جديدة.</div>
    </div>

    <form action="{{ route('purchasing.selling-prices.upload') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <x-file-upload-zone name="prices_file" label="ملف أسعار البيع" :required="true" accept=".xlsx,.xls,.csv" hint="حتى 20MB" />
        <button type="submit" class="btn btn-brand w-100 py-2 fw-medium rounded-2 mt-2">
            <i class="fa-solid fa-upload me-1"></i> رفع ومعالجة الملف
        </button>
    </form>
</div>
@endsection
