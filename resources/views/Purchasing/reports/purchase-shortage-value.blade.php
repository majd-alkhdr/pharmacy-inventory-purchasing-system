{{-- resources/views/purchasing/reports/purchase-shortage-value.blade.php --}}
@extends('layouts.admin')

@section('page_title', 'تقرير نواقص المشتريات')
@section('page_subtitle', 'تقارير المشتريات — حسب الشركة')

@push('styles')
    <style>
        .qty-badge-shortage {
            background-color: #fef2f2;
            color: #dc2626;
            border: 1px solid #fee2e2;
        }
    </style>
@endpush

@section('content')
    <div class="filter-bar p-2.5 mb-3">
        <form method="GET" action="{{ route('purchasing.reports.purchase-shortage-value') }}"
            class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="small text-secondary fw-semibold mb-1" style="font-size:.75rem">من تاريخ (اختياري)</label>
                <input type="date" name="date_from" value="{{ $dateFrom }}"
                    class="form-control form-control-sm rounded-2">
            </div>
            <div class="col-md-4">
                <label class="small text-secondary fw-semibold mb-1" style="font-size:.75rem">إلى تاريخ (اختياري)</label>
                <input type="date" name="date_to" value="{{ $dateTo }}"
                    class="form-control form-control-sm rounded-2">
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-brand btn-sm rounded-2 w-100 fw-medium"><i
                        class="fa-solid fa-filter fa-xs me-1"></i> تطبيق</button>
                <a href="{{ route('purchasing.reports.purchase-shortage-value') }}"
                    class="btn btn-outline-secondary btn-sm rounded-2 w-100">تصفير</a>
            </div>
        </form>
        <div class="small text-muted mt-2"><i class="fa-solid fa-circle-info me-1"></i> الفرق بين المطلوب والمستلَم فعلياً
            لأي فاتورة تجاوزت مرحلة الاستلام — حتى لو أُلغي الباقي لاحقاً، يبقى محتسَباً هنا كنقص تاريخي حدث فعلاً.</div>
    </div>

    <div class="d-flex gap-2 mb-3">
        <button type="button" class="report-toggle-btn active" data-view="table" onclick="switchReportView('table')"><i
                class="fa-solid fa-table-list me-1"></i> تفصيلي</button>
        <button type="button" class="report-toggle-btn" data-view="chart" onclick="switchReportView('chart')"><i
                class="fa-solid fa-chart-column me-1"></i> رسوم بيانية</button>
    </div>

    <div id="view-table">
        <div class="border rounded-2 overflow-hidden shadow-sm bg-white">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead>
                        <tr>
                            <th>الشركة</th>
                            <th class="text-center">قيمة النقص</th>
                            <th class="text-center">كمية النقص</th>
                            <th>المواد الناقصة</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr>
                                <td class="fw-bold text-dark"><i
                                        class="fa-solid fa-industry text-primary me-1"></i>{{ $row->company }}</td>
                                <td class="text-center"><span
                                        class="badge qty-badge-shortage px-2.5 py-1 fw-bold fs-6">{{ number_format($row->total_value, 2) }}</span>
                                </td>
                                <td class="text-center">{{ $row->total_qty }}</td>
                                <td style="min-width: 280px;"><x-names-grid :names="$row->product_names" /></td>
                            </tr>
                        @empty
                            <x-empty-state colspan="4" icon="fa-truck-ramp-box"
                                message="لا توجد نواقص شراء تطابق محددات الفرز حالياً." />
                        @endforelse
                    </tbody>
                    @if ($rows->isNotEmpty())
                        <tfoot>
                            <tr class="table-light fw-bold">
                                <td>الإجمالي ({{ $totals->company_count }} شركة)</td>
                                <td class="text-center">{{ number_format($totals->total_value, 2) }}</td>
                                <td class="text-center" colspan="2">{{ $totals->product_count }} مادة فريدة</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>

    <div id="view-chart" style="display:none;">
        <div class="row g-2 mb-3">
            <div class="col-md-4">
                <div class="kpi-card">
                    <div class="kpi-label">إجمالي قيمة النواقص</div>
                    <div class="kpi-value" style="color:#dc2626">{{ number_format($totals->total_value, 0) }}</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="kpi-card">
                    <div class="kpi-label">عدد المواد الفريدة</div>
                    <div class="kpi-value">{{ $totals->product_count }}</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="kpi-card">
                    <div class="kpi-label">عدد الشركات المتأثرة</div>
                    <div class="kpi-value">{{ $totals->company_count }}</div>
                </div>
            </div>
        </div>
        <div class="border rounded-2 shadow-sm bg-white p-3">
            <canvas id="purchaseShortageChart" height="90"></canvas>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script>
        function switchReportView(view) {
            document.getElementById('view-table').style.display = view === 'table' ? '' : 'none';
            document.getElementById('view-chart').style.display = view === 'chart' ? '' : 'none';
            document.querySelectorAll('.report-toggle-btn').forEach(btn => btn.classList.toggle('active', btn.dataset
                .view === view));
        }

        new Chart(document.getElementById('purchaseShortageChart'), {
            type: 'bar',
            data: {
                labels: @json($rows->pluck('company')),
                datasets: [{
                    label: 'قيمة النقص',
                    data: @json($rows->pluck('total_value')),
                    backgroundColor: '#dc2626',
                    borderRadius: 6
                }],
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            },
        });
    </script>
@endpush
