@extends('layouts.admin')

@section('page_title', 'مطابقة الاستلام — فاتورة #' . $order->id)
@section('page_subtitle', 'المشتريات — ' . $order->supplier->name)

@section('content')
<div class="surface-card p-4 shadow-sm">

    <form action="{{ route('purchasing.receiving.finalize') }}" method="POST">
        @csrf
        <input type="hidden" name="session_id" value="{{ $sessionId }}">

        {{-- ═══ الصفوف المُطابَقة تلقائياً (عرض فقط) ═══ --}}
        @if ($matched->isNotEmpty())
            <h6 class="fw-bold text-dark mb-2 small"><i class="fa-solid fa-circle-check text-success me-1"></i> مُطابَقة تلقائياً ({{ $matched->count() }})</h6>
            <div class="border rounded-2 overflow-hidden shadow-sm bg-white mb-4">
                <table class="table table-sm mb-0 small">
                    <thead><tr><th>الباركود</th><th>المادة بالملف</th><th class="text-center">الكمية المستلمة</th><th>تاريخ الصلاحية</th></tr></thead>
                    <tbody>
                        @foreach ($matched as $row)
                            <tr class="table-light">
                                <td>{{ $row->barcode }}</td>
                                <td>{{ $row->material_name }}</td>
                                <td class="text-center fw-bold">{{ $row->received_qty }}</td>
                                <td>{{ $row->expiry_date?->format('Y-m-d') ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- ═══ الصفوف غير المُطابَقة (تحتاج توجيهاً يدوياً) ═══ --}}
        @if ($unmatched->isNotEmpty())
            <h6 class="fw-bold text-dark mb-2 small"><i class="fa-solid fa-triangle-exclamation text-warning me-1"></i> تحتاج توجيهاً يدوياً ({{ $unmatched->count() }})</h6>
            <div class="border rounded-2 overflow-hidden shadow-sm bg-white mb-4">
                <table class="table table-sm mb-0 small">
                    <thead><tr><th>الباركود</th><th>المادة بالملف</th><th class="text-center">الكمية</th><th style="width:35%">توجيه هذا الصف إلى</th></tr></thead>
                    <tbody>
                        @foreach ($unmatched as $row)
                            <tr>
                                <td>{{ $row->barcode ?: '—' }}</td>
                                <td>{{ $row->material_name }} <span class="text-muted small">({{ $row->company }})</span></td>
                                <td class="text-center fw-bold">{{ $row->received_qty }}</td>
                                <td>
                                    <select name="mappings[{{ $row->id }}]" class="form-select form-select-sm">
                                        <option value="skip">— تجاهل (ليست ضمن هذه الفاتورة) —</option>
                                        @foreach ($order->items as $item)
                                            <option value="{{ $item->id }}">{{ $item->product->name }} (مطلوب: {{ $item->finalQuantity() }})</option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- ═══ هل هذه آخر شحنة؟ ═══ --}}
        <div class="p-3 rounded-2 mb-4" style="background: var(--c-bg); border: 1px solid var(--c-border);">
            <div class="form-check">
                <input type="checkbox" name="order_is_finalized" value="1" class="form-check-input" id="isFinalized" checked>
                <label class="form-check-label fw-bold small" for="isFinalized">هذه آخر شحنة متوقعة لهذه الفاتورة (إغلاق نهائي)</label>
            </div>
            <div class="form-text small mt-1">
                إن تركتها مفعَّلة: أي عجز متبقٍّ بعد هذا الاستلام سيُعلَّم فوراً كـ"مقطوع من السوق". لو كنت تتوقع شحنة إضافية لاحقاً لنفس الفاتورة، ألغِ التحديد.
            </div>
        </div>

        <button type="submit" class="btn btn-success w-100 py-2 fw-medium rounded-2">
            <i class="fa-solid fa-check-double me-1"></i> تأكيد الاستلام وتحديث المخزون
        </button>
    </form>
</div>
@endsection
