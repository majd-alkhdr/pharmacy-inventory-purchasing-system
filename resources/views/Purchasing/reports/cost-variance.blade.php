@extends('layouts.admin')

@section('page_title', 'مراقب تذبذب التكلفة')
@section('page_subtitle', 'المشتريات — تنبيهات')

@section('content')
<div class="surface-card p-4 shadow-sm">

    <div class="mb-4">
        <h6 class="fw-bold text-dark mb-1"><i class="fa-solid fa-chart-line text-danger me-1"></i> مواد ارتفع سعرها</h6>
        <p class="text-muted small mb-0">مقارنة "سعر المادة" الحالي بقيمته قبل آخر تغيير سجّله ملف الأصناف — لحماية هامش الربح من التآكل الخفي.</p>
    </div>

    <div class="border rounded-2 overflow-hidden shadow-sm bg-white">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead>
                    <tr>
                        <th>المادة</th>
                        <th class="text-center">السعر السابق</th>
                        <th class="text-center">السعر الحالي</th>
                        <th class="text-center">الفرق</th>
                        <th class="text-center">النسبة</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($variances as $row)
                        <tr>
                            <td>
                                <span class="d-block fw-bold text-dark">{{ $row->product->name }}</span>
                                <span class="text-muted" style="font-size: 0.72rem;">{{ $row->product->company }}</span>
                            </td>
                            <td class="text-center text-secondary">{{ number_format($row->previous_price, 2) }}</td>
                            <td class="text-center fw-bold text-dark">{{ number_format($row->current_price, 2) }}</td>
                            <td class="text-center fw-bold text-danger">+{{ number_format($row->variance_amount, 2) }}</td>
                            <td class="text-center">
                                <span class="badge bg-danger-subtle text-danger px-2 py-1 rounded-2">
                                    <i class="fa-solid fa-arrow-trend-up fa-xs me-1"></i> {{ $row->variance_percent }}%
                                </span>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="5" icon="fa-face-smile" message="لا توجد أي زيادات أسعار مرصودة حالياً — كل الأسعار مطابقة أو أقل من قيمتها السابقة." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
