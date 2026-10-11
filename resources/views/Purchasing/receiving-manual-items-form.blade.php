@extends('layouts.admin')

@section('page_title', 'تدقيق استلام فاتورة #' . $order->id)
@section('page_subtitle', 'المشتريات — ' . $order->supplier->name)

@push('styles')
    <style>
        /* 🩹 [2026-09] كانت background:#fff ثابتة بينما النص بداخل كل صف
           (.text-dark وغيره) يتبدّل حسب الثيم — يختفي بالوضع الداكن فوق
           هذه الخلفية البيضاء الثابتة. */
        .item-row {
            border: 1px solid var(--c-border);
            border-radius: 10px;
            padding: 14px 16px;
            margin-bottom: 10px;
            background: var(--c-surface);
        }

        .status-choice {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid var(--c-border);
            border-radius: 8px;
            padding: 6px 14px;
            cursor: pointer;
            font-size: 0.82rem;
            font-weight: 600;
            transition: all .12s ease;
            margin-inline-end: 6px;
        }

        .status-choice input {
            display: none;
        }

        .status-choice.choice-full.is-active {
            background: #f0fdf4;
            border-color: #16a34a;
            color: #166534;
        }

        .status-choice.choice-partial.is-active {
            background: #fffbeb;
            border-color: #f59e0b;
            color: #b45309;
        }

        .status-choice.choice-none.is-active {
            background: #fef2f2;
            border-color: #ef4444;
            color: #b91c1c;
        }

        .partial-qty-input {
            display: none;
            max-width: 140px;
        }

        .partial-qty-input.is-visible {
            display: inline-block;
        }
    </style>
@endpush

@section('topbar_actions')
    <a href="{{ route('purchasing.receiving.manual.select') }}" class="btn btn-outline-secondary btn-sm rounded-2">
        <i class="fa-solid fa-arrow-right fa-xs me-1"></i> اختيار فاتورة أخرى
    </a>
@endsection

@section('content')
    <div class="surface-card p-4 shadow-sm">

        <div class="mb-4 p-3 rounded-2" style="background: var(--c-bg); border: 1px solid var(--c-border);">
            <div class="d-flex justify-content-between flex-wrap gap-2">
                <div>
                    <span class="text-muted small d-block">المورد</span>
                    <span class="fw-bold text-primary">{{ $order->supplier->name }}</span>
                </div>
                <div>
                    <span class="text-muted small d-block">عدد الأصناف</span>
                    <span class="fw-bold">{{ $order->items->count() }}</span>
                </div>
            </div>
        </div>

        <form action="{{ route('purchasing.receiving.manual.finalize', $order->id) }}" method="POST"
            id="manualReceivingForm">
            @csrf

            @foreach ($order->items as $item)
                @php($finalQty = $item->finalQuantity())
                <div class="item-row">
                    <div class="row g-3 align-items-center">
                        <div class="col-md-4">
                            <span class="d-block fw-bold text-dark">{{ $item->product->name }}</span>
                            <span class="text-muted small">{{ $item->product->company }} —
                                {{ $item->product->barcode }}</span>
                            <span class="d-block text-secondary small mt-1">الكمية المطلوبة:
                                <strong>{{ $finalQty }}</strong></span>
                        </div>

                        <div class="col-md-5">
                            <label class="status-choice choice-full">
                                <input type="radio" name="items[{{ $item->id }}][status]" value="full"
                                    data-item="{{ $item->id }}" checked>
                                <i class="fa-solid fa-circle-check"></i> استلام كامل
                            </label>
                            <label class="status-choice choice-partial">
                                <input type="radio" name="items[{{ $item->id }}][status]" value="partial"
                                    data-item="{{ $item->id }}">
                                <i class="fa-solid fa-circle-half-stroke"></i> استلام جزئي
                            </label>
                            <label class="status-choice choice-none">
                                <input type="radio" name="items[{{ $item->id }}][status]" value="none"
                                    data-item="{{ $item->id }}">
                                <i class="fa-solid fa-circle-xmark"></i> لم يُستلَم
                            </label>

                            <input type="number" min="0" max="{{ $finalQty }}"
                                name="items[{{ $item->id }}][received_qty]" id="partialQty_{{ $item->id }}"
                                class="form-control form-control-sm partial-qty-input mt-2"
                                placeholder="الكمية المستلَمة فعلياً">
                        </div>

                        <div class="col-md-3">
                            <input type="text" name="items[{{ $item->id }}][note]"
                                class="form-control form-control-sm" placeholder="ملاحظة (اختياري)">
                        </div>
                    </div>
                </div>
            @endforeach

            <div class="alert alert-light border small text-secondary mt-3">
                <i class="fa-solid fa-circle-info text-primary me-1"></i>
                هذا الإجراء نهائي — أي كمية "جزئية" أو "لم تُستلَم" تُعتبر ملغاة فوراً وتعود تلقائياً ضمن الاحتياج المحسوب
                عند توليد أي فاتورة شراء قادمة لنفس المادة.
            </div>

            <button type="submit" class="btn btn-brand w-100 py-2 fw-medium rounded-2 mt-2">
                <i class="fa-solid fa-check-double me-1"></i> تأكيد الاستلام وتحديث الفاتورة
            </button>
        </form>
    </div>

    <script>
        document.querySelectorAll('input[type="radio"][data-item]').forEach(radio => {
            radio.addEventListener('change', function() {
                const itemId = this.dataset.item;
                const qtyInput = document.getElementById('partialQty_' + itemId);
                const choices = document.querySelectorAll(`input[data-item="${itemId}"]`);

                choices.forEach(r => r.closest('.status-choice').classList.remove('is-active'));
                this.closest('.status-choice').classList.add('is-active');

                if (this.value === 'partial') {
                    qtyInput.classList.add('is-visible');
                    qtyInput.setAttribute('required', 'required');
                } else {
                    qtyInput.classList.remove('is-visible');
                    qtyInput.removeAttribute('required');
                }
            });
        });

        // تفعيل الحالة الافتراضية (استلام كامل) بصرياً عند تحميل الصفحة
        document.querySelectorAll('input[value="full"][data-item]').forEach(r => {
            r.closest('.status-choice').classList.add('is-active');
        });
    </script>
@endsection
