@extends('layouts.admin')

@section('page_title', 'تقرير دوران المخزون')
@section('page_subtitle', 'التقارير — المناقلات')

@push('styles')
    <style>
        /* 🩹 [2026-09] كانت background:#fff ثابتة — بينما النص بداخلها
               (.text-dark) يتبدّل للأبيض بالوضع الداكن، فيصبح غير مرئي فوق
               هذه الخلفية البيضاء الثابتة. var(--c-surface) يجعل البطاقة
               تتبع الثيم كبقية الواجهة. */
        .location-card {
            border: 1px solid var(--c-border);
            border-radius: 10px;
            background: var(--c-surface);
            padding: 1rem 1.1rem;
            cursor: pointer;
            transition: border-color .15s ease, background-color .15s ease;
        }

        .location-card:hover,
        .location-card.selected {
            border-color: var(--c-primary);
        }

        .location-card.selected {
            background-color: var(--c-primary-soft);
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: .3rem;
            font-size: .68rem;
            font-weight: 700;
            padding: .15rem .55rem;
            border-radius: 999px;
            margin-inline-end: .35rem;
        }

        .status-stagnant {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fee2e2;
        }

        .status-normal {
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #dbeafe;
        }

        .status-high_turnover {
            background: #f0fdf4;
            color: #15803d;
            border: 1px solid #dcfce7;
        }

        .status-too_new_to_judge {
            background: #f3f4f6;
            color: #6b7280;
            border: 1px solid #e5e7eb;
        }

        .stale-badge {
            font-size: .66rem;
            color: #b45309;
            font-weight: 600;
        }
    </style>
@endpush

@php
    $allLocations = collect([$central])->concat($pharmacies);
    $statusLabels = [
        'stagnant' => 'راكد 🐌',
        'normal' => 'عادي',
        'high_turnover' => 'دوران عالٍ 🚀',
        'too_new_to_judge' => 'جديد',
    ];
@endphp

@section('content')

    <div class="alert alert-light border small text-secondary mb-3">
        <i class="fa-solid fa-circle-info text-primary me-1"></i>
        الدوران = (الرصيد الحالي ÷ مجموع الكميات المطلوبة بفواتير المناقلات الأساسية خلال آخر 7 أيام فيها طلب فعلي) ÷ 4،
        بالأشهر — للصيدلية تُفضَّل المبيعات المسجَّلة حين تتوفر. الرصيد من كشف المستودعات (آخر رفع)، وهو نفس المصدر
        الذي يقرؤه قسم المشتريات.
    </div>

    {{-- ═══════════ نظرة عامة على كل المواقع ═══════════ --}}
    <div class="row g-2 mb-4" id="locationCards">
        @foreach ($allLocations as $index => $location)
            <div class="col-md-4 col-lg-3">
                <div class="location-card {{ $index === 0 ? 'selected' : '' }}" data-location-index="{{ $index }}"
                    onclick="selectLocation({{ $index }})">
                    <div class="fw-bold text-dark small mb-1">
                        @if ($location->is_central)
                            <i class="fa-solid fa-warehouse text-warning me-1"></i>
                        @else
                            <i class="fa-solid fa-store text-primary me-1"></i>
                        @endif
                        {{ $location->label }}
                    </div>
                    <div class="mb-1">
                        @foreach ($statusLabels as $key => $labelText)
                            @if ($location->status_counts[$key] > 0)
                                <span class="status-pill status-{{ $key }}">{{ $labelText }}
                                    {{ $location->status_counts[$key] }}</span>
                            @endif
                        @endforeach
                        @if ($location->product_count === 0)
                            <span class="text-muted small">لا بيانات</span>
                        @endif
                    </div>
                    @if (!$location->is_central)
                        @if ($location->last_stock_update)
                            <div class="stale-badge">
                                <i class="fa-regular fa-clock me-1"></i>آخر تحديث رصيد:
                                {{ \Carbon\Carbon::parse($location->last_stock_update)->diffForHumans() }}
                            </div>
                        @else
                            <div class="stale-badge"><i class="fa-solid fa-triangle-exclamation me-1"></i>لا يوجد جرد مرفوع
                                لهذه الصيدلية بعد</div>
                        @endif
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    {{-- ═══════════ التفصيل الكامل للموقع المختار ═══════════ --}}
    @foreach ($allLocations as $index => $location)
        <div class="location-detail" id="locationDetail-{{ $index }}"
            style="{{ $index === 0 ? '' : 'display:none;' }}">
            <div class="border rounded-2 overflow-hidden shadow-sm bg-white">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead>
                            <tr>
                                <th>المادة</th>
                                <th>الشركة</th>
                                <th class="text-center">الرصيد الحالي</th>
                                <th class="text-center">متوسط الشحن الأسبوعي (آخر 7 أيام فعلية)</th>
                                <th class="text-center">الدوران (أشهر)</th>
                                <th class="text-center">التصنيف</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($location->rows as $row)
                                <tr>
                                    <td class="fw-medium text-dark">{{ $row->product_name }}</td>
                                    <td><span class="badge brand-badge px-2 py-1">{{ $row->company }}</span></td>
                                    <td class="text-center">{{ number_format($row->current_stock) }}</td>
                                    <td class="text-center">
                                        {{ number_format($row->weekly_outflow, 1) }}
                                        @if (($row->data_source ?? 'shipment_estimate') === 'real_sales')
                                            <span class="badge px-1 py-0"
                                                style="font-size:.6rem;background:#f0fdf4;color:#15803d;border:1px solid #dcfce7;"
                                                title="مبيعات حقيقية مسجَّلة">مبيعات فعلية</span>
                                        @else
                                            <span class="badge px-1 py-0"
                                                style="font-size:.6rem;background:#fff7ed;color:#9a5b13;border:1px solid #fed7aa;"
                                                title="تقدير من كميات الشحن — لا يوجد ملف مبيعات حقيقي مرفوع لهذا الزوج بعد">تقدير
                                                من الشحن</span>
                                        @endif
                                    </td>
                                    <td class="text-center fw-bold">
                                        @if (is_null($row->turnover_months))
                                            —
                                        @elseif ($row->turnover_months >= PHP_FLOAT_MAX)
                                            ∞
                                        @else
                                            {{ number_format($row->turnover_months, 2) }}
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        <span
                                            class="status-pill status-{{ $row->status->value }}">{{ $statusLabels[$row->status->value] }}</span>
                                    </td>
                                </tr>
                            @empty
                                <x-empty-state colspan="6" icon="fa-rotate"
                                    message="لا توجد بيانات دوران كافية لهذا الموقع حالياً." />
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endforeach

@endsection

@push('scripts')
    <script>
        function selectLocation(index) {
            document.querySelectorAll('.location-detail').forEach(el => el.style.display = 'none');
            document.getElementById('locationDetail-' + index).style.display = '';

            document.querySelectorAll('.location-card').forEach(el => el.classList.remove('selected'));
            document.querySelector('[data-location-index="' + index + '"]').classList.add('selected');
        }
    </script>
@endpush
