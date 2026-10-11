@extends('layouts.admin')

@section('page_title', 'المخزون القياسي للصيدليات')
@section('page_subtitle', 'العمليات (المناقلات) — تقييد مخزون نقاط البيع')

@section('content')
    <div class="surface-card p-4 shadow-sm" style="max-width: 700px; margin: 0 auto;">

        <div class="mb-4">
            <h6 class="fw-bold text-dark mb-1"><i class="fa-solid fa-gauge-high text-primary me-1"></i> استيراد المخزون
                القياسي الثابت لكل صيدلية-مادة</h6>
            <p class="text-muted small mb-0">
                هذا الرقم يمثّل "الكمية المستهدَفة" لكل مادة بكل صيدلية — عند الترميم (توليد فواتير إلحاقية للنواقص
                المعلَّقة)، يتوقف النظام تلقائياً بمجرد أن يصل رصيد الصيدلية لهذه القيمة بالضبط، حتى لو كان النقص
                المعلَّق أو الرصيد المركزي المتوفر أكبر من ذلك. الملف مستقل ويبقى محفوظاً حتى تعديله بملف جديد.
            </p>
        </div>

        {{-- 🆕 (أ.1) "هل نجح آخر رفع؟" — من سجل الرفع الموحَّد --}}
        <div class="alert {{ ($lastUpload ?? null) ? 'alert-success' : 'alert-warning' }} small mb-3 d-flex justify-content-between flex-wrap gap-2">
            <span>
                <i class="fa-solid fa-clock-rotate-left me-1"></i>
                @if ($lastUpload ?? null)
                    آخر رفع ناجح: <strong>{{ $lastUpload->displayTime()->format('Y-m-d H:i') }}</strong>
                    — {{ $lastUpload->original_filename }} ({{ $lastUpload->user?->name ?? 'غير معروف' }})
                    @if ($lastUpload->hasWarnings()) — ⚠️ {{ $lastUpload->rows_warned }} تحذير @endif
                @else
                    لا يوجد رفع ناجح مسجَّل بعد.
                @endif
            </span>
            <a href="{{ route('inventory.standard-stock.lookup') }}" class="small">عرض القيم الحالية ←</a>
        </div>

        <div class="alert alert-light border small text-secondary mb-4">
            <i class="fa-solid fa-circle-info text-primary me-1"></i>
            <strong>مثال توضيحي:</strong> لو رصيد صيدلية معيّنة الآن 5 وحدات، والمخزون القياسي المحدَّد لها 20، ونقصها
            المعلَّق 50 وحدة — سيُرمَّم لها **15 وحدة فقط** (لتصل بالضبط لـ20)، ويبقى الباقي (35 وحدة) معلَّقاً حتى ترتفع
            حاجتها الفعلية لاحقاً.
        </div>

        <div class="mb-4">
            <h6 class="fw-bold text-dark mb-2 small"><i class="fa-solid fa-table-list text-secondary me-1"></i> التنسيق
                المطلوب لأعمدة الملف</h6>
            <div class="border rounded-2 overflow-hidden shadow-sm">
                <table class="table table-bordered table-hover align-middle mb-0 text-center small">
                    <thead>
                        <tr>
                            <th>اسم الصيدلية</th>
                            <th>رقم المادة</th>
                            <th>اسم المادة</th>
                            <th>المخزون القياسي</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="text-muted">
                            <td>صيدلية هناء</td>
                            <td>2365</td>
                            <td>يونادول 500 ملغ</td>
                            <td class="fw-bold text-dark">20</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="form-text small mt-1">⚠️ ضع <code>-</code> بالخانة الأخيرة لإلغاء أي قيمة قياسية سابقة لهذا الزوج
                تحديداً (بلا قيد إطلاقاً بعدها).</div>
        </div>

        <form action="{{ route('inventory.pharmacy-minimum-stock.upload') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <x-file-upload-zone name="min_stock_file" label="ملف المخزون القياسي" :required="true" accept=".xlsx,.xls,.csv"
                hint="حتى 20MB" />
            <button type="submit" class="btn btn-brand w-100 py-2 fw-medium rounded-2 mt-2">
                <i class="fa-solid fa-upload me-1"></i> رفع ومعالجة الملف
            </button>
        </form>
    </div>
@endsection
