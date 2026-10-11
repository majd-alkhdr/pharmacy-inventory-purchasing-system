@extends('layouts.admin')

@section('page_title', 'جرد المستودع المركزي')
@section('page_subtitle', 'العمليات (المناقلات) — تحديث مستقل، تعريف مواد جديدة')

@section('content')

    <div class="surface-card p-4 shadow-sm" style="max-width: 720px; margin: 0 auto;">

        @if (session('status'))
            <div class="alert alert-light border small mb-3">{{ session('status') }}</div>
        @endif

        <div class="alert alert-light border small text-secondary mb-4">
            <i class="fa-solid fa-circle-info text-primary me-1"></i>
            ارفع ملف جرد المستودع المركزي هنا **وحده فقط** — بلا حاجة لأي ملف مبيعات أو جرد صيدلية مرفَق.
            يُحدِّث رصيد وسعر كل مادة معروفة بالنظام، وهذه الشاشة تحديداً هي الطريق الوحيد لتعريف مواد
            جديدة كلياً بالنظام لأول مرة — أي رمز غير معروف يُعرَض عليك للتأكيد قبل إنشائه كمنتج حقيقي.
        </div>

        <form action="{{ route('inventory.stock.upload') }}" method="POST" enctype="multipart/form-data"
            data-loading-message="جاري تحديث جرد المستودع المركزي...">
            @csrf

            <x-file-upload-zone name="inventory_file" label="ملف جرد المستودع المركزي" :required="true"
                accept=".xlsx,.xls,.csv"
                hint="الترتيب الإجباري: رقم المادة ← اسم الشركة ← اسم المادة ← الكمية ← [اختياري] حجم العبوة ← [اختياري] السعر" />

            <button type="submit" class="btn btn-brand w-100 rounded-2 py-2 fw-medium shadow-sm mt-3">
                <i class="fa-solid fa-rocket me-1"></i> بدء التحديث
            </button>
        </form>
    </div>

@endsection
