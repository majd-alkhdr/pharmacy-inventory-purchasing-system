@extends('layouts.admin')

@section('page_title', 'المناقلات العكسية')
@section('page_subtitle', 'العمليات (المناقلات) — صيدليات تتجاوز مخزونها القياسي')

@push('styles')
<style>
    .qty-badge-return {
        background-color: #eff6ff;
        color: #1d4ed8;
        border: 1px solid #dbeafe;
    }

    /* 🩹 كانت var(--c-secondary) — تتفاعل مع الشريط العلوي، بينما هذا
       النص يعيش دائماً داخل بطاقة بيضاء ثابتة لا تتفاعل إطلاقاً. */
    .snapshot-text {
        font-size: 0.72rem;
        color: #6c757d;
    }
</style>
@endpush

@section('content')

    {{-- بطاقات مؤشرات الأداء --}}
    <div class="row g-2 mb-3">
        <div class="col-md-4">
            <x-stat-card icon="fa-rotate-left" label="إجمالي الكمية المُعادة للمستودع" :value="$stats['total_quantity']" unit="قطعة" />
        </div>
        <div class="col-md-4">
            <x-stat-card icon="fa-capsules" label="عدد المواد الفريدة المتأثرة" :value="$stats['unique_products']" unit="صنف" />
        </div>
        <div class="col-md-4">
            <x-stat-card icon="fa-hospital" label="عدد الصيدليات صاحبة الفائض" :value="$stats['affected_pharmacies']" unit="صيدلية" />
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-light border small mb-3">{{ session('status') }}</div>
    @endif

    <div class="alert alert-light border small text-secondary mb-3">
        <i class="fa-solid fa-circle-info text-primary me-1"></i>
        تُقترح هذه المناقلات تلقائياً عند تشغيل الترميم بناءً على المخزون القياسي (أو الوضع المدمج)، لأي صيدلية
        كان رصيدها الفعلي يتجاوز مخزونها القياسي بأكثر من "الحد الأدنى للفرق" المحدَّد لها لنفس الصنف.
        <strong>لا شيء يُنفَّذ تلقائياً</strong> — اضغط «تنفيذ» لحجز الفائض؛ عندها تختفي أي مناقلة بين صيدليات
        تعتمد على نفس الفائض بهذه الدورة (والعكس).
    </div>

    {{-- 🆕 (ب.6) مقترحات الدورة الحالية بانتظار التنفيذ --}}
    <div class="border rounded-2 overflow-hidden shadow-sm bg-white mb-4">
        <div class="p-2.5 border-bottom bg-light fw-bold small d-flex justify-content-between flex-wrap gap-2">
            <span><i class="fa-solid fa-hourglass-half text-warning me-1"></i> مقترحة بانتظار التنفيذ ({{ $pending->count() }})</span>
            <span class="text-muted fw-normal">
                <i class="fa-regular fa-clock me-1"></i> آخر تحديث للبيانات:
                {{ $cycle->startedAt?->format('Y-m-d H:i') ?? 'غير معروف (قبل تفعيل سجل الرفع)' }}
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead>
                    <tr>
                        <th>الصيدلية</th>
                        <th>الأصناف (المغطاة بالفائض الحالي)</th>
                        <th class="text-center">إجمالي الكمية</th>
                        <th class="text-center" style="width: 22%">إجراء</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($pending as $transfer)
                        @php($covered = $transfer->items->where('is_covered', true))
                        <tr>
                            <td class="fw-bold text-dark">{{ $transfer->pharmacy->name }}</td>
                            <td>
                                {{ $covered->count() }} من {{ $transfer->items->count() }}
                                @if ($covered->count() < $transfer->items->count())
                                    <span class="d-block text-warning" style="font-size: .7rem;">
                                        <i class="fa-solid fa-triangle-exclamation"></i>
                                        بعض الأصناف التُزم بفائضها لمناقلة أخرى — ستُلغى عند التنفيذ.
                                    </span>
                                @endif
                            </td>
                            <td class="text-center fw-bold">{{ $covered->sum('quantity') }}</td>
                            <td class="text-center">
                                <div class="d-flex gap-1 justify-content-center flex-wrap">
                                    <a href="{{ route('inventory.reverse-transfer.show', $transfer->id) }}"
                                        class="btn btn-outline-secondary btn-sm rounded-2 px-2 py-1" title="عرض"><i class="fa-solid fa-eye fa-xs"></i></a>
                                    <form method="POST" action="{{ route('inventory.reverse-transfer.execute', $transfer->id) }}"
                                        onsubmit="return confirm('تنفيذ هذه المناقلة العكسية وحجز فائضها؟');">
                                        @csrf
                                        <button class="btn btn-success btn-sm rounded-2 px-2 py-1"><i class="fa-solid fa-check fa-xs"></i> تنفيذ</button>
                                    </form>
                                    <form method="POST" action="{{ route('inventory.reverse-transfer.dismiss', $transfer->id) }}">
                                        @csrf
                                        <button class="btn btn-outline-danger btn-sm rounded-2 px-2 py-1"><i class="fa-solid fa-xmark fa-xs"></i> رفض</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="4" icon="fa-check-double" message="لا توجد مناقلات عكسية مقترحة بانتظار التنفيذ بالدورة الحالية." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <h6 class="fw-bold small text-dark mb-2"><i class="fa-solid fa-clock-rotate-left text-muted me-1"></i> المناقلات العكسية المنفَّذة</h6>

    {{-- 🔍 شريط الفلترة --}}
    <div class="filter-bar p-2.5 mb-3">
        <form method="GET" action="{{ route('inventory.reverse-transfers.report') }}" id="filterForm"
            class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="small text-secondary fw-semibold mb-1" style="font-size: 0.75rem;">صيدلية
                    محددة</label>
                <select name="pharmacy_id" class="form-select form-select-sm rounded-2" onchange="document.getElementById('filterForm').submit()">
                    <option value="">جميع الصيدليات</option>
                    @foreach ($pharmacies as $pharmacy)
                        <option value="{{ $pharmacy->id }}" @selected(request('pharmacy_id') == $pharmacy->id)>
                            {{ $pharmacy->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="small text-secondary fw-semibold mb-1" style="font-size: 0.75rem;">الفترة من</label>
                <input type="date" name="date_from" value="{{ request('date_from') }}" class="form-control form-control-sm rounded-2">
            </div>
            <div class="col-md-2">
                <label class="small text-secondary fw-semibold mb-1" style="font-size: 0.75rem;">الفترة إلى</label>
                <input type="date" name="date_to" value="{{ request('date_to') }}" class="form-control form-control-sm rounded-2">
            </div>
            <div class="col-md-5 d-flex gap-2 justify-content-end">
                <button type="submit" class="btn btn-sm rounded-2 text-nowrap fw-medium px-3"
                    style="background-color: #495057; border-color: #495057; color:#fff;">
                    <i class="fa-solid fa-filter fa-xs me-1"></i> تصفية
                </button>
                <a href="{{ route('inventory.reverse-transfers.report') }}" class="btn btn-outline-secondary btn-sm rounded-2 text-nowrap px-3">
                    تصفير
                </a>
                <a href="{{ route('inventory.reverse-transfers.export', request()->only('pharmacy_id', 'date_from', 'date_to')) }}"
                    class="btn btn-success btn-sm rounded-2 text-nowrap fw-medium px-3">
                    <i class="fa-solid fa-file-excel me-1"></i> إكسيل
                </a>
            </div>
        </form>
    </div>

    {{-- 📑 الجدول --}}
    <div class="border rounded-2 overflow-hidden shadow-sm bg-white">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead>
                    <tr>
                        <th>تاريخ المناقلة</th>
                        <th>الصيدلية</th>
                        <th>المادة</th>
                        <th>الشركة</th>
                        <th class="text-center">الكمية المُعادة</th>
                        <th>لقطة وقت الرصد</th>
                        <th class="text-center">المستند</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $item)
                        <tr>
                            <td class="text-nowrap fw-bold text-dark">
                                {{ $item->reverseTransfer->movementDate()->format('Y-m-d') }}
                            </td>
                            <td>
                                <span class="fw-bold text-primary">
                                    <i class="fa-solid fa-store fa-xs text-muted me-1"></i>{{ $item->reverseTransfer->pharmacy->name }}
                                </span>
                            </td>
                            <td class="fw-medium text-dark">{{ $item->product->name }}</td>
                            <td><span class="badge brand-badge px-2 py-1">{{ $item->product->company }}</span></td>
                            <td class="text-center">
                                <span class="badge qty-badge-return px-2.5 py-1 fw-bold fs-6">
                                    <i class="fa-solid fa-arrow-right-to-bracket fa-xs me-1"></i>{{ $item->quantity }}
                                </span>
                            </td>
                            <td class="snapshot-text">
                                القياسي: {{ $item->standard_quantity_at_time }} — رصيد الصيدلية وقتها: {{ $item->pharmacy_quantity_at_time }}
                            </td>
                            <td class="text-center">
                                {{-- 🆕 كل عنصر بهذا الجدول المسطَّح ينتمي لمستند مناقلة واحد (raw_transfer) —
                                     رابط مباشر له، لا إعادة هيكلة للجدول نفسه. --}}
                                <a href="{{ route('inventory.reverse-transfer.show', $item->reverseTransfer->id) }}"
                                    class="btn btn-outline-secondary btn-sm rounded-2 px-2 py-1" title="عرض مستند المناقلة الكامل">
                                    <i class="fa-solid fa-file-lines fa-xs"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="7" icon="fa-rotate-left" message="لا توجد مناقلات عكسية تطابق محددات الفرز حالياً." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3 d-flex justify-content-center">
        {{ $items->links('pagination::bootstrap-5') }}
    </div>
@endsection
