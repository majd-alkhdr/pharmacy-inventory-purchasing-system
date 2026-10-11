{{-- resources/views/purchasing/reports/purchase-value.blade.php --}}
@extends('layouts.admin')

@section('page_title', 'تقرير قيمة المشتريات')
@section('page_subtitle', 'تقارير المشتريات — حسب سبب الشراء')

@push('styles')
    <style>
        .qty-badge-purchase {
            background-color: #ecfdf5;
            color: #16a34a;
            border: 1px solid #d1fae5;
        }
    </style>
@endpush

@section('content')
    <div class="filter-bar p-2.5 mb-3">
        <form method="GET" action="{{ route('purchasing.reports.purchase-value') }}" class="row g-2 align-items-end">
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
                <a href="{{ route('purchasing.reports.purchase-value') }}"
                    class="btn btn-outline-secondary btn-sm rounded-2 w-100">تصفير</a>
            </div>
        </form>
        <div class="small text-muted mt-2"><i class="fa-solid fa-circle-info me-1"></i> يحتسب فقط الكميات المستلَمة فعلياً
            (لا المقترحة) — فلترة التاريخ على آخر تحديث استلام لكل سطر، لا تاريخ توليد الفاتورة الأصلي.</div>
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
                            <th>سبب الشراء</th>
                            <th class="text-center">قيمة المشتريات</th>
                            <th class="text-center">عدد المواد</th>
                            <th class="text-center">عدد الشركات</th>
                            <th class="text-center no-print">التفاصيل</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr>
                                <td class="fw-bold text-dark"><i
                                        class="fa-solid fa-tag text-primary me-1"></i>{{ $row->reason_label }}</td>
                                <td class="text-center"><span
                                        class="badge qty-badge-purchase px-2.5 py-1 fw-bold fs-6">{{ number_format($row->total_value, 2) }}</span>
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
                                <td colspan="5" class="bg-light">
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
                            <x-empty-state colspan="5" icon="fa-sack-dollar"
                                message="لا توجد مشتريات مستلَمة تطابق محددات الفرز حالياً." />
                        @endforelse
                    </tbody>
                    @if ($rows->isNotEmpty())
                        <tfoot>
                            <tr class="table-light fw-bold">
                                <td>الإجمالي</td>
                                <td class="text-center">{{ number_format($totals->total_value, 2) }}</td>
                                <td class="text-center">{{ $totals->product_count }}</td>
                                <td class="text-center">{{ $totals->company_count }}</td>
                                <td></td>
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
                    <div class="kpi-label">إجمالي قيمة المشتريات</div>
                    <div class="kpi-value" style="color:#16a34a">{{ number_format($totals->total_value, 0) }}</div>
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
                    <div class="kpi-label">عدد الشركات</div>
                    <div class="kpi-value">{{ $totals->company_count }}</div>
                </div>
            </div>
        </div>
        <div class="border rounded-2 shadow-sm bg-white p-3">
            <canvas id="purchaseValueChart" height="90"></canvas>
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

        new Chart(document.getElementById('purchaseValueChart'), {
            type: 'bar',
            data: {
                labels: @json($rows->pluck('reason_label')),
                datasets: [{
                    label: 'قيمة المشتريات',
                    data: @json($rows->pluck('total_value')),
                    backgroundColor: '#16a34a',
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
