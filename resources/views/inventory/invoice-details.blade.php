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

    .redistribution-notice {
        color: #6d28d9;
        background-color: #f5f3ff;
        border: 1px solid #ede9fe;
        padding: 2px 8px;
        border-radius: 4px;
        display: inline-block;
    }
</style>
@endpush

@section('topbar_actions')
    {{-- 🆕 (ب.3) مطابقة مع التحضير الفعلي — خطوة مستقلة بعد التحضير المادي --}}
    <a href="{{ route('inventory.invoice.reconciliation', $invoice->id) }}"
        class="btn btn-outline-primary btn-sm rounded-2 fw-medium text-nowrap no-print">
        <i class="fa-solid fa-scale-balanced me-1"></i> مطابقة التحضير
    </a>
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

@push('styles')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <style>.select2-container { width: 100% !important; }</style>
@endpush

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        // 🆕 (ب.2) سطر إضافة مادة: بحث select2 بنفس نقطة البحث المستخدمة بشاشات المطابقة.
        let addRowIndex = 0;
        function addInvoiceRow() {
            const i = addRowIndex++;
            const row = document.createElement('div');
            row.className = 'row g-2 mb-2 align-items-center';
            row.innerHTML = `
                <div class="col-md-8"><select name="add_items[${i}][product_id]" class="form-select form-select-sm add-product-select"></select></div>
                <div class="col-md-3"><input type="number" min="0" step="any" name="add_items[${i}][quantity]" class="form-control form-control-sm" placeholder="الكمية"></div>
                <div class="col-md-1"><button type="button" class="btn btn-link text-danger p-0" onclick="this.closest('.row').remove()"><i class="fa-solid fa-xmark"></i></button></div>`;
            document.getElementById('addItemsContainer').appendChild(row);
            $(row).find('.add-product-select').select2({
                placeholder: 'ابحث عن المادة...', minimumInputLength: 1,
                ajax: { url: '{{ route('api.products.search') }}', dataType: 'json', delay: 300, data: p => ({ q: p.term }), processResults: d => d },
                language: { inputTooShort: () => 'اكتب حرفاً للبحث...' },
            });
        }
    </script>
