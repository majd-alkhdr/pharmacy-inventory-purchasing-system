{{-- resources/views/purchasing/reports/supplier-summary.blade.php --}}
@extends('layouts.admin')

@section('page_title', 'تقرير الموردين')
@section('page_subtitle', 'تقارير المشتريات — قيمة الشراء والنقص لكل مورد')

@push('styles')
    <style>
        .qty-badge-purchase {
            background-color: #ecfdf5;
            color: #16a34a;
            border: 1px solid #d1fae5;
        }

        .qty-badge-shortage {
            background-color: #fef2f2;
            color: #dc2626;
            border: 1px solid #fee2e2;
        }
    </style>
@endpush

@section('content')
    <div class="filter-bar p-2.5 mb-3">
        <form method="GET" action="{{ route('purchasing.reports.supplier-summary') }}" class="row g-2 align-items-end">
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
                <a href="{{ route('purchasing.reports.supplier-summary') }}"
                    class="btn btn-outline-secondary btn-sm rounded-2 w-100">تصفير</a>
            </div>
        </form>
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
                            <th>المورد</th>
                            <th class="text-center">قيمة الشراء</th>
                            <th class="text-center">قيمة النقص</th>
                            <th class="text-center">عدد المواد</th>
                            <th class="text-center">عدد الشركات</th>
                            <th class="text-center no-print">التفاصيل</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr>
                                <td class="fw-bold text-dark"><i
                                        class="fa-solid fa-truck-field text-primary me-1"></i>{{ $row->supplier_name }}</td>
                                <td class="text-center"><span
                                        class="badge qty-badge-purchase px-2.5 py-1 fw-bold fs-6">{{ number_format($row->total_purchase_value, 2) }}</span>
                                </td>
                                <td class="text-center"><span
                                        class="badge qty-badge-shortage px-2.5 py-1 fw-bold fs-6">{{ number_format($row->total_shortage_value, 2) }}</span>
                                </td>
                                <td class="text-center">{{ $row->product_count }}</td>
                                <td class="text-center">{{ $row->company_count }}</td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-2 py-0"
                                        data-bs-toggle="collapse" data-bs-target="#details-{{ $loop->index }}">
                                        <i class="fa-solid fa-chevron-down fa-xs"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr class="collapse" id="details-{{ $loop->index }}">
                                <td colspan="6" class="bg-light">
                                    <div class="row g-3 p-2">
                                        <div class="col-md-6">
                                            <div class="fw-bold small text-secondary mb-1">أسماء المواد
                                                ({{ $row->product_count }})</div>
                                            <x-names-grid :names="$row->product_names" />
                                        </div>
                                        <div class="col-md-6">
                                            <div class="fw-bold small text-secondary mb-1">أسماء الشركات
                                                ({{ $row->company_count }})</div>
                                            <x-names-grid :names="$row->company_names" />
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <x-empty-state colspan="6" icon="fa-truck-field"
                                message="لا توجد بيانات موردين تطابق محددات الفرز حالياً." />
                        @endforelse
                    </tbody>
                    @if ($rows->isNotEmpty())
                        <tfoot>
                            <tr class="table-light fw-bold">
                                <td>الإجمالي ({{ $totals->supplier_count }} مورد)</td>
                                <td class="text-center">{{ number_format($totals->total_purchase_value, 2) }}</td>
                                <td class="text-center">{{ number_format($totals->total_shortage_value, 2) }}</td>
                                <td colspan="3"></td>
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
                    <div class="kpi-label">إجمالي قيمة الشراء</div>
                    <div class="kpi-value" style="color:#16a34a">{{ number_format($totals->total_purchase_value, 0) }}
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="kpi-card">
                    <div class="kpi-label">إجمالي قيمة النقص</div>
                    <div class="kpi-value" style="color:#dc2626">{{ number_format($totals->total_shortage_value, 0) }}
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="kpi-card">
                    <div class="kpi-label">عدد الموردين</div>
                    <div class="kpi-value">{{ $totals->supplier_count }}</div>
                </div>
            </div>
        </div>
        <div class="border rounded-2 shadow-sm bg-white p-3">
            <canvas id="supplierSummaryChart" height="90"></canvas>
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

        new Chart(document.getElementById('supplierSummaryChart'), {
            type: 'bar',
            data: {
                labels: @json($rows->pluck('supplier_name')),
                datasets: [{
                        label: 'قيمة الشراء',
                        data: @json($rows->pluck('total_purchase_value')),
                        backgroundColor: '#16a34a',
                        borderRadius: 6
                    },
                    {
                        label: 'قيمة النقص',
                        data: @json($rows->pluck('total_shortage_value')),
                        backgroundColor: '#dc2626',
                        borderRadius: 6
                    },
                ],
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: true
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
