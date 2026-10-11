@extends('layouts.admin')

@section('page_title', 'تقرير قيمة المخزون')
@section('page_subtitle', 'التقارير — المناقلات')

@push('styles')
    <style>
        /* 🩹 [2026-09] كانت خلفية بيضاء مائلة للبرتقالي ثابتة — بينما نص
           خلايا الجدول (.table td) يتبدّل حسب الثيم ويصبح فاتحاً بالوضع
           الداكن، فيختفي فوق هذه الخلفية الفاتحة الثابتة. */
        .central-row {
            background-color: var(--c-primary-soft);
        }
    </style>
@endpush

@section('content')
    <div class="d-flex gap-2 mb-3">
        <button type="button" class="report-toggle-btn active" data-view="table" onclick="switchReportView('table')">
            <i class="fa-solid fa-table-list me-1"></i> تفصيلي
        </button>
        <button type="button" class="report-toggle-btn" data-view="chart" onclick="switchReportView('chart')">
            <i class="fa-solid fa-chart-column me-1"></i> رسوم بيانية
        </button>
    </div>

    {{-- ═══════════ الوضع التفصيلي ═══════════ --}}
    <div id="view-table">
        <div class="border rounded-2 overflow-hidden shadow-sm bg-white">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead>
                        <tr>
                            <th>الموقع</th>
                            <th class="text-center">القيمة الإجمالية</th>
                            <th class="text-center">عدد المواد</th>
                            <th class="text-center">عدد الشركات</th>
                            <th class="text-center no-print">التفاصيل</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr class="{{ $row->is_central ? 'central-row' : '' }}">
                                <td class="fw-bold text-dark">
                                    @if ($row->is_central)
                                        <i class="fa-solid fa-warehouse text-warning me-1"></i>
                                    @else
                                        <i class="fa-solid fa-store text-primary me-1"></i>
                                    @endif
                                    {{ $row->label }}
                                </td>
                                <td class="text-center fw-bold">{{ number_format($row->total_value, 2) }}</td>
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
                            <x-empty-state colspan="5" icon="fa-boxes-stacked"
                                message="لا يوجد مخزون حالياً بأي صيدلية أو بالمستودع المركزي." />
                        @endforelse
                    </tbody>
                    @if ($rows->isNotEmpty())
                        <tfoot>
                            <tr class="table-light fw-bold">
                                <td>الإجمالي (بلا ازدواج للمواد المشتركة)</td>
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

    {{-- ═══════════ وضع الرسوم البيانية ═══════════ --}}
    <div id="view-chart" style="display:none;">
        <div class="row g-2 mb-3">
            <div class="col-md-3">
                <div class="kpi-card">
                    <div class="kpi-label">القيمة الإجمالية للنظام</div>
                    <div class="kpi-value">{{ number_format($totals->total_value, 0) }}</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="kpi-card">
                    <div class="kpi-label">عدد المواقع (صيدليات + مركزي)</div>
                    <div class="kpi-value">{{ $totals->locations_count }}</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="kpi-card">
                    <div class="kpi-label">عدد المواد الفريدة بالنظام</div>
                    <div class="kpi-value">{{ $totals->product_count }}</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="kpi-card">
                    <div class="kpi-label">عدد الشركات الفريدة بالنظام</div>
                    <div class="kpi-value">{{ $totals->company_count }}</div>
                </div>
            </div>
        </div>
        <div class="border rounded-2 shadow-sm bg-white p-3">
            <canvas id="inventoryValueChart" height="90"></canvas>
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

        // 🎯 بيانات الرسم تُبنى من نفس $rows المُستخدَمة بالجدول أعلاه — مصدر
        // حقيقة واحد، لا استعلام ثانٍ منفصل لأجل الرسم البياني فقط.
        const chartLabels = @json($rows->pluck('label'));
        const chartValues = @json($rows->pluck('total_value'));
        const chartColors = @json($rows->pluck('is_central')->map(fn($c) => $c ? '#f59e0b' : '#FF6B00'));

        new Chart(document.getElementById('inventoryValueChart'), {
            type: 'bar',
            data: {
                labels: chartLabels,
                datasets: [{
                    label: 'القيمة الإجمالية',
                    data: chartValues,
                    backgroundColor: chartColors,
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
                },
            },
        });
    </script>
@endpush
