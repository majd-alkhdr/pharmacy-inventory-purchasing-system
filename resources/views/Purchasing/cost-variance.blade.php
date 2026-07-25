@extends('layouts.admin')

@section('page_title', 'مراقب تذبذب التكلفة')
@section('page_subtitle', 'المشتريات — تنبيهات')

@section('content')
<div class="surface-card p-4 shadow-sm">

    <div class="mb-4">
        <h6 class="fw-bold text-dark mb-1"><i class="fa-solid fa-chart-line text-danger me-1"></i> مواد ارتفع سعرها الحالي عن آخر توريد فعلي</h6>
        <p class="text-muted small mb-0">مقارنة السعر المُسجَّل حالياً بالمورد مقابل آخر سعر دُفع فعلياً لنفس رابطة المورد-المادة — لحماية هامش الربح من التآكل الخفي.</p>
    </div>

    <div class="border rounded-2 overflow-hidden shadow-sm bg-white">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead>
                    <tr>
                        <th>المادة</th>
                        <th>المورد</th>
                        <th class="text-center">آخر سعر فعلي مدفوع</th>
                        <th class="text-center">السعر الحالي المُسجَّل</th>
                        <th class="text-center">الفرق</th>
                        <th class="text-center">النسبة</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($variances as $row)
                        <tr>
                            <td>
                                <span class="d-block fw-bold text-dark">{{ $row->supplier_product->product->name }}</span>
                                <span class="text-muted" style="font-size: 0.72rem;">{{ $row->supplier_product->product->company }}</span>
                            </td>
                            <td class="fw-bold text-primary">{{ $row->supplier_product->supplier->name }}</td>
                            <td class="text-center text-secondary">{{ number_format($row->last_paid_cost, 2) }}</td>
                            <td class="text-center fw-bold text-dark">{{ number_format($row->current_cost, 2) }}</td>
                            <td class="text-center fw-bold text-danger">+{{ number_format($row->variance_amount, 2) }}</td>
                            <td class="text-center">
                                <span class="badge bg-danger-subtle text-danger px-2 py-1 rounded-2">
                                    <i class="fa-solid fa-arrow-trend-up fa-xs me-1"></i> {{ $row->variance_percent }}%
                                </span>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="6" icon="fa-face-smile" message="لا توجد أي زيادات أسعار مرصودة حالياً — كل الأسعار مطابقة أو أقل من آخر توريد فعلي." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
