@extends('layouts.admin')

@section('page_title', 'حل تعارض الموردين')
@section('page_subtitle', 'المشتريات — قبل إكمال التوليد')

@section('content')
<div class="surface-card p-4 shadow-sm" style="max-width: 760px; margin: 0 auto;">

    <div class="text-center mb-4">
        <span class="badge bg-warning text-dark px-3 py-2 rounded-pill fw-bold mb-2">
            <i class="fa-solid fa-triangle-exclamation me-1"></i> يتطلب إجراء منك
        </span>
        <h4 class="fw-bold text-dark mb-0">شركات لها أكثر من مورد مفضِّل</h4>
        <p class="text-muted small mb-0 mt-1">
            الشركات التالية موزَّعة من أكثر من مورد واحد بقائمة "الشركات المفضّلة" — اختر مورداً واحداً لكل شركة
            لهذه الدفعة من التوليد. ⚠️ هذا الاختيار مؤقت لهذه العملية فقط، وسيُطلَب منك مجدداً بأي توليد مستقبلي.
        </p>
    </div>

    <form action="{{ route('purchasing.orders.resolve-conflicts.save') }}" method="POST">
        @csrf

        <div class="border rounded-2 overflow-hidden shadow-sm bg-white mb-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead>
                        <tr>
                            <th style="width: 40%">الشركة المصنّعة</th>
                            <th>اختر المورد لهذه الدفعة</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($suppliersByCompany as $company => $suppliers)
                            <tr>
                                <td class="fw-bold text-dark">{{ $company }}</td>
                                <td>
                                    <select name="supplier_choice[{{ $company }}]" class="form-select form-select-sm" required>
                                        <option value="">— اختر مورداً —</option>
                                        @foreach ($suppliers as $supplier)
                                            <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="d-flex gap-2">
            <a href="{{ route('purchasing.orders.create') }}" class="btn btn-outline-secondary w-100 rounded-2">إلغاء والعودة</a>
            <button type="submit" class="btn btn-success w-100 fw-medium rounded-2">
                <i class="fa-solid fa-check-double me-1"></i> حفظ الاختيار ومتابعة التوليد
            </button>
        </div>
    </form>
</div>
@endsection
