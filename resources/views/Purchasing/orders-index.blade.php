@extends('layouts.admin')

@section('page_title', 'فواتير الشراء المقترحة')
@section('page_subtitle', 'المشتريات')

@section('topbar_actions')
    <a href="{{ route('purchasing.orders.create') }}" class="btn btn-brand btn-sm rounded-2 px-3">
        <i class="fa-solid fa-wand-magic-sparkles fa-xs me-1"></i> توليد فواتير جديدة
    </a>
@endsection

@section('content')
<div class="surface-card p-4 shadow-sm">

    {{-- فلترة سريعة --}}
    <form method="GET" class="row g-2 mb-3 align-items-end">
        <div class="col-md-3">
            <label class="form-label small fw-bold text-secondary mb-1">الحالة</label>
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">كل الحالات</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold text-secondary mb-1">المورد</label>
            <select name="supplier_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">كل الموردين</option>
                @foreach ($suppliers as $supplier)
                    <option value="{{ $supplier->id }}" @selected(request('supplier_id') == $supplier->id)>{{ $supplier->name }}</option>
                @endforeach
            </select>
        </div>
    </form>

    <div class="border rounded-2 overflow-hidden shadow-sm bg-white">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead>
                    <tr>
                        <th>رقم الفاتورة</th>
                        <th>المورد</th>
                        <th>الحالة</th>
                        <th>السيناريو</th>
                        <th class="text-center">عدد الأصناف</th>
                        <th class="text-center">التكلفة التقديرية</th>
                        <th>تاريخ التوليد</th>
                        <th class="text-center">الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($orders as $order)
                        <tr>
                            <td class="fw-bold text-dark">#{{ $order->id }}</td>
                            <td class="fw-bold text-primary">{{ $order->supplier->name }}</td>
                            <td>
                                <span class="badge px-2 py-1 rounded-2" style="background: var(--c-bg); border: 1px solid var(--c-border); color: var(--c-secondary);">
                                    {{ $order->status->label() }}
                                </span>
                                @if ($order->isOverBudget())
                                    <span class="badge bg-danger-subtle text-danger px-2 py-1 rounded-2 ms-1">تجاوز الميزانية</span>
                                @endif
                            </td>
                            <td class="text-secondary">{{ $order->scenario->label() }}</td>
                            <td class="text-center fw-bold">{{ $order->items->count() ?: $order->items()->count() }}</td>
                            <td class="text-center fw-bold text-dark">{{ number_format($order->total_estimated_cost, 2) }}</td>
                            <td class="text-secondary">{{ $order->generated_at->format('Y-m-d H:i') }}</td>
                            <td class="text-center">
                                <a href="{{ route('purchasing.orders.show', $order->id) }}" class="btn btn-outline-primary btn-sm rounded-2 px-2.5 fw-medium">
                                    عرض التفاصيل
                                </a>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="8" icon="fa-file-invoice" message="لا توجد فواتير شراء مقترحة بعد — جرّب توليد فواتير جديدة." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3 d-flex justify-content-center">
        {{ $orders->links('pagination::bootstrap-5') }}
    </div>
</div>
@endsection
