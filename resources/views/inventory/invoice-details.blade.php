@extends('layouts.admin')

@section('page_title', 'فاتورة مناقلة رقم #' . $invoice->id)
@section('page_subtitle', 'العمليات (المناقلات)')

@push('styles')
<style>
    .shortage-notice {
        color: var(--c-danger);
        background-color: #fef2f2;
        border: 1px solid #fee2e2;
        padding: 2px 8px;
        border-radius: 4px;
        display: inline-block;
    }
</style>
@endpush

@section('topbar_actions')
    <a href="{{ route('inventory.invoice.excel', ['id' => $invoice->id, 'availability' => request('availability')]) }}"
        class="btn btn-success btn-sm rounded-2 fw-medium text-nowrap no-print">
        <i class="fa-solid fa-file-excel me-1"></i> تصدير Excel
    </a>
    <button onclick="window.print()" class="btn btn-sm rounded-2 fw-medium text-nowrap no-print"
        style="background-color: var(--c-secondary); border-color: var(--c-secondary); color:#fff;">
        <i class="fa-solid fa-print me-1"></i> طباعة المستند
    </button>
    <a href="{{ route('inventory.invoices.index') }}"
        class="btn btn-outline-secondary btn-sm rounded-2 text-nowrap no-print">
        قائمة الفواتير <i class="fa-solid fa-arrow-left fa-xs ms-1"></i>
    </a>
@endsection

@section('content')
    <div class="surface-card p-4 p-lg-5 shadow-sm">

        {{-- 📄 ترويسة الفاتورة --}}
        <div class="row mb-4 border-bottom pb-3 align-items-center">
            <div class="col-sm-6 text-center text-sm-start mb-3 mb-sm-0">
                <h4 class="fw-bold text-dark mb-2">
                    <i class="fa-solid fa-file-invoice-dollar text-primary me-1 fs-4"></i> مستند مناقلة بضاعة
                    (ترميم)
                </h4>
                <p class="text-muted small mb-1">رقم الفاتورة المرجعي: <strong
                        class="text-dark">#{{ $invoice->id }}</strong></p>
                <p class="text-muted small mb-0"><i class="fa-regular fa-calendar me-1"></i> تاريخ الإصدار:
                    {{ $invoice->date }}</p>
            </div>
            <div class="col-sm-6 text-center text-sm-end">
                <span class="text-muted small d-block mb-1">الجهة المستلمة</span>
                <h5 class="fw-bold text-primary mb-0">
                    <i class="fa-solid fa-store fa-xs me-1"></i> {{ $invoice->pharmacy->name }}
                </h5>
            </div>
        </div>

        {{-- 🔍 فلترة الأصناف المعروضة --}}
        <form method="GET" action="{{ route('inventory.invoice.show', $invoice->id) }}"
            class="d-flex gap-2 align-items-center flex-wrap mb-4 no-print">
            <select name="availability" class="form-select form-select-sm rounded-2" style="width: auto"
                onchange="this.form.submit()">
                <option value="">📋 عرض كل الأصناف</option>
                <option value="available" @selected(request('availability') === 'available')>✅ الأصناف المتوفرة المشحونة فقط</option>
                <option value="shortage" @selected(request('availability') === 'shortage')>⚠️ أصناف العجز (النواقص) فقط</option>
            </select>
            @if (request('availability'))
                <a href="{{ route('inventory.invoice.show', $invoice->id) }}"
                    class="btn btn-link btn-sm text-decoration-none text-muted p-0 px-1">إلغاء الفرز</a>
            @endif
        </form>

        {{-- 📊 جدول عرض مواد المستند --}}
        <div class="border rounded-2 overflow-hidden shadow-sm bg-white mb-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead>
                        <tr>
                            <th style="width: 5%" class="text-center">#</th>
                            <th style="width: 45%">اسم المادة الطبية الكامل</th>
                            <th style="width: 20%">الشركة المصنعة</th>
                            <th style="width: 15%" class="text-center">الكمية المطلوبة</th>
                            <th style="width: 15%"
                                class="text-center table-success text-success fw-bold text-nowrap">الكمية المشحونة
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($items as $index => $item)
                            <tr>
                                <td class="text-center text-muted fw-semibold">{{ $index + 1 }}</td>
                                <td>
                                    <span class="d-block text-dark fw-bold mb-0.5">{{ $item->product->name }}</span>
                                    @if ($item->shortage_qty > 0)
                                        <div class="shortage-notice small mt-1">
                                            <i class="fa-solid fa-circle-exclamation me-0.5"></i> عجز متبقٍ:
                                            <strong>{{ $item->shortage_qty }}</strong> علبة
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge px-2 py-1"
                                        style="background:var(--c-bg);color:var(--c-secondary);border:1px solid var(--c-border);">{{ $item->product->company }}</span>
                                </td>
                                <td class="text-center text-secondary fw-semibold">
                                    {{ $item->requested_qty }}
                                </td>
                                <td class="text-center table-success text-success fw-bold fs-6">
                                    {{ $item->supplied_qty }}
                                </td>
                            </tr>
                        @empty
                            <x-empty-state colspan="5" icon="fa-folder-open" message="لا توجد أصناف تطابق الفلترة المختارة داخل هذه المناقلة." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- 📌 تذييل إرشادي للمستودع والصيدلية --}}
        <div class="p-3 bg-light border rounded-2 small text-secondary shadow-sm">
            <div class="d-flex align-items-center mb-1 text-dark fw-bold" style="font-size: 0.8rem;">
                <i class="fa-solid fa-clipboard-check text-primary me-1"></i> إرشادات التدقيق والاستلام:
            </div>
            <div class="ps-3 mb-1">- يُرجى مطابقة وتدقيق الأعداد المشحونة المذكورة أعلاه مع البضاعة المادية المستلمة
                فعلياً قبل التوقيع.</div>
            <div class="ps-3">- المواد والأصناف التي سجلت عجزاً تم ترحيلها تلقائياً لمخزن النواقص المركزي لتأمينها
                في أقرب طلبية توريد.</div>
        </div>
    </div>
@endsection
