@extends('layouts.admin')

@section('page_title', 'إدارة التصنيفات (الاستطباب)')
@section('page_subtitle', 'المشتريات — تصنيف المنتجات')

@section('content')
<div class="row g-3" style="max-width: 900px; margin: 0 auto;">

    <div class="col-12">
        <div class="alert alert-light border small text-secondary shadow-sm mb-0">
            <i class="fa-solid fa-circle-info text-primary me-1"></i>
            التصنيف معلوماتي بحت (لأغراض التقارير والبحث والفلترة، مثل "كل مبيعات المسكنات") — <strong>لا يُستخدم إطلاقاً لأي مطابقة آلية بين الأدوية</strong>، فهذا قرار يبقى دائماً بيد صيدلي مختص عبر شاشة "البدائل".
            التصنيفات الحالية بالنظام: <strong>{{ $categoriesCount }}</strong> — الروابط المعلّقة بانتظار مطابقة يدوية: <strong>{{ $pendingLinksCount }}</strong>
            @if ($pendingLinksCount > 0)
                (راجعها من رابط "مطابقة الروابط المعلّقة" أسفل الجدول الأخير بنتيجة كل استيراد جديد لملف الربط)
            @endif
        </div>
    </div>

    {{-- ═══ الخطوة 1: قائمة التصنيفات الرئيسية ═══ --}}
    <div class="col-md-6">
        <div class="surface-card p-4 shadow-sm h-100">
            <h6 class="fw-bold text-dark mb-1"><i class="fa-solid fa-list-check text-primary me-1"></i> 1️⃣ قائمة التصنيفات الرئيسية</h6>
            <p class="text-muted small mb-3">عمود واحد فقط: اسم التصنيف. يجب رفع هذا الملف أولاً — أي تصنيف هنا يُنشأ رسمياً بالنظام.</p>
            <form action="{{ route('purchasing.categories.upload-master') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <x-file-upload-zone name="categories_file" label="ملف قائمة التصنيفات" :required="true" accept=".xlsx,.xls,.csv" hint="عمود واحد: اسم التصنيف" />
                <button type="submit" class="btn btn-brand w-100 py-2 fw-medium rounded-2 mt-2">
                    <i class="fa-solid fa-upload me-1"></i> رفع قائمة التصنيفات
                </button>
            </form>
        </div>
    </div>

    {{-- ═══ الخطوة 2: ربط المنتجات بالتصنيفات ═══ --}}
    <div class="col-md-6">
        <div class="surface-card p-4 shadow-sm h-100">
            <h6 class="fw-bold text-dark mb-1"><i class="fa-solid fa-link text-primary me-1"></i> 2️⃣ ربط المنتجات بالتصنيفات</h6>
            <p class="text-muted small mb-3">عمودان: باركود + اسم تصنيف (موجود مسبقاً بالخطوة 1). لربط منتج بعدة تصنيفات، كرِّر الباركود بسطر منفصل لكل تصنيف.</p>
            <form action="{{ route('purchasing.categories.upload-links') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <x-file-upload-zone name="links_file" label="ملف الربط" :required="true" accept=".xlsx,.xls,.csv" hint="باركود ← اسم التصنيف" />
                <button type="submit" class="btn btn-brand w-100 py-2 fw-medium rounded-2 mt-2">
                    <i class="fa-solid fa-upload me-1"></i> رفع ملف الربط
                </button>
            </form>
        </div>
    </div>
</div>
@endsection
