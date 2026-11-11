@extends('layouts.admin')

@section('page_title', 'مطابقة تحضير المناقلة #' . $invoice->id)
@section('page_subtitle', 'العمليات (المناقلات) — ' . $invoice->pharmacy->name)

@section('topbar_actions')
    <a href="{{ route('inventory.invoice.show', $invoice->id) }}" class="btn btn-outline-secondary btn-sm rounded-2 text-nowrap">
        الفاتورة <i class="fa-solid fa-arrow-left fa-xs ms-1"></i>
    </a>
@endsection

@section('content')
    <div class="surface-card p-4 shadow-sm mb-3">
        <div class="alert alert-light border small text-secondary mb-3">
            <i class="fa-solid fa-circle-info text-primary me-1"></i>
            خطوة <strong>بعد</strong> التحضير الفعلي بالمستودع: نزّل القالب (مواد الفاتورة جاهزة)، اكتب الكمية المحضّرة فعلاً
            لكل مادة، ثم ارفعه. تظهر الفروقات أدناه لتحديد سبب كل منها. اختيار سبب من فئة "رصيد وهمي" يحيل المادة تلقائياً
            لشاشة <a href="{{ route('inventory.finance-settlements.index') }}">مواد تحتاج تسوية من المالية</a>.
        </div>

        <form method="POST" action="{{ route('inventory.invoice.reconciliation.upload', $invoice->id) }}" enctype="multipart/form-data" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-7">
                <label class="small fw-bold text-secondary mb-1">ملف التحضير (xlsx / csv): اسم المادة ← الكمية المحضّرة</label>
                <input type="file" name="preparation_file" accept=".xlsx,.csv" required class="form-control form-control-sm @error('preparation_file') is-invalid @enderror">
                @error('preparation_file')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-5 d-flex gap-2">
                <a href="{{ route('inventory.invoice.reconciliation.template', $invoice->id) }}" class="btn btn-outline-success btn-sm rounded-2 w-50">
                    <i class="fa-solid fa-file-excel me-1"></i> تنزيل القالب
                </a>
                <button class="btn btn-brand btn-sm rounded-2 w-50"><i class="fa-solid fa-upload me-1"></i> رفع ومطابقة</button>
            </div>
        </form>
    </div>

    @if ($reconciliation)
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 small text-muted mb-2">
            <span>
                آخر مطابقة: {{ $reconciliation->created_at->format('Y-m-d H:i') }} — {{ $reconciliation->user?->name ?? '—' }}
                — {{ $reconciliation->items_count }} مادة، <strong class="text-danger">{{ $reconciliation->mismatched_count }}</strong> بفرق
                @if ($previousCount > 0) (و{{ $previousCount }} مطابقة سابقة محفوظة) @endif
            </span>
            <div class="d-flex flex-wrap align-items-center gap-2">
                @if ($reconciliation->importLog)
                    <a href="{{ route('import-history.show', $reconciliation->import_log_id) }}">تحذيرات الملف بسجل الرفع ←</a>
                @endif
                @if ($reconciliation->mismatched_count > 0)
                    <a href="{{ route('inventory.invoice.reconciliation.export', $invoice->id) }}" class="btn btn-outline-success btn-sm rounded-2 text-nowrap">
                        <i class="fa-solid fa-file-excel me-1"></i> تصدير الفروقات للمستودع ({{ $reconciliation->mismatched_count }})
                    </a>
                @endif
                <a href="{{ route('inventory.invoice.reconciliation.export', [$invoice->id, 'all' => 1]) }}" class="btn btn-outline-secondary btn-sm rounded-2 text-nowrap">
                    <i class="fa-solid fa-file-excel me-1"></i> تصدير الكل
                </a>
                <span class="text-muted" style="font-size: .7rem;">التصدير لا يتأثر بالبحث والفلاتر أدناه</span>
            </div>
        </div>

        <x-table-toolbar table="reconciliation-items" placeholder="بحث بالاسم أو الباركود..." :filters="[
            ['key' => 'status', 'label' => 'الحالة', 'options' => [
                'mismatched' => 'بفرق', 'matched' => 'مطابق', 'short' => 'نقص بالتحضير', 'over' => 'زيادة بالتحضير',
                'not_in_invoice' => 'غير موجودة بالفاتورة',
            ]],
            ['key' => 'reason', 'label' => 'السبب', 'options' => [
                'none' => 'بلا سبب بعد', 'has_reason' => 'له سبب', 'finance' => 'محالة للمالية',
            ]],
        ]" />

        <div class="border rounded-2 overflow-hidden shadow-sm bg-white">
            <div class="table-responsive">
                {{-- تغيير السبب يعيد تحميل الصفحة: الأداة تستعيد البحث/الفلتر/الفرز وموضع التمرير (data-tt-keep-scroll) --}}
                <table class="table table-hover align-middle mb-0 small" data-tt-table="reconciliation-items" data-tt-scope="{{ $invoice->id }}" data-tt-keep-scroll>
                    <thead>
                        <tr>
                            <th data-tt-sort="name" data-tt-type="text">المادة</th>
                            <th class="text-center" data-tt-sort="invoiced" data-tt-type="num">بالفاتورة</th>
                            <th class="text-center" data-tt-sort="prepared" data-tt-type="num">محضَّر فعلياً</th>
                            <th class="text-center" data-tt-sort="difference" data-tt-type="num">الفرق</th>
                            <th style="width: 32%">سبب الفرق</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($reconciliation->items as $item)
                            @php($status = !$item->hasDifference() ? 'matched' : 'mismatched ' . ($item->invoiced_qty === 0 ? 'not_in_invoice ' : '') . ($item->difference < 0 ? 'short' : 'over'))
                            @php($reason = !$item->hasDifference() ? '' : ($item->discrepancy_reason_id === null ? 'none' : 'has_reason' . ($item->settlement ? ' finance' : '')))
                            <tr class="{{ $item->hasDifference() ? '' : 'text-muted' }}" data-tt-row data-tt-id="{{ $item->id }}"
                                data-tt-search="{{ $item->product->name }} {{ $item->product->barcode }}"
                                data-tt-s-name="{{ $item->product->name }}" data-tt-s-invoiced="{{ $item->invoiced_qty }}"
                                data-tt-s-prepared="{{ $item->prepared_qty }}" data-tt-s-difference="{{ $item->difference }}"
                                data-tt-f-status="{{ $status }}" data-tt-f-reason="{{ $reason }}">
                                <td>
                                    <span class="d-block fw-bold {{ $item->hasDifference() ? 'text-dark' : '' }}">{{ $item->product->name }}</span>
                                    <span style="font-size: .7rem;">{{ $item->product->barcode }}</span>
                                    @if ($item->invoiced_qty === 0)
                                        <span class="d-block text-warning" style="font-size: .7rem;">غير موجودة بالفاتورة الأصلية</span>
                                    @endif
                                </td>
                                <td class="text-center">{{ $item->invoiced_qty }}</td>
                                <td class="text-center">{{ $item->prepared_qty }}</td>
                                <td class="text-center fw-bold {{ $item->difference < 0 ? 'text-danger' : ($item->difference > 0 ? 'text-success' : '') }}">
                                    {{ $item->difference > 0 ? '+' : '' }}{{ $item->difference }}
                                </td>
                                <td>
                                    @if ($item->hasDifference())
                                        <form method="POST" action="{{ route('inventory.reconciliation-item.reason', $item->id) }}" class="d-flex gap-1">
                                            @csrf
                                            <select name="discrepancy_reason_id" class="form-select form-select-sm" onchange="this.form.submit()">
                                                <option value="">— اختر السبب —</option>
                                                @foreach ($reasons as $reason)
                                                    <option value="{{ $reason->id }}" @selected($item->discrepancy_reason_id === $reason->id)>
                                                        {{ $reason->label }}{{ $reason->requires_finance_settlement ? ' 💰' : '' }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </form>
                                        @if ($item->settlement)
                                            <span class="d-block text-primary mt-1" style="font-size: .7rem;">
                                                <i class="fa-solid fa-coins"></i> محالة للمالية ({{ $item->settlement->quantity }})
                                                {{ $item->settlement->exported_at ? '— صُدِّرت ' . $item->settlement->exported_at->format('Y-m-d') : '' }}
                                            </span>
                                        @endif
                                    @else
                                        <span class="small">مطابق ✅</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <x-empty-state colspan="5" icon="fa-folder-open" message="لا بنود بهذه المطابقة." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
