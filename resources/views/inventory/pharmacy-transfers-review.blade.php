@extends('layouts.admin')

@section('page_title', 'مناقلات مباشرة بين الصيدليات')
@section('page_subtitle', 'العمليات (المناقلات) — اقتراحات آلية، بانتظار مراجعتك')

@section('topbar_actions')
    <form method="POST" action="{{ route('inventory.pharmacy-transfers.refresh') }}" class="d-inline">
        @csrf
        <button type="submit" class="btn btn-sm rounded-2 fw-medium text-nowrap"
            style="background-color: var(--c-secondary); border-color: var(--c-secondary); color:#fff;">
            <i class="fa-solid fa-arrows-rotate me-1"></i> تحديث الاقتراحات الآن
        </button>
    </form>
@endsection

@section('content')

    @if (session('status'))
        <div class="alert alert-light border small mb-3">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger small mb-3">{{ session('error') }}</div>
    @endif

    <div class="alert alert-light border small text-secondary mb-3">
        <i class="fa-solid fa-circle-info text-primary me-1"></i>
        يقارن هذا المحرك فائض كل صيدلية (فوق مخزونها القياسي) بفجوة صيدلية أخرى (دون مخزونها القياسي) لنفس المادة،
        ويقترح مناقلة مباشرة توفّر خطوة شحن كاملة عبر المستودع المركزي. <strong>لا شيء يُنفَّذ تلقائياً</strong> —
        كل اقتراح ينتظر تنفيذك أو رفضك صراحة. التنفيذ يحجز الفائض، فتختفي أي مناقلة عكسية تعتمد عليه (والعكس).
        <div class="mt-1"><i class="fa-regular fa-clock me-1"></i> آخر تحديث للبيانات:
            <strong>{{ $cycle->startedAt?->format('Y-m-d H:i') ?? 'غير معروف (قبل تفعيل سجل الرفع)' }}</strong>
            — تُعرض اقتراحات هذه الدورة فقط.</div>
    </div>

    {{-- 📑 الاقتراحات المعلَّقة --}}
    <div class="border rounded-2 overflow-hidden shadow-sm bg-white mb-4">
        <div class="p-2.5 border-bottom bg-light fw-bold small">
            <i class="fa-solid fa-hourglass-half text-warning me-1"></i> اقتراحات بانتظار المراجعة ({{ $suggested->count() }})
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead>
                    <tr>
                        <th>من صيدلية</th>
                        <th>إلى صيدلية</th>
                        <th class="text-center">عدد الأصناف</th>
                        <th class="text-center">إجمالي الكمية المقترَحة</th>
                        <th class="text-center" style="width: 20%">إجراء</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($suggested as $transfer)
                        <tr>
                            <td class="fw-bold text-dark">
                                <i class="fa-solid fa-store fa-xs text-muted me-1"></i>{{ $transfer->fromPharmacy->name }}
                            </td>
                            <td class="fw-bold text-primary">
                                <i class="fa-solid fa-arrow-left fa-xs text-muted me-1"></i>{{ $transfer->toPharmacy->name }}
                            </td>
                            @php($covered = $transfer->items->where('is_covered', true))
                            <td class="text-center">
                                {{ $covered->count() }} من {{ $transfer->items->count() }}
                                @if ($covered->count() < $transfer->items->count())
                                    <span class="d-block text-warning" style="font-size: .7rem;">بعضها التُزم بفائضه لمناقلة أخرى</span>
                                @endif
                            </td>
                            <td class="text-center fw-bold">{{ $covered->sum('quantity') }}</td>
                            <td class="text-center">
                                <div class="d-flex gap-1 justify-content-center flex-wrap">
                                    <a href="{{ route('inventory.pharmacy-transfer.show', $transfer->id) }}"
                                        class="btn btn-outline-secondary btn-sm rounded-2 px-2 py-1" title="عرض التفاصيل">
                                        <i class="fa-solid fa-eye fa-xs"></i>
                                    </a>
                                    <a href="{{ route('inventory.pharmacy-transfer.excel', ['id' => $transfer->id]) }}"
                                        class="btn btn-outline-success btn-sm rounded-2 px-2 py-1" title="تصدير Excel">
                                        <i class="fa-solid fa-file-excel fa-xs"></i>
                                    </a>
                                    <form method="POST" action="{{ route('inventory.pharmacy-transfer.approve', $transfer->id) }}"
                                        onsubmit="return confirm('تنفيذ هذه المناقلة وحجز فائض الصيدلية المرسِلة؟');">
                                        @csrf
                                        <button type="submit" class="btn btn-success btn-sm rounded-2 px-2 py-1" title="تنفيذ">
                                            <i class="fa-solid fa-check fa-xs"></i> تنفيذ
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('inventory.pharmacy-transfer.dismiss', $transfer->id) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-outline-danger btn-sm rounded-2 px-2 py-1" title="رفض">
                                            <i class="fa-solid fa-xmark fa-xs"></i> رفض
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="5" icon="fa-check-double" message="لا توجد فرص مناقلة مباشرة مقترَحة حالياً — اضغط «تحديث الاقتراحات الآن» لإعادة الفحص." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- 📜 قرارات سابقة --}}
    <div class="border rounded-2 overflow-hidden shadow-sm bg-white">
        <div class="p-2.5 border-bottom bg-light fw-bold small">
            <i class="fa-solid fa-clock-rotate-left text-muted me-1"></i> آخر القرارات
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead>
                    <tr>
                        <th>من صيدلية</th>
                        <th>إلى صيدلية</th>
                        <th>الحالة</th>
                        <th>تاريخ التنفيذ</th>
                        <th class="text-center">المستند</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recentDecisions as $transfer)
                        <tr>
                            <td>{{ $transfer->fromPharmacy->name }}</td>
                            <td>{{ $transfer->toPharmacy->name }}</td>
                            <td>{{ $transfer->status->label() }}</td>
                            <td>
                                {{ $transfer->executed_date?->format('Y-m-d') ?? '—' }}
                                @if ($transfer->executor)
                                    <span class="d-block text-muted" style="font-size: .7rem;">{{ $transfer->executor->name }}</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <a href="{{ route('inventory.pharmacy-transfer.show', $transfer->id) }}"
                                    class="btn btn-outline-secondary btn-sm rounded-2 px-2 py-1" title="عرض">
                                    <i class="fa-solid fa-file-lines fa-xs"></i>
                                </a>
                                <a href="{{ route('inventory.pharmacy-transfer.excel', ['id' => $transfer->id]) }}"
                                    class="btn btn-outline-success btn-sm rounded-2 px-2 py-1" title="تصدير Excel">
                                    <i class="fa-solid fa-file-excel fa-xs"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="5" icon="fa-folder-open" message="لا توجد قرارات سابقة بعد." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

@endsection
