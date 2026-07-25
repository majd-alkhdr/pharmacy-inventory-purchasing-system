@extends('layouts.admin')

@section('page_title', 'الفواتير الصادرة')
@section('page_subtitle', 'العمليات (المناقلات)')

@section('topbar_actions')
    <a href="{{ route('inventory.upload.form') }}" class="btn btn-brand btn-sm rounded-2 px-3">
        <i class="fa-solid fa-plus fa-xs me-1"></i> رفع ملف جديد
    </a>
@endsection

@section('content')
    <div class="surface-card p-4 shadow-sm">

        {{-- 🔍 فورم الفلترة والبحث المتقدم --}}
        <form method="GET" action="{{ route('inventory.invoices.index') }}" class="p-3 mb-4 rounded-2"
            style="background-color: var(--c-bg); border: 1px solid var(--c-border);">
            <div class="row g-2.5 align-items-end">

                {{-- 🆕 فلتر التاريخ — افتراضياً تاريخ اليوم --}}
                <div class="col-md-2 col-sm-6">
                    <label class="form-label small fw-bold text-secondary mb-1">تاريخ الفاتورة</label>
                    <input type="date" name="date" value="{{ $currentDate }}"
                        class="form-control form-control-sm rounded-2" onchange="this.form.submit()">
                </div>

                <div class="col-md-2 col-sm-6">
                    <label class="form-label small fw-bold text-secondary mb-1">حالة توفر الأصناف</label>
                    <select name="availability" class="form-select form-select-sm rounded-2" onchange="this.form.submit()">
                        <option value="">كل الحالات (متوفر + عجز)</option>
                        <option value="available" @selected(request('availability') === 'available')>✅ المشحون فقط</option>
                        <option value="shortage" @selected(request('availability') === 'shortage')>❌ العجز فقط</option>
                    </select>
                </div>

                <div class="col-md-2 col-sm-6">
                    <label class="form-label small fw-bold text-secondary mb-1">تصنيف ونوع الفاتورة</label>
                    <select name="status" class="form-select form-select-sm rounded-2" onchange="this.form.submit()">
                        <option value="">كل الأنواع</option>
                        <option value="processed" @selected(request('status') === 'processed')>أساسية</option>
                        <option value="supplementary_invoice" @selected(request('status') === 'supplementary_invoice')>ترميم من مبيعات سابقة
                            (نواقص)</option>
                    </select>
                </div>

                <div class="col-md-3 col-sm-6">
                    <label class="form-label small fw-bold text-secondary mb-1">تصفية حسب الصيدلية</label>
                    <select name="pharmacy_id" class="form-select form-select-sm rounded-2" onchange="this.form.submit()">
                        <option value="">كل الصيدليات</option>
                        @foreach ($pharmacies as $pharmacy)
                            <option value="{{ $pharmacy->id }}" @selected(request('pharmacy_id') == $pharmacy->id)>
                                {{ $pharmacy->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3 col-sm-12 d-flex gap-2 justify-content-end">
                    <a href="{{ route('inventory.invoices.index') }}"
                        class="btn btn-outline-secondary btn-sm rounded-2 w-100 fw-medium">
                        تصفية اليوم فقط
                    </a>
                    {{-- 🆕 ندمج التاريخ الحالي (المعروض فعلياً بالشاشة) مع باقي الفلاتر عند بناء رابط التصدير --}}
                    <a href="{{ route('inventory.invoices.export-all', array_merge(request()->only('status', 'pharmacy_id', 'availability'), ['date' => $currentDate])) }}"
                        class="btn btn-success btn-sm rounded-2 w-100 fw-medium text-nowrap">
                        <i class="fa-solid fa-file-excel me-1"></i> تصدير Excel
                    </a>
                </div>
            </div>

            <div class="mt-2 small text-muted">
                <i class="fa-solid fa-circle-info me-1"></i>
                الفلترة والتصدير مقتصران افتراضياً على تاريخ
                <strong>{{ \Carbon\Carbon::parse($currentDate)->format('Y-m-d') }}</strong> فقط.
            </div>
        </form>

        {{-- 📊 جدول الفواتير المجمعة --}}
        <div class="border rounded-2 overflow-hidden shadow-sm bg-white mb-3">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead>
                        <tr>
                            <th style="width: 10%">رقم الفاتورة</th>
                            <th style="width: 30%">اسم الصيدلية المستلمة</th>
                            <th style="width: 15%">تاريخ الإصدار</th>
                            <th style="width: 25%">تصنيف مستند المناقلة</th>
                            <th style="width: 10%" class="text-center">عدد المواد</th>
                            <th style="width: 10%" class="text-center">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($invoices as $invoice)
                            <tr>
                                <td class="text-dark fw-bold">#{{ $invoice->id }}</td>
                                <td><span class="fw-bold text-dark">{{ $invoice->pharmacy->name }}</span></td>
                                <td class="text-secondary fw-semibold">
                                    {{ \Carbon\Carbon::parse($invoice->date)->format('Y-m-d') }}</td>
                                <td>
                                    @if ($invoice->isProcessed())
                                        <span class="badge badge-soft-primary px-2.5 py-1 rounded-2">
                                            <i class="fa-solid fa-file-invoice me-1"></i> فاتورة أساسية
                                        </span>
                                    @else
                                        <span class="badge px-2.5 py-1 rounded-2"
                                            style="background-color:#f0fdf4;color:#166534;border:1px solid #bbf7d0;">
                                            <i class="fa-solid fa-clock-rotate-left me-1"></i> ترميم طلبات (نواقص
                                            سابق)
                                        </span>
                                    @endif
                                </td>
                                <td class="text-center fw-bold text-secondary">{{ $invoice->items_count }}</td>
                                <td class="text-center">
                                    <a href="{{ route('inventory.invoice.show', $invoice->id) }}"
                                        class="btn btn-outline-primary btn-sm rounded-2 px-2.5 fw-medium">
                                        عرض التفاصيل
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <x-empty-state colspan="6" icon="fa-box-open" message="لا توجد سجلات فواتير تطابق معايير الفلترة الحالية." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3 d-flex justify-content-center">
            {{ $invoices->links('pagination::bootstrap-5') }}
        </div>
    </div>
@endsection
