@extends('layouts.admin')

@section('page_title', 'مناقلة مباشرة رقم #' . $transfer->id)
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
    {{-- 🆕 (ب.7) التنفيذ لمقترحة من الدورة الحالية فقط — غير ذلك أرشيف قراءة فقط --}}
    @if ($transfer->status === \App\Enums\PharmacyTransferStatus::Suggested && $cycle->contains($transfer->cycle_import_log_id))
        <form method="POST" action="{{ route('inventory.pharmacy-transfer.approve', $transfer->id) }}" class="d-inline no-print"
            onsubmit="return confirm('تنفيذ هذه المناقلة وحجز فائض الصيدلية المرسِلة؟');">
            @csrf
            <button type="submit" class="btn btn-success btn-sm rounded-2 fw-medium text-nowrap">
                <i class="fa-solid fa-check me-1"></i> تنفيذ
            </button>
        </form>
        <form method="POST" action="{{ route('inventory.pharmacy-transfer.dismiss', $transfer->id) }}" class="d-inline no-print">
            @csrf
            <button type="submit" class="btn btn-outline-danger btn-sm rounded-2 fw-medium text-nowrap">
                <i class="fa-solid fa-xmark me-1"></i> رفض
            </button>
        </form>
    @endif
    {{-- 🆕 تصدير Excel لإرسالها لفريق التحضير وإكمال النقل المادي فعلياً --}}
    <a href="{{ route('inventory.pharmacy-transfer.excel', ['id' => $transfer->id]) }}"
        class="btn btn-success btn-sm rounded-2 fw-medium text-nowrap no-print">
        <i class="fa-solid fa-file-excel me-1"></i> تصدير Excel
    </a>
    <button onclick="window.print()" class="btn btn-sm rounded-2 fw-medium text-nowrap no-print"
        style="background-color: var(--c-secondary); border-color: var(--c-secondary); color:#fff;">
        <i class="fa-solid fa-print me-1"></i> طباعة المستند
    </button>
    <a href="{{ route('inventory.pharmacy-transfers.review') }}"
        class="btn btn-outline-secondary btn-sm rounded-2 text-nowrap no-print">
        قائمة المناقلات المباشرة <i class="fa-solid fa-arrow-left fa-xs ms-1"></i>
    </a>
@endsection

@section('content')
    <div class="surface-card p-4 p-lg-5 shadow-sm">

        {{-- 📄 ترويسة المستند --}}
        <div class="row mb-4 border-bottom pb-3 align-items-center">
            <div class="col-sm-4 text-center text-sm-start mb-3 mb-sm-0">
                <h4 class="fw-bold text-dark mb-2">
                    <i class="fa-solid fa-right-left text-primary me-1 fs-4"></i> مستند مناقلة مباشرة
                </h4>
                <p class="text-muted small mb-1">رقم المستند: <strong class="text-dark">#{{ $transfer->id }}</strong></p>
                <p class="text-muted small mb-0">
                    <span class="badge px-2 py-1" style="background:var(--c-bg);color:var(--c-secondary);border:1px solid var(--c-border);">
                        {{ $transfer->status->label() }}
                    </span>
                    @if ($transfer->executed_date)
                        — نُفِّذت بتاريخ {{ $transfer->executed_date->format('Y-m-d') }}
                    @endif
                </p>
            </div>
            <div class="col-sm-4 text-center mb-3 mb-sm-0">
                <span class="text-muted small d-block mb-1">من صيدلية</span>
                <h5 class="fw-bold mb-0"><i class="fa-solid fa-store fa-xs me-1"></i> {{ $transfer->fromPharmacy->name }}</h5>
            </div>
            <div class="col-sm-4 text-center text-sm-end">
                <span class="text-muted small d-block mb-1">إلى صيدلية</span>
                <h5 class="fw-bold text-primary mb-0"><i class="fa-solid fa-store fa-xs me-1"></i> {{ $transfer->toPharmacy->name }}</h5>
            </div>
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
                                الكمية المقترَحة</th>
                            <th style="width: 30%">لقطة وقت الاقتراح</th>
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
                                    فائض المُرسِلة وقتها: {{ $item->from_pharmacy_excess_at_time }} — فجوة المستقبِلة وقتها:
                                    {{ $item->to_pharmacy_gap_at_time }}
                                </td>
                            </tr>
                        @empty
                            <x-empty-state colspan="5" icon="fa-folder-open" message="لا توجد أصناف بهذا المستند." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- 📌 تذييل إرشادي --}}
        <div class="p-3 bg-light border rounded-2 small text-secondary shadow-sm">
            <div class="d-flex align-items-center mb-1 text-dark fw-bold" style="font-size: 0.8rem;">
                <i class="fa-solid fa-clipboard-check text-primary me-1"></i> إرشادات التدقيق:
            </div>
            @if ($transfer->status === \App\Enums\PharmacyTransferStatus::Suggested)
                <div class="ps-3">- هذا اقتراح فقط — لم يُحجز أي فائض بعد. التنفيذ يحجز كمية كل صنف **كاملة** إن كان
                    فائض المُرسِلة ما زال يغطيها، وإلا يُلغى ذلك الصنف (لا تنفيذ جزئي).</div>
                @unless ($cycle->contains($transfer->cycle_import_log_id))
                    <div class="ps-3 text-warning">- مقترحة من دورة رفع سابقة — أرشيف قراءة فقط، لا تُنفَّذ.</div>
                @endunless
            @else
                <div class="ps-3 mb-1">- يُرجى مطابقة وتدقيق الأعداد المذكورة أعلاه مع البضاعة المادية المنقولة فعلياً بين
                    الصيدليتين.</div>
                <div class="ps-3">- التنفيذ حجز فائض المُرسِلة بهذه الدورة (فلا يُقترح لمناقلة أخرى)؛ الأرصدة نفسها تُحدَّث
                    فعلياً مع رفع الكشف التالي. نفَّذها: {{ $transfer->executor?->name ?? 'غير معروف' }}.</div>
            @endif
        </div>
    </div>
@endsection
