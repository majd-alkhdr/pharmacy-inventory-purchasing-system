@extends('layouts.admin')

@section('page_title', 'تدقيق يدوي — اختيار الفاتورة')
@section('page_subtitle', 'المشتريات — تسجيل استلام فعلي')

@section('topbar_actions')
    <a href="{{ route('purchasing.receiving.choose') }}" class="btn btn-outline-secondary btn-sm rounded-2">
        <i class="fa-solid fa-arrow-right fa-xs me-1"></i> رجوع لاختيار الطريقة
    </a>
@endsection

@section('content')
<div class="surface-card p-4 shadow-sm">
    <p class="text-muted small mb-3">اختر فاتورة الشراء التي تريد تدقيق استلامها يدوياً — تُعرَض فقط الفواتير المُرسَلة للمورد أو المستلَمة جزئياً.</p>

    @if ($orders->isEmpty())
        <div class="alert alert-warning small">لا توجد حالياً أي فواتير شراء بحالة "مُرسَلة" أو "استلام جزئي" بانتظار تسجيل استلام.</div>
    @else
        <div class="border rounded-2 overflow-hidden shadow-sm bg-white">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead>
                        <tr>
                            <th>رقم الفاتورة</th><th>المورد</th><th>الحالة</th><th class="text-center">عدد الأصناف</th><th class="text-center">إجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($orders as $order)
                            <tr>
                                <td class="fw-bold text-dark">#{{ $order->id }}</td>
                                <td class="fw-bold text-primary">{{ $order->supplier->name }}</td>
                                <td><span class="badge px-2 py-1 rounded-2" style="background: var(--c-bg); border: 1px solid var(--c-border);">{{ $order->status->label() }}</span></td>
                                <td class="text-center fw-bold">{{ $order->items()->count() }}</td>
                                <td class="text-center">
                                    <a href="{{ route('purchasing.receiving.manual.show', $order->id) }}" class="btn btn-brand btn-sm rounded-2 px-3 fw-medium">
                                        <i class="fa-solid fa-list-check fa-xs me-1"></i> بدء التدقيق
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
@endsection
