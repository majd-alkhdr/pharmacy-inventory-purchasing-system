@extends('layouts.admin')

@section('page_title', 'جرد المشتريات')
@section('page_subtitle', 'المشتريات — المصدر الوحيد للحقيقة')

@section('content')
<div class="surface-card p-4 shadow-sm" style="max-width: 760px; margin: 0 auto;">

    <div class="mb-4">
        <h6 class="fw-bold text-dark mb-1"><i class="fa-solid fa-warehouse text-primary me-1"></i> رفع ملف جرد المشتريات الموحّد</h6>
        <p class="text-muted small mb-0">
            هذا الملف هو المصدر الوحيد للحقيقة بالنسبة لرصيد المخزون، سعر البيع، الحد الأدنى، وعلم "تارجت" لكل مادة
            بقسم المشتريات. كل رفع جديد **يستبدل بالكامل** القيم السابقة الناتجة من رفع سابق لهذا الملف تحديداً
            (وليس من أي استلام فعلي — تلك بيانات منفصلة تماماً).
        </p>
    </div>

    <div class="mb-4">
        <h6 class="fw-bold text-dark mb-2 small"><i class="fa-solid fa-table-list text-secondary me-1"></i> التنسيق المطلوب لأعمدة الملف (بهذا الترتيب بالضبط)</h6>
        <div class="border rounded-2 overflow-hidden shadow-sm">
            <table class="table table-bordered table-hover align-middle mb-0 text-center small">
                <thead>
                    <tr>
                        <th>رمز المادة</th><th>اسم المادة</th><th>اسم الشركة</th><th>الكمية</th>
                        <th>التصنيف</th><th>سعر البيع</th><th>تاريخ الصلاحية</th><th>تاريخ الشراء</th>
                        <th>الحد الأدنى</th><th>صنف تارجت؟</th><th>آخر سعر شراء</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="text-muted">
                        <td>2365</td><td>يونادول 500 ملغ</td><td>يونيفارما</td><td class="fw-bold text-dark">120</td>
                        <td>—</td><td>5.75</td><td>2027-03-01</td><td>2026-07-15</td>
                        <td>20</td><td>نعم</td><td class="fw-bold text-dark">4.10</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="form-text small mt-1">
            ⚠️ عمود "التصنيف" مُتجاهَل تماماً من هذا الملف — التصنيفات تُدار حصراً من شاشة "التصنيفات (الاستطباب)" المنفصلة.<br>
            ⚠️ الرمز يجب أن يطابق باركود مادة موجودة أصلاً بالنظام — الملف لا يُنشئ مواد جديدة.<br>
            ⚠️ اترك خانة "الحد الأدنى" بقيمة <code>-</code> إن كنت لا تريد تحديد حد أدنى لهذه المادة.<br>
            ⚠️ عند تكرار نفس الرمز بعدة أسطر (تواريخ صلاحية مختلفة)، تُجمَع الكميات تلقائياً.<br>
            🆕 عمود "آخر سعر شراء" (الأخير) يغذّي "مراقب تذبذب التكلفة" — يُقارَن تلقائياً بآخر سعر مسجَّل سابقاً لنفس المادة عند كل رفع جديد.
        </div>
    </div>

    <form action="{{ route('purchasing.purchase-inventory.upload') }}" method="POST" enctype="multipart/form-data"
        data-loading-message="جاري معالجة ملف جرد المشتريات ومطابقته، قد يستغرق هذا حتى دقيقتين...">
        @csrf
        <x-file-upload-zone name="inventory_file" label="ملف جرد المشتريات" :required="true" accept=".xlsx,.xls,.csv" hint="حتى 20MB" />
        <button type="submit" class="btn btn-brand w-100 py-2 fw-medium rounded-2 mt-2">
            <i class="fa-solid fa-upload me-1"></i> رفع ومعالجة الملف
        </button>
    </form>
</div>
@endsection
