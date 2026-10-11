@extends('layouts.admin')

@section('page_title', 'مناقلة عكسية رقم #' . $transfer->id)
@section('page_subtitle', 'العمليات (المناقلات)')

@push('styles')
<style>
    .snapshot-text {
        font-size: 0.72rem;
        color: #6c757d;
    }
</style>
@endpush

@section('topbar_actions')
    {{-- 🆕 (ب.6) التنفيذ متاح فقط لمقترحة من الدورة الحالية — غير ذلك أرشيف قراءة فقط --}}
    @if ($transfer->isSuggested() && $cycle->contains($transfer->cycle_import_log_id))
        <form method="POST" action="{{ route('inventory.reverse-transfer.execute', $transfer->id) }}" class="d-inline no-print"
            onsubmit="return confirm('تنفيذ هذه المناقلة العكسية وحجز فائضها؟');">
            @csrf
            <button class="btn btn-success btn-sm rounded-2 fw-medium text-nowrap"><i class="fa-solid fa-check me-1"></i> تنفيذ</button>
        </form>
        <form method="POST" action="{{ route('inventory.reverse-transfer.dismiss', $transfer->id) }}" class="d-inline no-print">
            @csrf
            <button class="btn btn-outline-danger btn-sm rounded-2 fw-medium text-nowrap"><i class="fa-solid fa-xmark me-1"></i> رفض</button>
        </form>
    @endif
    <a href="{{ route('inventory.reverse-transfer.excel', ['id' => $transfer->id]) }}"
        class="btn btn-success btn-sm rounded-2 fw-medium text-nowrap no-print">
        <i class="fa-solid fa-file-excel me-1"></i> تصدير Excel
    </a>
    <button onclick="window.print()" class="btn btn-sm rounded-2 fw-medium text-nowrap no-print"
        style="background-color: var(--c-secondary); border-color: var(--c-secondary); color:#fff;">
        <i class="fa-solid fa-print me-1"></i> طباعة المستند
    </button>
    <a href="{{ route('inventory.reverse-transfers.report') }}"
        class="btn btn-outline-secondary btn-sm rounded-2 text-nowrap no-print">
        قائمة المناقلات العكسية <i class="fa-solid fa-arrow-left fa-xs ms-1"></i>
    </a>
@endsection

@section('content')
    <div class="surface-card p-4 p-lg-5 shadow-sm">

        {{-- 📄 ترويسة المستند --}}
        <div class="row mb-4 border-bottom pb-3 align-items-center">
            <div class="col-sm-6 text-center text-sm-start mb-3 mb-sm-0">
                <h4 class="fw-bold text-dark mb-2">
                    <i class="fa-solid fa-rotate-left text-primary me-1 fs-4"></i> مستند مناقلة عكسية (فائض)
                </h4>
                <p class="text-muted small mb-1">رقم المستند المرجعي: <strong
                        class="text-dark">#{{ $transfer->id }}</strong></p>
                <p class="text-muted small mb-0"><i class="fa-regular fa-calendar me-1"></i> تاريخ الرصد:
                    {{ \Carbon\Carbon::parse($transfer->date)->format('Y-m-d') }}</p>
            </div>
            <div class="col-sm-6 text-center text-sm-end">
                <span class="text-muted small d-block mb-1">الجهة المُرسِلة (صاحبة الفائض)</span>
                <h5 class="fw-bold text-primary mb-0">
                    <i class="fa-solid fa-store fa-xs me-1"></i> {{ $transfer->pharmacy->name }}
                </h5>
                <span class="text-muted small d-block mt-1">المستلم: المستودع المركزي</span>
            </div>
        </div>

        @if (session('status'))
            <div class="alert alert-light border small mb-3">{{ session('status') }}</div>
        @endif

        {{-- 🆕 (ب.6) حالة المستند --}}
        <div class="d-flex gap-2 flex-wrap mb-3 small">
            <span class="badge px-2.5 py-1 rounded-2" style="background: var(--c-bg); border: 1px solid var(--c-border); color: var(--c-secondary);">
                {{ $transfer->status?->label() ?? '—' }}
            </span>
            <span class="badge badge-soft-primary px-2.5 py-1 rounded-2">{{ $transfer->origin?->label() }}</span>
            @if ($transfer->executed_at)
                <span class="text-muted">نُفِّذت {{ $transfer->executed_at->format('Y-m-d H:i') }} — {{ $transfer->executor?->name ?? 'غير معروف' }}</span>
            @endif
            @if ($transfer->isSuggested() && !$cycle->contains($transfer->cycle_import_log_id))
                <span class="text-warning"><i class="fa-solid fa-box-archive"></i> مقترحة من دورة رفع سابقة — أرشيف قراءة فقط.</span>
            @endif
        </div>

        {{-- 📊 جدول عرض أصناف المستند --}}
        <div class="border rounded-2 overflow-hidden shadow-sm bg-white mb-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead>
                        <tr>
                            <th style="width: 5%" class="text-center">#</th>
                            <th style="width: 35%">اسم المادة الطبية الكامل</th>
                            <th style="width: 15%">الشركة المصنعة</th>
                            <th style="width: 15%" class="text-center table-primary text-primary fw-bold text-nowrap">
                                الكمية المُعادة</th>
                            <th style="width: 30%">لقطة وقت الرصد</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($transfer->items as $index => $item)
                            <tr>
                                <td class="text-center text-muted fw-semibold">{{ $index + 1 }}</td>
                                <td>
                                    <span class="d-block text-dark fw-bold mb-0.5">{{ $item->product->name }}</span>
                                    @if ($item->execution_status)
                                        <span class="snapshot-text {{ $item->execution_status === \App\Enums\TransferItemExecutionStatus::Superseded ? 'text-danger' : 'text-success' }}">
                                            {{ $item->execution_status->label() }}
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge px-2 py-1"
                                        style="background:var(--c-bg);color:var(--c-secondary);border:1px solid var(--c-border);">{{ $item->product->company }}</span>
                                </td>
                                <td class="text-center table-primary text-primary fw-bold fs-6">
                                    {{ $item->quantity }}
                                </td>
                                <td class="snapshot-text">
                                    القياسي: {{ $item->standard_quantity_at_time }} — رصيد الصيدلية وقتها:
                                    {{ $item->pharmacy_quantity_at_time }}
                                </td>
                            </tr>
                        @empty
                            <x-empty-state colspan="5" icon="fa-folder-open" message="لا توجد أصناف بهذا المستند." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- 📌 تذييل إرشادي للمستودع --}}
        <div class="p-3 bg-light border rounded-2 small text-secondary shadow-sm">
            <div class="d-flex align-items-center mb-1 text-dark fw-bold" style="font-size: 0.8rem;">
                <i class="fa-solid fa-clipboard-check text-primary me-1"></i> إرشادات التدقيق والاستلام:
            </div>
            <div class="ps-3 mb-1">- يُرجى مطابقة وتدقيق الأعداد المذكورة أعلاه مع البضاعة المادية المستلمة من
                الصيدلية فعلياً قبل التوقيع.</div>
            <div class="ps-3">- التنفيذ يحجز فائض الصيدلية بهذه الدورة (فلا يُقترح لمناقلة أخرى)؛ أرصدة المستودع
                والصيدلية تُحدَّث فعلياً مع رفع الكشف التالي.</div>
        </div>
    </div>
@endsection
