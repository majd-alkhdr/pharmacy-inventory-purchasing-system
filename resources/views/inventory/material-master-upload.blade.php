@extends('layouts.admin')

@section('page_title', 'بيانات المواد')
@section('page_subtitle', 'العمليات (المناقلات) — تحديث بيانات وصفية للمواد الموجودة')

@section('content')

    @if (session('status'))
        <div class="alert alert-light border small mb-3">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger small mb-3">{{ session('error') }}</div>
    @endif

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="border rounded-2 shadow-sm bg-white p-3">
                <h6 class="fw-bold mb-3"><i class="fa-solid fa-tags text-primary me-1"></i> رفع ملف بيانات المواد</h6>
                <form method="POST" action="{{ route('inventory.material-master.upload') }}" enctype="multipart/form-data">
                    @csrf
                    <x-file-upload-zone name="material_master_file" label="ملف بيانات المواد" :required="true"
                        accept=".xlsx,.csv"
                        hint="الترتيب الإجباري: رمز المادة ← اسم المادة ← الشركة ← السعر ← حجم العبوة ← الحد الأدنى للفرق. الأعمدة كلها عدا الرمز اختيارية — الفراغ أو «-» يعني «لا تغيير»، وليس صفراً. يحدّث مواداً مسجَّلة مسبقاً فقط، ولا يُنشئ مواداً جديدة."/>
                    <button type="submit" class="btn btn-brand w-100 mt-3 fw-medium">
                        <i class="fa-solid fa-upload me-1"></i> رفع وتحديث بيانات المواد
                    </button>
                </form>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="border rounded-2 shadow-sm bg-white p-3 h-100">
                <h6 class="fw-bold mb-3"><i class="fa-solid fa-circle-info text-primary me-1"></i> ماذا يفعل هذا الملف؟</h6>
                <ul class="small text-muted mb-0 ps-3">
                    <li class="mb-2">يُحدِّث اسم المادة، شركتها، حجم عبوتها، والحد الأدنى للفرق قبل الترميم (افتراضي هذه
                        المادة لكل الصيدليات — يبقى بإمكانك تجاوزه لصيدلية محدَّدة من شاشة «المخزون القياسي للصيدليات»).</li>
                    <li class="mb-2">تغيير السعر يُسجَّل بتاريخ الأسعار تلقائياً (لا يُستبدَل السعر القديم، يُضاف سطر جديد
                        بتاريخ اليوم — نفس آلية ملف الجرد المركزي).</li>
                    <li class="mb-2">لا علاقة له بكميات المخزون أو دفعة الترميم اليومية — ذلك من شاشة «كشف المستودعات»
                        المنفصلة تماماً.</li>
                    <li class="mb-0">رمز مادة غير مسجَّل بالنظام يظهر برسالة النتيجة كمعلومة فقط، ولا يُنشئ شيئاً.</li>
                </ul>
            </div>
        </div>
    </div>

@endsection
