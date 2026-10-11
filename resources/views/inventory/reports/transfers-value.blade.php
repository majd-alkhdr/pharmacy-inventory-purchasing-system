@extends('layouts.admin')

@section('page_title', 'تقرير قيمة المناقلات')
@section('page_subtitle', 'التقارير — المناقلات')

@push('styles')
<style>
    .value-forward { color: #15803d; font-weight: 700; }
    .value-reverse { color: #1d4ed8; font-weight: 700; }
    .value-inter { color: #7e22ce; font-weight: 700; }
    .value-net-positive { color: #15803d; }
    .value-net-negative { color: #dc2626; }
</style>
@endpush

@section('content')
    @if ($totals->unpriced_product_count > 0)
        <div class="alert alert-warning small mb-3">
            <i class="fa-solid fa-triangle-exclamation me-1"></i>
            <strong>{{ number_format($totals->unpriced_product_count) }}</strong> مادة بهذه الحركات بلا "سعر مادة" سارٍ بتاريخ حركتها —
            محسوبة بقيمة صفر، فالقيم أدناه أقل من الحقيقة بقدرها. السعر يُحدَّث من ملف الأصناف.
        </div>
    @endif

    <div class="filter-bar p-2.5 mb-3">
        <form method="GET" action="{{ route('inventory.reports.transfers-value') }}" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="small text-secondary fw-semibold mb-1" style="font-size:.75rem">من تاريخ</label>
                <input type="date" name="date_from" value="{{ $dateFrom }}" class="form-control form-control-sm rounded-2">
            </div>
            <div class="col-md-3">
                <label class="small text-secondary fw-semibold mb-1" style="font-size:.75rem">إلى تاريخ</label>
                <input type="date" name="date_to" value="{{ $dateTo }}" class="form-control form-control-sm rounded-2">
            </div>
            <div class="col-md-3">
                <label class="small text-secondary fw-semibold mb-1" style="font-size:.75rem">صيدلية محددة</label>
                <select name="pharmacy_id" class="form-select form-select-sm rounded-2">
                    <option value="">كل الصيدليات</option>
                    @foreach ($pharmacies as $pharmacy)
                        <option value="{{ $pharmacy->id }}" @selected(request('pharmacy_id') == $pharmacy->id)>{{ $pharmacy->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-brand btn-sm rounded-2 w-100 fw-medium"><i class="fa-solid fa-filter fa-xs me-1"></i> تطبيق</button>
            </div>
        </form>
    </div>

    <div class="d-flex gap-2 mb-3">
        <button type="button" class="report-toggle-btn active" data-view="table" onclick="switchReportView('table')"><i class="fa-solid fa-table-list me-1"></i> تفصيلي</button>
        <button type="button" class="report-toggle-btn" data-view="chart" onclick="switchReportView('chart')"><i class="fa-solid fa-chart-column me-1"></i> رسوم بيانية</button>
    </div>

    {{-- ═══════════ الوضع التفصيلي ═══════════ --}}
    <div id="view-table">
        <div class="border rounded-2 overflow-hidden shadow-sm bg-white">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead>
                        <tr>
                            <th>الصيدلية</th>
                            <th class="text-center">من المستودع (إليها)</th>
                            <th class="text-center">من صيدليات أخرى (إليها)</th>
                            <th class="text-center">إلى المستودع — عكسية (منها)</th>
                            <th class="text-center">إلى صيدليات أخرى (منها)</th>
                            <th class="text-center">الصافي</th>
                            <th class="text-center">عدد المواد</th>
                            <th class="text-center">عدد الشركات</th>
                            <th class="text-center no-print">التفاصيل</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr>
                                <td class="fw-bold text-dark"><i class="fa-solid fa-store text-primary me-1"></i>{{ $row->pharmacy_name }}</td>
                                <td class="text-center value-forward">{{ number_format($row->forward_value, 2) }}</td>
                                <td class="text-center value-inter">{{ number_format($row->inter_in_value, 2) }}</td>
                                <td class="text-center value-reverse">{{ number_format($row->reverse_value, 2) }}</td>
                                <td class="text-center value-inter">{{ number_format($row->inter_out_value, 2) }}</td>
                                <td class="text-center fw-bold {{ $row->net_value >= 0 ? 'value-net-positive' : 'value-net-negative' }}">
                                    {{ number_format($row->net_value, 2) }}
                                </td>
                                <td class="text-center">{{ $row->product_count }}</td>
                                <td class="text-center">{{ $row->company_count }}</td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-2 py-0" data-bs-toggle="collapse" data-bs-target="#details-{{ $loop->index }}">
                                        <i class="fa-solid fa-chevron-down fa-xs"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr class="collapse" id="details-{{ $loop->index }}">
                                <td colspan="9" class="bg-light">
                                    <div class="row g-3 p-2">
                                        <div class="col-md-6">
                                            <div class="fw-bold small text-secondary mb-1">أسماء المواد ({{ $row->product_count }})</div>
                                            <x-names-grid :names="$row->product_names" />
                                        </div>
                                        <div class="col-md-6">
                                            <div class="fw-bold small text-secondary mb-1">أسماء الشركات ({{ $row->company_count }})</div>
                                            <x-names-grid :names="$row->company_names" />
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <x-empty-state colspan="9" icon="fa-arrow-right-arrow-left" message="لا توجد مناقلات (من المستودع، عكسية، أو بين الصيدليات) بالفترة المحدَّدة." />
                        @endforelse
                    </tbody>
                    @if ($rows->isNotEmpty())
                        <tfoot>
                            <tr class="table-light fw-bold">
                                <td>الإجمالي</td>
                                <td class="text-center value-forward">{{ number_format($totals->forward_value, 2) }}</td>
                                <td class="text-center value-inter">{{ number_format($totals->inter_in_value, 2) }}</td>
                                <td class="text-center value-reverse">{{ number_format($totals->reverse_value, 2) }}</td>
                                <td class="text-center value-inter">{{ number_format($totals->inter_out_value, 2) }}</td>
                                <td class="text-center {{ $totals->net_value >= 0 ? 'value-net-positive' : 'value-net-negative' }}">{{ number_format($totals->net_value, 2) }}</td>
                                <td class="text-center">{{ $totals->product_count }}</td>
                                <td class="text-center">{{ $totals->company_count }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
        <div class="small text-muted mt-2"><i class="fa-solid fa-circle-info me-1"></i> كل قيمة محسوبة بالسعر الذي كان سارياً فعلياً وقت وقوع الحدث (لا سعر اليوم الحالي). الصافي = إليها − منها. المناقلات بين الصيدليات نقل داخلي: إجمالي الوارد = إجمالي الصادر بلا فلتر صيدلية.</div>
    </div>

    {{-- ═══════════ وضع الرسوم البيانية ═══════════ --}}
    <div id="view-chart" style="display:none;">
        <div class="row g-2 mb-3">
            <div class="col-md-3"><div class="kpi-card"><div class="kpi-label">من المستودع (إجمالي)</div><div class="kpi-value value-forward">{{ number_format($totals->forward_value, 0) }}</div></div></div>
            <div class="col-md-3"><div class="kpi-card"><div class="kpi-label">عكسية إلى المستودع (إجمالي)</div><div class="kpi-value value-reverse">{{ number_format($totals->reverse_value, 0) }}</div></div></div>
            <div class="col-md-3"><div class="kpi-card"><div class="kpi-label">بين الصيدليات (منقول)</div><div class="kpi-value value-inter">{{ number_format($totals->inter_out_value, 0) }}</div></div></div>
            <div class="col-md-3"><div class="kpi-card"><div class="kpi-label">الصافي</div><div class="kpi-value {{ $totals->net_value >= 0 ? 'value-net-positive' : 'value-net-negative' }}">{{ number_format($totals->net_value, 0) }}</div></div></div>
        </div>
        <div class="border rounded-2 shadow-sm bg-white p-3">
            <canvas id="transfersValueChart" height="90"></canvas>
        </div>
    </div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    function switchReportView(view) {
        document.getElementById('view-table').style.display = view === 'table' ? '' : 'none';
        document.getElementById('view-chart').style.display = view === 'chart' ? '' : 'none';
        document.querySelectorAll('.report-toggle-btn').forEach(btn => btn.classList.toggle('active', btn.dataset.view === view));
    }

    const chartLabels = @json($rows->pluck('pharmacy_name'));

    new Chart(document.getElementById('transfersValueChart'), {
        type: 'bar',
        data: {
            labels: chartLabels,
            datasets: [
                { label: 'مناقلات (إلى الصيدلية)', data: @json($rows->pluck('forward_value')), backgroundColor: '#15803d', borderRadius: 4 },
                { label: 'من صيدليات أخرى', data: @json($rows->pluck('inter_in_value')), backgroundColor: '#a855f7', borderRadius: 4 },
                { label: 'مناقلات عكسية (من الصيدلية)', data: @json($rows->pluck('reverse_value')), backgroundColor: '#1d4ed8', borderRadius: 4 },
                { label: 'إلى صيدليات أخرى', data: @json($rows->pluck('inter_out_value')), backgroundColor: '#6b21a8', borderRadius: 4 },
            ],
        },
        options: { responsive: true, scales: { y: { beginAtZero: true } } },
    });
</script>
@endpush
