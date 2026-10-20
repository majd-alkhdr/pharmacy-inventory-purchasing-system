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
        <div class="d-flex justify-content-between flex-wrap gap-2 small text-muted mb-2">
            <span>
                آخر مطابقة: {{ $reconciliation->created_at->format('Y-m-d H:i') }} — {{ $reconciliation->user?->name ?? '—' }}
                — {{ $reconciliation->items_count }} مادة، <strong class="text-danger">{{ $reconciliation->mismatched_count }}</strong> بفرق
                @if ($previousCount > 0) (و{{ $previousCount }} مطابقة سابقة محفوظة) @endif
            </span>
            @if ($reconciliation->importLog)
                <a href="{{ route('import-history.show', $reconciliation->import_log_id) }}">تحذيرات الملف بسجل الرفع ←</a>
            @endif
        </div>

        <div class="border rounded-2 overflow-hidden shadow-sm bg-white">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead>
                        <tr>
                            <th>المادة</th>
                            <th class="text-center">بالفاتورة</th>
                            <th class="text-center">محضَّر فعلياً</th>
                            <th class="text-center">الفرق</th>
                            <th style="width: 32%">سبب الفرق</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($reconciliation->items as $item)
                            <tr class="{{ $item->hasDifference() ? '' : 'text-muted' }}">
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