@endpush

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

        {{-- 🆕 (ب.2) وضع التعديل قبل التصدير --}}
        @if ($invoice->isEditable())
            <div class="alert alert-warning small no-print mb-3">
                <i class="fa-solid fa-pen-to-square me-1"></i>
                الفاتورة <strong>لم تُصدَّر بعد</strong> — يمكنك تعديل الكميات أو حذف/إضافة مواد ثم "حفظ التعديلات".
                يُصدَّر فقط الشكل النهائي، وأول تصدير يقفل التعديل. كل تغيير يُسجَّل (المقترح ↔ النهائي) بسجل النشاط،
                ويُعاد الفرق للمستودع المركزي أو يُخصم منه.
            </div>
        @else
            <div class="alert alert-light border small no-print mb-3">
                <i class="fa-solid fa-lock me-1"></i> صُدِّرت بتاريخ {{ $invoice->exported_at->format('Y-m-d H:i') }} — مقفلة للتعديل.
            </div>
        @endif

        <form method="POST" action="{{ route('inventory.invoice.items.update', $invoice->id) }}" id="invoiceEditForm">
            @csrf
            @method('PUT')

        {{-- 📊 جدول عرض مواد المستند --}}
        <div class="border rounded-2 overflow-hidden shadow-sm bg-white mb-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead>
                        <tr>
                            <th style="width: 5%" class="text-center">#</th>
                            <th style="width: 40%">اسم المادة الطبية الكامل</th>
                            <th style="width: 17%">الشركة المصنعة</th>
                            <th style="width: 12%" class="text-center">الكمية المطلوبة</th>
                            <th style="width: 10%" class="text-center no-print">مقترح النظام</th>
                            <th style="width: 12%"
                                class="text-center table-success text-success fw-bold text-nowrap">الكمية المشحونة
                            </th>
                            @if ($invoice->isEditable())
                                <th class="text-center no-print" style="width: 4%">حذف</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($items as $index => $item)
                            <tr id="invoice-item-{{ $item->id }}">
                                <td class="text-center text-muted fw-semibold">{{ $index + 1 }}</td>
                                <td>
                                    <span class="d-block text-dark fw-bold mb-0.5">{{ $item->product->name }}</span>
                                    @if ($item->shortage_qty > 0)
                                        <div class="shortage-notice small mt-1">
                                            <i class="fa-solid fa-circle-exclamation me-0.5"></i> عجز متبقٍ:
                                            <strong>{{ $item->shortage_qty }}</strong> علبة
                                        </div>
                                    @endif
                                    @if ($item->is_redistributed)
                                        <div class="redistribution-notice small mt-1">
                                            <i class="fa-solid fa-shuffle me-0.5"></i> شُحن عبر إعادة توزيع مباشرة من:
                                            <strong>{{ implode('، ', $item->redistribution_sources ?? []) }}</strong>
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
                                <td class="text-center no-print {{ $item->wasEdited() ? 'text-warning fw-bold' : 'text-muted' }}">
                                    {{ $item->systemQuantity() }}
                                    @if ($item->is_manually_added)
                                        <span class="d-block" style="font-size: .65rem;">مضافة يدوياً</span>
                                    @endif
                                </td>
                                <td class="text-center table-success text-success fw-bold fs-6">
                                    @if ($invoice->isEditable())
                                        <input type="number" min="0" step="any" name="items[{{ $item->id }}][supplied_qty]"
                                            value="{{ old("items.{$item->id}.supplied_qty", $item->supplied_qty) }}"
                                            class="form-control form-control-sm text-center mx-auto no-print" style="width: 90px;">
                                        <span class="d-none d-print-inline">{{ $item->supplied_qty }}</span>
                                    @else
                                        {{ $item->supplied_qty }}
                                    @endif
                                </td>
                                @if ($invoice->isEditable())
                                    <td class="text-center no-print">
                                        <input type="checkbox" class="form-check-input" name="remove_items[]" value="{{ $item->id }}"
                                            onchange="document.getElementById('invoice-item-{{ $item->id }}').style.opacity = this.checked ? .4 : 1"
                                            title="حذف هذه المادة من الفاتورة">
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <x-empty-state colspan="7" icon="fa-folder-open" message="لا توجد أصناف تطابق الفلترة المختارة داخل هذه المناقلة." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($invoice->isEditable())
            <div class="border rounded-2 p-3 mb-3 no-print" style="background: var(--c-bg);">
                <div class="small fw-bold text-dark mb-2"><i class="fa-solid fa-plus me-1 text-primary"></i> إضافة مواد للفاتورة</div>
                <div id="addItemsContainer"></div>
                <button type="button" class="btn btn-outline-primary btn-sm rounded-2" onclick="addInvoiceRow()">
                    <i class="fa-solid fa-plus fa-xs me-1"></i> سطر إضافة
                </button>
            </div>
            <button type="submit" class="btn btn-outline-primary w-100 mb-4 rounded-2 fw-medium no-print">
                <i class="fa-solid fa-floppy-disk me-1"></i> حفظ التعديلات
            </button>
        @endif
        </form>

        {{-- 🆕 (ب.2) سجل التعديلات: المقترح من النظام ↔ النهائي، من، متى --}}
        @if ($editHistory->isNotEmpty())
            <div class="border rounded-2 overflow-hidden bg-white mb-4 no-print">
                <div class="p-2.5 border-bottom bg-light fw-bold small"><i class="fa-solid fa-clock-rotate-left me-1 text-muted"></i> سجل تعديلات هذه الفاتورة</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0 small">
                        <thead><tr><th>الوقت</th><th>المستخدم</th><th>الإجراء</th><th>المادة</th><th class="text-center">مقترح النظام</th><th class="text-center">قبل</th><th class="text-center">بعد</th></tr></thead>
                        <tbody>
                            @foreach ($editHistory as $log)
                                <tr>
                                    <td class="text-nowrap">{{ $log->created_at->format('Y-m-d H:i') }}</td>
                                    <td>{{ $log->user?->name ?? '—' }}</td>
                                    <td>{{ $log->action->label() }}</td>
                                    <td>{{ $log->context['product_name'] ?? ('#' . ($log->context['product_id'] ?? '')) }}</td>
                                    <td class="text-center">{{ $log->context['system_suggested_qty'] ?? '—' }}</td>
                                    <td class="text-center">{{ $log->old_values['supplied_qty'] ?? '—' }}</td>
                                    <td class="text-center">{{ $log->new_values['supplied_qty'] ?? 'محذوفة' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

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
