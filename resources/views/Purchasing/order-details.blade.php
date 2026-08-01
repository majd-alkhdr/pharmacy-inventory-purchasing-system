@extends('layouts.admin')

@section('page_title', 'فاتورة شراء مقترحة #' . $order->id)
@section('page_subtitle', 'المشتريات — ' . $order->supplier->name)

@push('styles')
<style>
    .budget-bar-track {
        height: 10px; border-radius: 999px; background: var(--c-bg);
        border: 1px solid var(--c-border); overflow: hidden;
    }
    .budget-bar-fill { height: 100%; background: var(--c-primary); transition: width .3s ease; }
    .budget-bar-fill.over-budget { background: var(--c-danger); }

    .why-toggle {
        background: none; border: 1px solid var(--c-border); color: var(--c-secondary);
        border-radius: 6px; padding: 2px 8px; font-size: 0.72rem;
    }
    .why-toggle:hover { background: var(--c-primary-soft); color: var(--c-primary); border-color: var(--c-primary); }

    .why-panel {
        display: none; background: var(--c-bg); border-top: 1px dashed var(--c-border);
    }
    .why-panel.is-open { display: table-row; }

    .priority-badge { font-size: 0.68rem; padding: 2px 8px; border-radius: 999px; font-weight: 600; }
</style>
@endpush

@section('topbar_actions')
    <a href="{{ route('purchasing.orders.index') }}" class="btn btn-outline-secondary btn-sm rounded-2">
        <i class="fa-solid fa-arrow-right fa-xs me-1"></i> كل الفواتير
    </a>
@endsection

