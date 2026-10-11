@extends('layouts.admin')

@section('page_title', 'سجل النواقص والعجز')
@section('page_subtitle', 'العمليات (المناقلات)')

@push('styles')
<style>
    .stat-card {
        border: 1px solid #E4E5EA;
        border-radius: 10px;
        background-color: #fff;
    }

    .stat-icon {
        color: var(--c-primary);
        background-color: var(--c-primary-soft);
        padding: 10px;
        border-radius: 8px;
    }

    .sticky-sidebar {
        position: sticky;
        top: calc(var(--topbar-h) + 16px);
    }

    .notes-input {
        border: 1px solid #cbd5e1;
    }

    .notes-input:focus {
        border-color: var(--c-primary);
        box-shadow: none;
        background-color: #fff;
    }

    .qty-badge {
        background-color: #fef2f2;
        color: #dc2626;
        border: 1px solid #fee2e2;
    }

    /* 🆕 شارة مصدر النقص — لون مختلف لكل مصدر لتمييزهما بسرعة بالجدول */
    .source-badge-sales {
        background-color: #eff6ff;
        color: #1d4ed8;
        border: 1px solid #dbeafe;
    }

    .source-badge-standard-inventory {
        background-color: #f0fdf4;
        color: #15803d;
        border: 1px solid #dcfce7;
    }
</style>
@endpush

@section('topbar_actions')
    <a href="{{ route('inventory.shortages.upload.form') }}" class="btn btn-outline-primary btn-sm rounded-2 px-3">
        <i class="fa-solid fa-file-import me-1"></i> استيراد نواقص تاريخية
    </a>
    <a href="{{ route('inventory.upload.form') }}" class="btn btn-brand btn-sm rounded-2 px-3">
        <i class="fa-solid fa-plus me-1"></i> رفع ملف جديد
    </a>
@endsection

@section('content')

    {{-- بطاقات مؤشرات الأداء --}}
    <div class="row g-2 mb-3">
        <div class="col-md-4">
            <x-stat-card icon="fa-capsules" label="عدد المواد الناقصة الفريدة" :value="$stats['unique_products']" unit="صنف" />
        </div>
        <div class="col-md-4">
            <x-stat-card icon="fa-calculator" label="إجمالي القطع المطلوبة" :value="$stats['total_quantity']" unit="قطعة" />
        </div>
        <div class="col-md-4">
            <x-stat-card icon="fa-hospital" label="عدد الصيدليات المتأثرة بالطلب" :value="$stats['affected_pharmacies']" unit="صيدلية" />
        </div>
    </div>

    {{-- 🔍 شريط الفلترة --}}
    <div class="filter-bar p-2.5 mb-3">
        <form method="GET" action="{{ route('inventory.shortages.dashboard') }}" id="filterForm"
            class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="small text-secondary fw-semibold mb-1" style="font-size: 0.75rem;">حالة
                    النقص</label>
                <select name="status" class="form-select form-select-sm rounded-2"
                    onchange="document.getElementById('filterForm').submit()">
                    <option value="pending" @selected(request('status', 'pending') === 'pending')>⏳ الطلبات المعلقة</option>
                    <option value="fulfilled" @selected(request('status') === 'fulfilled')>✅ الطلبات المحلولة</option>
                    <option value="" @selected(request('status') === '')>عرض الكل</option>
                </select>
            </div>
            {{-- 🆕 فلتر المصدر --}}
            <div class="col-md-2">
                <label class="small text-secondary fw-semibold mb-1" style="font-size: 0.75rem;">مصدر
                    النقص</label>
                <select name="source" class="form-select form-select-sm rounded-2"
                    onchange="document.getElementById('filterForm').submit()">
                    <option value="" @selected(request('source', '') === '')>كل المصادر</option>
                    <option value="sales" @selected(request('source') === 'sales')>🛒 نقص مبيعات</option>
                    <option value="standard_inventory" @selected(request('source') === 'standard_inventory')>📊 نقص مخزون قياسي</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="small text-secondary fw-semibold mb-1" style="font-size: 0.75rem;">صيدلية
                    محددة</label>
                <select name="pharmacy_id" class="form-select form-select-sm rounded-2"
                    onchange="document.getElementById('filterForm').submit()">
                    <option value="">جميع الصيدليات المشتركة</option>
                    @foreach ($pharmacies as $pharmacy)
                        <option value="{{ $pharmacy->id }}" @selected(request('pharmacy_id') == $pharmacy->id)>
                            {{ $pharmacy->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="small text-secondary fw-semibold mb-1" style="font-size: 0.75rem;">الفترة
                    من</label>
                <input type="date" name="date_from" value="{{ request('date_from') }}"
                    class="form-control form-control-sm rounded-2">
            </div>
            <div class="col-md-2">
                <label class="small text-secondary fw-semibold mb-1" style="font-size: 0.75rem;">الفترة
                    إلى</label>
                <input type="date" name="date_to" value="{{ request('date_to') }}"
                    class="form-control form-control-sm rounded-2">
            </div>
            <div class="col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-sm w-50 rounded-2 text-nowrap fw-medium"
                    style="background-color: #495057; border-color: #495057; color:#fff;">
                    <i class="fa-solid fa-filter fa-xs me-1"></i> تصفية
                </button>
                <a href="{{ route('inventory.shortages.dashboard') }}"
                    class="btn btn-outline-secondary btn-sm w-25 rounded-2 text-nowrap">
                    تصفير
                </a>
                {{-- 🩹 أُضيف source هنا — بدونها كان رابط التصدير يفقد فلتر
                     المصدر الحالي بمجرد الضغط عليه (يُصدِّر كل المصادر
                     دائماً حتى لو الجدول المعروض مفلتَر لمصدر واحد فقط). --}}
                <a href="{{ route('inventory.shortages.export', request()->only('status', 'pharmacy_id', 'source', 'date_from', 'date_to')) }}"
                    class="btn btn-success btn-sm w-50 rounded-2 text-nowrap fw-medium">
                    <i class="fa-solid fa-file-excel me-1"></i> إكسيل
                </a>
            </div>
        </form>
    </div>

    <div class="row g-3">

        {{-- 📑 الجدول الرئيسي --}}
        <div class="col-xl-9 col-lg-8">
            <div class="border rounded-2 overflow-hidden shadow-sm bg-white">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead>
                            <tr>
                                <th>تاريخ الطلب</th>
                                <th>الصيدلية</th>
                                <th>المادة المستهدفة</th>
                                <th>الشركة</th>
                                <th class="text-center">الكمية</th>
                                <th>المصدر</th> {{-- 🆕 --}}
                                <th>الحالة</th>
                                <th>ملاحظات وتدقيق</th>
                                <th class="text-center no-print">الإجراء</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($shortages as $shortage)
                                <tr
                                    class="{{ $shortage->isPending() ? '' : 'table-light text-muted' }}">
                                    <td class="text-nowrap">
                                        <span
                                            class="fw-bold text-dark d-block mb-0">{{ $shortage->created_at->format('Y-m-d') }}</span>
                                        <span class="text-muted small" style="font-size: 0.7rem;"><i
                                                class="fa-regular fa-clock me-1"></i>{{ $shortage->created_at->diffForHumans() }}</span>
                                    </td>
                                    <td>
                                        <span class="fw-bold text-primary"><i
                                                class="fa-solid fa-store fa-xs text-muted me-1"></i>{{ $shortage->pharmacy->name }}</span>
                                    </td>
                                    <td class="fw-medium text-dark">
                                        {{ $shortage->product->name }}
                                    </td>
                                    <td>
                                        <span
                                            class="badge brand-badge px-2 py-1">{{ $shortage->product->company }}</span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge qty-badge px-2.5 py-1 fw-bold fs-6">
                                            {{ $shortage->quantity }}
                                        </span>
                                    </td>
                                    {{-- 🆕 شارة المصدر --}}
                                    <td>
                                        <span
                                            class="badge source-badge-{{ str_replace('_', '-', $shortage->source->value) }} px-2 py-1">
                                            {{ $shortage->source->label() }}
                                        </span>
                                    </td>
                                    <td>
                                        @if ($shortage->isPending())
                                            <span class="badge bg-warning text-dark px-2 py-1"><i
                                                    class="fa-solid fa-spinner fa-spin fa-xs me-1"></i>معلق</span>
                                        @else
                                            <span class="badge bg-success px-2 py-1"><i
                                                    class="fa-solid fa-check-double fa-xs me-1"></i>محلول</span>
                                        @endif
                                    </td>
                                    <td>
                                        <form action="{{ route('inventory.shortages.note', $shortage->id) }}"
                                            method="POST" class="input-group input-group-sm">
                                            @csrf
                                            <input type="text" name="notes"
                                                value="{{ $shortage->notes }}"
                                                class="form-control notes-input bg-light border-end-0"
                                                placeholder="إضافة ملاحظة...">
                                            <button
                                                class="btn btn-outline-secondary border-start-0 bg-light text-muted"
                                                type="submit">
                                                <i class="fa-regular fa-floppy-disk"></i>
                                            </button>
                                        </form>
                                    </td>
                                    <td class="text-center">
                                        @if ($shortage->isPending())
                                            <form
                                                action="{{ route('inventory.shortages.resolve', $shortage->id) }}"
                                                method="POST"
                                                onsubmit="return confirm('تأكيد نقل الطلب للإرشيف المحلول يدوياً؟')">
                                                @csrf
                                                <button
                                                    class="btn btn-sm btn-outline-success rounded-pill px-2 py-0.5 fw-medium text-nowrap"
                                                    style="font-size: 0.75rem;">
                                                    <i class="fa-solid fa-check"></i> حُلّ
                                                </button>
                                            </form>
                                        @else
                                            <span class="text-muted small"><i
                                                    class="fa-solid fa-circle-minus opacity-25"></i></span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <x-empty-state colspan="9" icon="fa-inbox" message="لا توجد سجلات عجز أو نواقص تطابق محددات الفرز حالياً." />
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="mt-3 d-flex justify-content-center">
                {{ $shortages->links('pagination::bootstrap-5') }}
            </div>
        </div>

        {{-- 📊 القائمة الجانبية (الأعلى نقصاً) --}}
        <div class="col-xl-3 col-lg-4">
            <div class="sticky-sidebar">
                <div class="card border-0 shadow-sm rounded-2 overflow-hidden"
                    style="border: 1px solid #E4E5EA !important;">
                    <div class="card-header py-2.5 border-0 d-flex justify-content-between align-items-center"
                        style="background-color: #1f2937;">
                        <h6 class="mb-0 fw-bold text-white small">
                            <i class="fa-solid fa-fire text-warning me-1"></i> الأعلى نقصاً (المعلقة)
                        </h6>
                        <span class="badge fw-bold rounded-pill p-1 small"
                            style="font-size: 0.65rem; background-color: #495057; color:#fff;">
                            @if(request('pharmacy_id') || request('date_from') || request('date_to'))
                                مطابق للفلتر
                            @else
                                عام لكل النظام
                            @endif
                        </span>
                    </div>
                    <div class="card-body p-0 bg-white">
                        <div class="table-responsive" style="max-height: 520px;">
                            <table class="table table-hover align-middle m-0 small">
                                <thead>
                                    <tr class="table-light text-secondary border-bottom"
                                        style="font-size: 0.75rem;">
                                        <th>اسم المادة الطبية الكامل</th>
                                        <th class="text-center" style="width: 25%;">إجمالي العجز</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($summary as $item)
                                        <tr>
                                            <td class="py-2">
                                                <span class="d-block text-dark fw-semibold lh-base">
                                                    {{ $item->product->name }}
                                                </span>
                                                <small class="text-muted d-block mt-0.5"
                                                    style="font-size:0.7rem">
                                                    <i class="fa-solid fa-industry fa-xs me-0.5"></i>
                                                    {{ $item->product->company }}
                                                </small>
                                            </td>
                                            <td class="text-center py-2">
                                                <span class="badge qty-badge fw-bold rounded px-2 py-1">
                                                    {{ $item->total }}
                                                </span>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="2" class="text-center text-muted py-4 small">لا
                                                توجد طلبات متراكمة</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
@endsection