@section('content')
<div class="surface-card p-4 shadow-sm">

    {{-- ═══ ترويسة الفاتورة ═══ --}}
    <div class="row mb-4 pb-3 border-bottom align-items-center">
        <div class="col-md-8">
            <h5 class="fw-bold text-dark mb-2">
                <i class="fa-solid fa-file-invoice text-primary me-1"></i> فاتورة شراء مقترحة #{{ $order->id }}
            </h5>
            <div class="d-flex gap-2 flex-wrap">
                <span class="badge px-2.5 py-1 rounded-2" style="background: var(--c-bg); border: 1px solid var(--c-border); color: var(--c-secondary);">
                    {{ $order->status->label() }}
                </span>
                <span class="badge badge-soft-primary px-2.5 py-1 rounded-2">{{ $order->scenario->label() }}</span>
                <span class="badge px-2.5 py-1 rounded-2" style="background: var(--c-bg); border: 1px solid var(--c-border); color: var(--c-secondary);">
                    <i class="fa-regular fa-calendar fa-xs me-1"></i> تنبؤ {{ $order->forecast_period_days }} يوماً
                </span>
            </div>
        </div>
        <div class="col-md-4 text-md-end mt-3 mt-md-0">
            <span class="text-muted small d-block mb-1">المورد</span>
            <h6 class="fw-bold text-primary mb-0"><i class="fa-solid fa-truck-field fa-xs me-1"></i> {{ $order->supplier->name }}</h6>
        </div>
    </div>

    {{-- ═══ شريط استهلاك الميزانية ═══ --}}
    @if ($order->budget_cap_amount)
        <div class="mb-4 p-3 rounded-2" style="background: var(--c-bg); border: 1px solid var(--c-border);">
            <div class="d-flex justify-content-between align-items-center mb-1.5">
                <span class="small fw-bold text-dark">
                    استهلاك الميزانية
                    <span class="text-muted fw-normal">({{ $order->budget_cap_source === 'monthly' ? 'الحد الشهري العام' : 'تحديد يدوي لهذه الفاتورة' }})</span>
                </span>
                <span class="small fw-bold {{ $order->isOverBudget() ? 'text-danger' : 'text-dark' }}">
                    {{ number_format($order->total_estimated_cost, 2) }} / {{ number_format($order->budget_cap_amount, 2) }}
                </span>
            </div>
            <div class="budget-bar-track">
                <div class="budget-bar-fill {{ $order->isOverBudget() ? 'over-budget' : '' }}" style="width: {{ min(100, $order->budgetUsagePercent() ?? 0) }}%;"></div>
            </div>
            @if ($order->isOverBudget())
                <div class="small text-danger mt-1.5"><i class="fa-solid fa-triangle-exclamation fa-xs me-1"></i> تجاوزت هذه الفاتورة السقف المحدَّد — راجع الأسطر المُعلَّمة "استثناء قنص سوق" أدناه لمعرفة السبب.</div>
            @endif
        </div>
    @endif

    {{-- ═══ جدول الأصناف + لوحة التبرير ═══ --}}
    <div class="border rounded-2 overflow-hidden shadow-sm bg-white mb-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead>
                    <tr>
                        <th style="width: 24%">المادة</th>
                        <th class="text-center" style="width: 9%">الكمية المقترحة</th>
                        <th class="text-center" style="width: 9%">بونص</th>
                        <th class="text-center" style="width: 9%">سعر الوحدة</th>
                        <th class="text-center" style="width: 10%">الإجمالي</th>
                        <th style="width: 13%">الأولوية</th>
                        <th class="text-center" style="width: 8%">التبرير</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($order->items as $item)
                        <tr>
                            <td>
                                <span class="d-block text-dark fw-bold">{{ $item->product->name }}</span>
                                <span class="text-muted" style="font-size: 0.72rem;">{{ $item->product->company }}</span>
                                @if (!empty($item->explanation_factors['cross_supplier_overlap_warning'] ?? null))
                                    <span class="d-block text-warning-emphasis mt-1" style="font-size: 0.68rem;"><i class="fa-solid fa-triangle-exclamation"></i> تداخل مع مورد آخر</span>
                                @endif
                            </td>
                            <td class="text-center fw-bold text-dark">{{ $item->suggested_qty }}</td>
                            <td class="text-center">
                                @if ($item->bonus_qty > 0)
                                    <span class="badge bg-success-subtle text-success px-2 py-1 rounded-2">+{{ $item->bonus_qty }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-center text-secondary">{{ number_format($item->unit_cost, 2) }}</td>
                            <td class="text-center fw-bold text-dark">{{ number_format($item->line_total_cost, 2) }}</td>
                            <td>
                                @if ($item->is_market_shortage)
                                    <span class="priority-badge" style="background:#fef2f2; color:#b91c1c;">قنص نواقص سوق</span>
                                @elseif ($item->is_target_item)
                                    <span class="priority-badge" style="background:#fffbeb; color:#b45309;">صنف تارجت</span>
                                @elseif (($item->explanation_factors['is_high_turnover'] ?? false))
                                    {{-- 🆕 شارة الدوران العالي — إعادة هيكلة المشتريات 2026-08 --}}
                                    <span class="priority-badge" style="background:#f0fdf4; color:#166534;">دوران عالٍ 🚀</span>
                                @elseif ($item->priority_rank && $item->priority_rank <= 3)
                                    <span class="priority-badge" style="background:var(--c-primary-soft); color:var(--c-primary-dark);">أولوية عالية</span>
                                @else
                                    <span class="priority-badge" style="background:var(--c-bg); color:var(--c-secondary); border:1px solid var(--c-border);">عادي</span>
                                @endif
                                @if ($item->is_over_budget_exception)
                                    <span class="priority-badge d-block mt-1" style="background:#fef2f2; color:#b91c1c;">تجاوز الميزانية (استثناء)</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <button type="button" class="why-toggle" onclick="toggleWhy({{ $item->id }})">
                                    <i class="fa-regular fa-circle-question"></i> لماذا؟
                                </button>
                            </td>
                        </tr>
                        <tr id="why-row-{{ $item->id }}" class="why-panel">
                            <td colspan="7" class="p-3">
                                <div class="d-flex gap-2">
                                    <i class="fa-solid fa-lightbulb text-warning mt-1"></i>
                                    <div>
                                        <p class="mb-1 text-secondary" style="line-height: 1.8;">{{ $item->explanation_summary }}</p>
                                        @if (isset($item->explanation_factors['margin_per_unit']) && $item->explanation_factors['margin_per_unit'] !== null)
                                            <span class="badge bg-success-subtle text-success px-2 py-1 rounded-2">
                                                الهامش الصافي المتوقَّع للوحدة: {{ number_format($item->explanation_factors['margin_per_unit'], 2) }}
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="7" icon="fa-inbox" message="لا توجد أصناف بهذه الفاتورة." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ═══ اعتماد / رفض المسودة ═══ --}}
    @if ($order->isEditable())
        <div class="row g-3">
            <div class="col-md-8">
                <form action="{{ route('purchasing.orders.approve', $order->id) }}" method="POST" class="p-3 rounded-2 h-100" style="background: var(--c-bg); border: 1px solid var(--c-border);">
                    @csrf
                    <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-circle-check text-success me-1"></i> اعتماد هذه الفاتورة</h6>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-5">
                            <label class="form-label small fw-bold text-secondary mb-1">اسم المعتمِد</label>
                            <input type="text" name="approved_by" required class="form-control form-control-sm" placeholder="اكتب اسمك">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-bold text-secondary mb-1">ملاحظات (اختياري)</label>
                            <input type="text" name="notes" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-success btn-sm w-100 fw-medium rounded-2">
                                <i class="fa-solid fa-check me-1"></i> اعتماد
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            <div class="col-md-4">
                <form action="{{ route('purchasing.orders.reject', $order->id) }}" method="POST" class="p-3 rounded-2 h-100 d-flex flex-column justify-content-between" style="background: #fef2f2; border: 1px solid #fecaca;" onsubmit="return confirm('رفض هذه المسودة نهائياً؟ يمكنك توليد مسودة جديدة لهذا المورد بعدها مباشرة.')">
                    @csrf
                    <div>
                        <h6 class="fw-bold text-danger mb-2"><i class="fa-solid fa-ban me-1"></i> رفض المسودة</h6>
                        <input type="text" name="reason" class="form-control form-control-sm mb-2" placeholder="سبب الرفض (اختياري)">
                    </div>
                    <button type="submit" class="btn btn-outline-danger btn-sm w-100 fw-medium rounded-2">
                        <i class="fa-solid fa-xmark me-1"></i> رفض وحذف هذه المسودة
                    </button>
                </form>
            </div>
        </div>
    @endif

    @if ($order->status->canBeFullyCancelled() && !$order->isEditable())
        <form action="{{ route('purchasing.orders.cancel', $order->id) }}" method="POST" class="p-3 rounded-2 mt-3" style="background: #fef2f2; border: 1px solid #fecaca;" onsubmit="return confirm('إلغاء هذه الفاتورة بالكامل؟ لم يصل منها أي شيء بعد.')">
            @csrf
            <div class="row g-2 align-items-end">
                <div class="col-md-9">
                    <label class="form-label small fw-bold text-danger mb-1"><i class="fa-solid fa-ban me-1"></i> إلغاء الفاتورة بالكامل</label>
                    <input type="text" name="reason" class="form-control form-control-sm" placeholder="سبب الإلغاء (تغيّر السعر، تغيّر خطة الشراء...الخ)">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-outline-danger btn-sm w-100 fw-medium rounded-2">إلغاء كامل</button>
                </div>
            </div>
        </form>
    @endif

    @if ($order->status->canCancelRemaining())
        <form action="{{ route('purchasing.orders.cancel-remaining', $order->id) }}" method="POST" class="p-3 rounded-2 mt-3" style="background: #fffbeb; border: 1px solid #fde68a;" onsubmit="return confirm('إلغاء الكمية المتبقية غير المستلَمة؟ سيتم تقييمها تلقائياً ضمن قنص نواقص السوق إن انطبق.')">
            @csrf
            <div class="row g-2 align-items-end">
                <div class="col-md-9">
                    <label class="form-label small fw-bold mb-1" style="color:#b45309;"><i class="fa-solid fa-ban me-1"></i> إلغاء الكمية المتبقية غير المستلَمة</label>
                    <input type="text" name="reason" class="form-control form-control-sm" placeholder="سبب إلغاء المتبقي (المورد لن يوفّر الباقي...الخ)">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-warning btn-sm w-100 fw-medium rounded-2 text-dark">إلغاء المتبقي</button>
                </div>
            </div>
        </form>
    @endif
</div>

<script>
    function toggleWhy(itemId) {
        document.getElementById('why-row-' + itemId)?.classList.toggle('is-open');
    }
</script>
@endsection
