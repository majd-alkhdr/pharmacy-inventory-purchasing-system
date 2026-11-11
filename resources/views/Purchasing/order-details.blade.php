@extends('layouts.admin')

@section('page_title', 'فاتورة شراء مقترحة #' . $order->id)
@section('page_subtitle', 'المشتريات — ' . $order->supplier->name)

@push('styles')
    <style>
        .budget-bar-track {
            height: 10px;
            border-radius: 999px;
            background: var(--c-bg);
            border: 1px solid var(--c-border);
            overflow: hidden;
        }

        .budget-bar-fill {
            height: 100%;
            background: var(--c-primary);
            transition: width .3s ease;
        }

        .budget-bar-fill.over-budget {
            background: var(--c-danger);
        }

        .why-toggle {
            background: none;
            border: 1px solid var(--c-border);
            color: var(--c-secondary);
            border-radius: 6px;
            padding: 2px 8px;
            font-size: 0.72rem;
        }

        .why-toggle:hover {
            background: var(--c-primary-soft);
            color: var(--c-primary);
            border-color: var(--c-primary);
        }

        .why-panel {
            display: none;
            background: var(--c-bg);
            border-top: 1px dashed var(--c-border);
        }

        .why-panel.is-open {
            display: table-row;
        }

        .priority-badge {
            font-size: 0.68rem;
            padding: 2px 8px;
            border-radius: 999px;
            font-weight: 600;
        }

        .cancelled-notice {
            color: var(--c-danger);
            background-color: #fef2f2;
            border: 1px solid #fee2e2;
            padding: 2px 8px;
            border-radius: 4px;
            display: inline-block;
            font-size: 0.7rem;
            margin-top: 4px;
        }

        /* 🆕 عمود الكمية القابل للتعديل بحالة المسودة */
        .qty-edit-input {
            width: 90px;
            text-align: center;
            margin: 0 auto;
        }

        .remove-line-btn {
            font-size: 0.7rem;
            color: var(--c-danger);
        }

        .edit-mode-banner {
            background: #fffbeb;
            border: 1px solid #fde68a;
            color: #92400e;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 0.82rem;
            margin-bottom: 16px;
        }

        /* 🆕 (ج.3) لوحة "الفروع" — لون الفائض (--c-surplus) لا يُستعمل إلا للفائض. */
        .branch-surplus-badge {
            display: inline-block;
            margin-top: 4px;
            padding: 1px 8px;
            border-radius: 999px;
            border: 1px solid var(--c-surplus);
            background: var(--c-surplus-soft);
            color: var(--c-surplus);
            font-size: 0.68rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .branch-surplus-badge:hover {
            background: var(--c-surplus);
            color: #fff;
        }

        .branch-chip {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 999px;
            border: 1px solid var(--c-border);
            background: var(--c-surface);
            color: var(--c-text-secondary);
            font-size: 0.72rem;
            white-space: nowrap;
        }

        .branch-chip.branch-surplus-chip {
            border-color: var(--c-surplus);
            background: var(--c-surplus-soft);
            color: var(--c-surplus);
            font-weight: 600;
        }

        .branch-stock-scroll {
            max-height: 320px;
            overflow-y: auto;
            border: 1px solid var(--c-border);
            border-radius: 8px;
        }

        .branch-stock-scroll thead th {
            position: sticky;
            top: 0;
            z-index: 1;
            background: var(--c-bg);
        }

        .branch-stock-table td {
            vertical-align: middle;
        }

        .branch-surplus-row > td {
            background: var(--c-surplus-soft) !important;
        }

        .branch-surplus-row > td:first-child {
            border-inline-start: 3px solid var(--c-surplus);
        }

        .branch-surplus-state {
            display: inline-block;
            padding: 1px 8px;
            border-radius: 999px;
            background: var(--c-surplus);
            color: #fff;
            font-size: 0.68rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .branch-reserved-row > td {
            opacity: .6;
        }

        .branch-below-state {
            display: inline-block;
            padding: 1px 8px;
            border-radius: 999px;
            background: var(--c-danger-soft);
            color: var(--c-danger);
            font-size: 0.68rem;
            white-space: nowrap;
        }

        .branch-divider > td {
            padding: 2px 8px !important;
            font-size: 0.68rem;
            color: var(--c-text-faint);
            background: var(--c-bg) !important;
            border-top: 1px solid var(--c-border);
        }

        .branch-legend-swatch {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 2px;
            vertical-align: middle;
            margin-inline-end: 4px;
        }

        .branch-legend-swatch.is-surplus { background: var(--c-surplus); }
        .branch-legend-swatch.is-below { background: var(--c-danger-soft); border: 1px solid var(--c-danger); }
    </style>
@endpush

@section('topbar_actions')
    <a href="{{ route('purchasing.orders.export-excel', $order->id) }}"
        class="btn btn-success btn-sm rounded-2 fw-medium text-nowrap">
        <i class="fa-solid fa-file-excel me-1"></i> تصدير Excel
    </a>
    <a href="{{ route('purchasing.orders.index') }}" class="btn btn-outline-secondary btn-sm rounded-2">
        <i class="fa-solid fa-arrow-right fa-xs me-1"></i> كل الفواتير
    </a>
@endsection

@section('content')
    <div class="surface-card p-4 shadow-sm">

        <div class="row mb-4 pb-3 border-bottom align-items-center">
            <div class="col-md-8">
                <h5 class="fw-bold text-dark mb-2">
                    <i class="fa-solid fa-file-invoice text-primary me-1"></i> فاتورة شراء مقترحة #{{ $order->id }}
                </h5>
                <div class="d-flex gap-2 flex-wrap">
                    <span class="badge px-2.5 py-1 rounded-2"
                        style="background: var(--c-bg); border: 1px solid var(--c-border); color: var(--c-secondary);">
                        {{ $order->status->label() }}
                    </span>
                    <span class="badge badge-soft-primary px-2.5 py-1 rounded-2">{{ $order->scenario->label() }}</span>
                    <span class="badge px-2.5 py-1 rounded-2"
                        style="background: var(--c-bg); border: 1px solid var(--c-border); color: var(--c-secondary);">
                        <i class="fa-regular fa-calendar fa-xs me-1"></i> تنبؤ {{ $order->forecast_period_days }} يوماً
                    </span>
                </div>
            </div>
            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                <span class="text-muted small d-block mb-1">المورد</span>
                <h6 class="fw-bold text-primary mb-0"><i class="fa-solid fa-truck-field fa-xs me-1"></i>
                    {{ $order->supplier->name }}</h6>
            </div>
        </div>

        @if ($order->isEditable())
            <div class="edit-mode-banner">
                <i class="fa-solid fa-pen-to-square me-1"></i>
                هذه الفاتورة بحالة "مسودة" — يمكنك تعديل الكميات المقترحة أو حذف أي صنف قبل الاعتماد. عدِّل ما تريد ثم اضغط
                "حفظ التعديلات"، وعندما تكون راضياً عن المحتوى، اعتمِد الفاتورة من أسفل الصفحة.
            </div>
        @endif

        @if ($order->budget_cap_amount)
            <div class="mb-4 p-3 rounded-2" style="background: var(--c-bg); border: 1px solid var(--c-border);">
                <div class="d-flex justify-content-between align-items-center mb-1.5">
                    <span class="small fw-bold text-dark">
                        استهلاك الميزانية
                        <span
                            class="text-muted fw-normal">({{ $order->budget_cap_source === 'monthly' ? 'الحد الشهري العام' : 'تحديد يدوي لهذه الفاتورة' }})</span>
                    </span>
                    <span class="small fw-bold {{ $order->isOverBudget() ? 'text-danger' : 'text-dark' }}">
                        {{ number_format($order->total_estimated_cost, 2) }} /
                        {{ number_format($order->budget_cap_amount, 2) }}
                    </span>
                </div>
                <div class="budget-bar-track">
                    <div class="budget-bar-fill {{ $order->isOverBudget() ? 'over-budget' : '' }}"
                        style="width: {{ min(100, $order->budgetUsagePercent() ?? 0) }}%;"></div>
                </div>
            </div>
        @endif

        {{-- 🆕 بحث/فرز/فلترة بالمتصفح. المجاميع والميزانية أعلاه تبقى لكل الفاتورة لا للمفلتر. --}}
        <x-table-toolbar table="po-items" :filters="array_filter([
            ['key' => 'priority', 'label' => 'الأولوية', 'options' => [
                'pharmacy_shortage' => 'نقص فعلي بالصيدليات', 'market_shortage' => 'قنص نواقص سوق', 'target' => 'تارجت',
                'high_turnover' => 'دوران عالٍ', 'high_priority' => 'أولوية عالية', 'normal' => 'عادي',
            ]],
            ['key' => 'stock', 'label' => 'الصناعة والفروع', 'options' => [
                'in_industry' => 'متوفر بالصناعة', 'no_industry' => 'غير متوفر بالصناعة', 'branch_surplus' => 'له فائض بالفروع',
            ]],
            $order->isEditable() ? ['key' => 'edit', 'label' => 'التعديل', 'options' => ['edited' => 'معدَّلة عن المقترح']] : null,
        ])" />

        <form action="{{ route('purchasing.orders.update-items', $order->id) }}" method="POST" id="editItemsForm">
            @csrf
            <div class="border rounded-2 overflow-hidden shadow-sm bg-white mb-3">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small" data-tt-table="po-items" data-tt-scope="{{ $order->id }}">
                        <thead>
                            <tr>
                                <th style="width: 22%" data-tt-sort="name" data-tt-type="text">المادة</th>
                                <th class="text-center" style="width: 9%" data-tt-sort="suggested" data-tt-type="num">الكمية المقترحة (من النظام)</th>
                                <th class="text-center" style="width: 12%" data-tt-sort="approved" data-tt-type="num">الكمية المعتمَدة</th>
                                {{-- 🆕 (ج.2) يستبدل عمود "بونص" (غير مستخدم بالشركة) — حُذف من الشاشة لا أُخفي --}}
                                <th class="text-center" style="width: 9%" data-tt-sort="industry" data-tt-type="num">الكمية المتوفرة في الصناعة</th>
                                <th class="text-center" style="width: 8%" data-tt-sort="unit_cost" data-tt-type="num">سعر الوحدة</th>
                                <th class="text-center" style="width: 9%" data-tt-sort="total" data-tt-type="num">الإجمالي</th>
                                <th style="width: 13%" data-tt-sort="priority" data-tt-type="num">الأولوية</th>
                                <th class="text-center" style="width: 6%">التبرير</th>
                                @if ($order->isEditable())
                                    <th class="text-center" style="width: 6%">حذف</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($order->items as $item)
                                @php($inIndustry = $industryStock[$item->product_id] ?? null)
                                @php($branchSurplus = $surplusByProduct[$item->product_id] ?? 0)
                                @php($priorityKey = $item->priorityKey())
                                <tr id="item-row-{{ $item->id }}" data-tt-row data-tt-id="{{ $item->id }}"
                                    data-tt-search="{{ $item->product->name }} {{ $item->product->company }} {{ $item->product->barcode }}"
                                    data-tt-s-name="{{ $item->product->name }}" data-tt-s-suggested="{{ $item->suggested_qty }}"
                                    data-tt-s-approved="{{ $item->finalQuantity() }}" data-tt-s-industry="{{ $inIndustry }}"
                                    data-tt-s-unit_cost="{{ $item->unit_cost }}" data-tt-s-total="{{ $item->line_total_cost }}"
                                    data-tt-s-priority="{{ $item->priorityRank() }}" data-tt-f-priority="{{ $priorityKey }}"
                                    data-tt-f-stock="{{ $inIndustry > 0 ? 'in_industry' : 'no_industry' }}{{ $branchSurplus > 0 ? ' branch_surplus' : '' }}"
                                    data-tt-f-edit="{{ $item->finalQuantity() !== (int) $item->suggested_qty ? 'edited' : '' }}">
                                    <td>
                                        <span class="d-block text-dark fw-bold">{{ $item->product->name }}</span>
                                        <span class="text-muted"
                                            style="font-size: 0.72rem;">{{ $item->product->company }}</span>
                                        @if (!empty($item->explanation_factors['cross_supplier_overlap_warning'] ?? null))
                                            <span class="d-block mt-1"
                                                style="font-size: 0.68rem; color: var(--c-warning);"><i
                                                    class="fa-solid fa-triangle-exclamation"></i> تداخل مع مورد آخر</span>
                                        @endif
                                        @if ($item->hasCancelledQuantity())
                                            <div class="cancelled-notice">
                                                <i class="fa-solid fa-ban me-1"></i> أُلغي منها
                                                <strong>{{ $item->cancelled_qty }}</strong> وحدة
                                                ({{ $order->status->value === 'cancelled' ? 'إلغاء كامل' : 'إلغاء المتبقي بعد استلام جزئي' }})
                                            </div>
                                        @endif
                                    </td>
                                    <td class="text-center text-muted">{{ $item->suggested_qty }}</td>
                                    <td class="text-center">
                                        @if ($order->isEditable())
                                            <input type="number" min="0" step="any"
                                                name="items[{{ $item->id }}][approved_qty]"
                                                value="{{ $item->finalQuantity() }}"
                                                data-tt-live="approved" data-tt-edit-base="{{ $item->suggested_qty }}" data-tt-edit-filter="edit"
                                                class="form-control form-control-sm qty-edit-input">
                                        @else
                                            <span class="fw-bold text-dark">{{ $item->finalQuantity() }}</span>
                                            @if ($item->hasCancelledQuantity())
                                                <span class="d-block text-muted"
                                                    style="font-size: 0.68rem; font-weight: 400;">
                                                    (المستلم فعلياً: {{ $item->received_qty }})
                                                </span>
                                            @endif
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        <span class="fw-bold {{ $inIndustry > 0 ? 'text-success' : 'text-muted' }}">{{ $inIndustry ?? '—' }}</span>
                                        {{-- 🆕 (ج.3) إشارة صغيرة واحدة فقط بالجدول الرئيسي — التفصيل كله داخل لوحة "الفروع". --}}
                                        @if ($branchSurplus > 0)
                                            <button type="button" class="branch-surplus-badge d-block mx-auto" onclick="toggleTransfers({{ $item->id }})"
                                                title="عرض أرصدة الفروع">فائض بالفروع: {{ $branchSurplus }}</button>
                                        @endif
                                    </td>
                                    <td class="text-center text-secondary">{{ number_format($item->unit_cost, 2) }}</td>
                                    <td class="text-center fw-bold text-dark">
                                        {{ number_format($item->line_total_cost, 2) }}</td>
                                    <td>
                                        {{-- مصدر واحد للأسبقية: PurchaseOrderItem::priorityKey() (يستعمله الفلتر والفرز أيضاً) --}}
                                        @switch ($priorityKey)
                                            @case ('market_shortage')
                                                <span class="priority-badge" style="background:#fef2f2; color:#b91c1c;">قنص
                                                    نواقص سوق</span>
                                                @break
                                            @case ('pharmacy_shortage')
                                                <span class="priority-badge" style="background:#fef2f2; color:#b91c1c;">
                                                    ⛑️ نقص فعلي بالصيدليات
                                                    ({{ $item->explanation_factors['pending_shortage_qty'] ?? 0 }})
                                                </span>
                                                @break
                                            @case ('target')
                                                <span class="priority-badge" style="background:#fffbeb; color:#b45309;">صنف
                                                    تارجت</span>
                                                @break
                                            @case ('high_turnover')
                                                <span class="priority-badge" style="background:#f0fdf4; color:#166534;">دوران
                                                    عالٍ 🚀</span>
                                                @break
                                            @case ('high_priority')
                                                <span class="priority-badge"
                                                    style="background:var(--c-primary-soft); color:var(--c-primary-dark);">أولوية
                                                    عالية</span>
                                                @break
                                            @default
                                                <span class="priority-badge"
                                                    style="background:var(--c-bg); color:var(--c-secondary); border:1px solid var(--c-border);">عادي</span>
                                        @endswitch
                                        @if ($item->is_over_budget_exception)
                                            <span class="priority-badge d-block mt-1"
                                                style="background:#fef2f2; color:#b91c1c;">تجاوز الميزانية (استثناء)</span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="why-toggle" onclick="toggleWhy({{ $item->id }})">
                                            <i class="fa-regular fa-circle-question"></i> لماذا؟
                                        </button>
                                        {{-- 🆕 (ج.3) --}}
                                        <button type="button" class="why-toggle mt-1" onclick="toggleTransfers({{ $item->id }})">
                                            <i class="fa-solid fa-store"></i> الفروع
                                        </button>
                                    </td>
                                    @if ($order->isEditable())
                                        <td class="text-center">
                                            <button type="button" class="btn btn-link remove-line-btn p-0"
                                                onclick="markForRemoval({{ $item->id }})"
                                                title="حذف هذا الصنف من الفاتورة">
                                                <i class="fa-solid fa-trash-can"></i>
                                            </button>
                                            <input type="checkbox" name="remove_items[]" value="{{ $item->id }}"
                                                id="remove_{{ $item->id }}" class="d-none">
                                        </td>
                                    @endif
                                </tr>
                                <tr id="why-row-{{ $item->id }}" class="why-panel" data-tt-companion-of="{{ $item->id }}">
                                    <td colspan="9" class="p-3">
                                        <div class="d-flex gap-2">
                                            <i class="fa-solid fa-lightbulb text-warning mt-1"></i>
                                            <div>
                                                <p class="mb-1 text-secondary" style="line-height: 1.8;">
                                                    {{ $item->explanation_summary }}</p>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                {{-- 🆕 (ج.3) لوحة "الفروع": رصيد المادة بكل فرع، الفائض أولاً بلون مميّز، والسحب منه (للمسودات) --}}
                                <tr id="transfer-row-{{ $item->id }}" class="why-panel" data-tt-companion-of="{{ $item->id }}">
                                    <td colspan="9" class="p-3">
                                        @unless ($isDraft)
                                            <div class="small text-muted mb-2"><i class="fa-solid fa-lock fa-xs me-1"></i> الفاتورة غير مسودة — العرض للاطلاع فقط</div>
                                        @endunless
                                        <div class="d-flex align-items-center flex-wrap gap-2 mb-2">
                                            <strong class="small me-1"><i class="fa-solid fa-store text-secondary me-1"></i> {{ $item->product->name }} بالفروع</strong>
                                            <span class="d-flex flex-wrap gap-1" data-summary></span>
                                            <span class="badge px-2 py-1 rounded-2 ms-auto" style="background: var(--c-bg); border: 1px solid var(--c-border); color: var(--c-secondary);">
                                                <i class="fa-regular fa-clock me-1"></i> آخر تحديث للبيانات:
                                                <span data-last-update>{{ $surplusCycle->startedAt?->format('Y-m-d H:i') ?? 'غير معروف' }}</span>
                                            </span>
                                        </div>
                                        <div id="transfer-body-{{ $item->id }}" class="small text-muted"
                                            data-options-url="{{ route('purchasing.orders.items.transfer-options', [$order->id, $item->id]) }}"
                                            data-execute-url="{{ route('purchasing.orders.items.transfer', [$order->id, $item->id]) }}"
                                            data-product-name="{{ $item->product->name }}">
                                            جاري التحميل...
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <x-empty-state colspan="9" icon="fa-inbox" message="لا توجد أصناف بهذه الفاتورة." />
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($order->isEditable() && $order->items->isNotEmpty())
                <button type="submit" class="btn btn-outline-primary w-100 mb-4 rounded-2 fw-medium">
                    <i class="fa-solid fa-floppy-disk me-1"></i> حفظ التعديلات (بلا اعتماد بعد)
                </button>
            @endif
        </form>

        {{-- 🆕 (ج.3) نموذج التنفيذ خارج نموذج التعديل (لا نماذج متداخلة بـ HTML) — تملؤه لوحة "الفروع".
             للمسودات فقط؛ والحارس الحقيقي بالخادم (PurchaseTransferOptionsController::execute). --}}
        @if ($isDraft)
            <form method="POST" id="transferExecuteForm" class="d-none"
                onsubmit="return confirm('تنفيذ مناقلة عكسية من هذه الصيدلية للمستودع وحجز فائضها؟');">
                @csrf
                <input type="hidden" name="pharmacy_id">
                <input type="hidden" name="quantity">
            </form>
        @endif

        {{-- 🆕 (ج.2) سجل تعديلات الكمية المعتمدة (المقترح ↔ المعتمد) --}}
        @if ($approvalHistory->isNotEmpty())
            <div class="border rounded-2 overflow-hidden bg-white mb-4">
                <div class="p-2.5 border-bottom bg-light fw-bold small"><i class="fa-solid fa-clock-rotate-left me-1 text-muted"></i> سجل تعديلات الكميات المعتمدة</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0 small">
                        <thead><tr><th>الوقت</th><th>المستخدم</th><th>الإجراء</th><th>المادة</th><th class="text-center">المقترح</th><th class="text-center">قبل</th><th class="text-center">بعد</th></tr></thead>
                        <tbody>
                            @foreach ($approvalHistory as $log)
                                <tr>
                                    <td class="text-nowrap">{{ $log->created_at->format('Y-m-d H:i') }}</td>
                                    <td>{{ $log->user?->name ?? '—' }}</td>
                                    <td>{{ $log->action->label() }}</td>
                                    <td>{{ $log->context['product_name'] ?? '—' }}</td>
                                    <td class="text-center">{{ $log->context['suggested_qty'] ?? '—' }}</td>
                                    <td class="text-center">{{ $log->old_values['approved_qty'] ?? '—' }}</td>
                                    <td class="text-center">{{ $log->new_values['approved_qty'] ?? 'محذوفة' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if ($order->isEditable())
            <div class="row g-3">
                <div class="col-md-8">
                    <form action="{{ route('purchasing.orders.approve', $order->id) }}" method="POST"
                        class="p-3 rounded-2 h-100" style="background: var(--c-bg); border: 1px solid var(--c-border);">
                        @csrf
                        <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-circle-check text-success me-1"></i>
                            اعتماد هذه الفاتورة</h6>
                        <div class="row g-2 align-items-end">
                            <div class="col-md-5">
                                <label class="form-label small fw-bold text-secondary mb-1">اسم المعتمِد</label>
                                <input type="text" name="approved_by" required class="form-control form-control-sm"
                                    placeholder="اكتب اسمك">
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
                        <div class="form-text small mt-2">⚠️ إن لم تضغط "حفظ التعديلات" أولاً، سيُعتمَد آخر شكل محفوظ
                            للفاتورة (قد لا يشمل تعديلاتك الأخيرة).</div>
                    </form>
                </div>
                <div class="col-md-4">
                    <form action="{{ route('purchasing.orders.reject', $order->id) }}" method="POST"
                        class="p-3 rounded-2 h-100 d-flex flex-column justify-content-between"
                        style="background: #fef2f2; border: 1px solid #fecaca;"
                        onsubmit="return confirm('رفض هذه المسودة نهائياً؟ يمكنك توليد مسودة جديدة لهذا المورد بعدها مباشرة.')">
                        @csrf
                        <div>
                            <h6 class="fw-bold text-danger mb-2"><i class="fa-solid fa-ban me-1"></i> رفض المسودة</h6>
                            <input type="text" name="reason" class="form-control form-control-sm mb-2"
                                placeholder="سبب الرفض (اختياري)">
                        </div>
                        <button type="submit" class="btn btn-outline-danger btn-sm w-100 fw-medium rounded-2">
                            <i class="fa-solid fa-xmark me-1"></i> رفض وحذف هذه المسودة
                        </button>
                    </form>
                </div>
            </div>
        @endif

        @if ($order->status->canBeFullyCancelled() && !$order->isEditable())
            <form action="{{ route('purchasing.orders.cancel', $order->id) }}" method="POST" class="p-3 rounded-2 mt-3"
                style="background: #fef2f2; border: 1px solid #fecaca;"
                onsubmit="return confirm('إلغاء هذه الفاتورة بالكامل؟ لم يصل منها أي شيء بعد.')">
                @csrf
                <div class="row g-2 align-items-end">
                    <div class="col-md-9">
                        <label class="form-label small fw-bold text-danger mb-1"><i class="fa-solid fa-ban me-1"></i>
                            إلغاء الفاتورة بالكامل</label>
                        <input type="text" name="reason" class="form-control form-control-sm"
                            placeholder="سبب الإلغاء">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-outline-danger btn-sm w-100 fw-medium rounded-2">إلغاء
                            كامل</button>
                    </div>
                </div>
            </form>
        @endif

        @if ($order->status->canCancelRemaining())
            <form action="{{ route('purchasing.orders.cancel-remaining', $order->id) }}" method="POST"
                class="p-3 rounded-2 mt-3" style="background: #fffbeb; border: 1px solid #fde68a;"
                onsubmit="return confirm('إلغاء الكمية المتبقية غير المستلَمة؟')">
                @csrf
                <div class="row g-2 align-items-end">
                    <div class="col-md-9">
                        <label class="form-label small fw-bold mb-1" style="color:#b45309;"><i
                                class="fa-solid fa-ban me-1"></i> إلغاء الكمية المتبقية غير المستلَمة</label>
                        <input type="text" name="reason" class="form-control form-control-sm"
                            placeholder="سبب إلغاء المتبقي">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-warning btn-sm w-100 fw-medium rounded-2 text-dark">إلغاء
                            المتبقي</button>
                    </div>
                </div>
            </form>
        @endif
    </div>

    <script>
        function toggleWhy(itemId) {
            document.getElementById('why-row-' + itemId)?.classList.toggle('is-open');
        }

        // 🆕 (ج.3) لوحة "الفروع": قراءة فقط حتى يضغط المستخدم "سحب للمستودع" بصف فائض (للمسودات فقط).
        // كل الفروع تُعرض (لا طيّ ولا إخفاء) — التمييز بالترتيب واللون فقط.
        // الأسماء مصدرها ملفات مرفوعة — تُهرَّب قبل إدراجها بـ innerHTML.
        const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        const num = v => v === null || v === undefined ? '—' : esc(v);

        async function toggleTransfers(itemId) {
            const row = document.getElementById('transfer-row-' + itemId);
            const opening = !row.classList.contains('is-open');
            row.classList.toggle('is-open');
            if (opening) await loadTransferOptions(itemId);
        }

        // حالة الفرع ← خلية "الحالة" (الوصف من الخادم؛ هنا اللون والرقم فقط).
        function branchState(b) {
            switch (b.state) {
                case 'surplus': return `<span class="branch-surplus-state">${esc(b.state_label)} ${esc(b.available)}</span>`;
                case 'below': return `<span class="branch-below-state">${esc(b.state_label)} (${esc(b.difference)})</span>`;
                default: return `<span class="text-muted">${esc(b.state_label)}</span>`;
            }
        }

        // صف فرع واحد. السحب فقط من صفوف "فائض" وبفاتورة مسودة؛ غير ذلك "—".
        function branchRow(b, canExecute) {
            const rowClass = { surplus: 'branch-surplus-row', surplus_reserved: 'branch-reserved-row' }[b.state] || '';
            const action = b.state === 'surplus' && canExecute
                ? `<div class="d-flex gap-1 justify-content-center">
                       <input type="number" min="1" max="${esc(b.available)}" value="${esc(b.suggested_qty)}" class="form-control form-control-sm text-center" style="width:75px" data-qty>
                       <button type="button" class="btn btn-outline-secondary btn-sm rounded-2 text-nowrap" data-pull="${esc(b.pharmacy_id)}"><i class="fa-solid fa-arrow-down-long me-1"></i> سحب للمستودع</button>
                   </div>`
                : '<span class="text-muted">—</span>';

            return `<tr class="${rowClass}" data-branch="${esc(b.pharmacy_id)}">
                <td class="fw-bold">${esc(b.name)}</td>
                <td class="text-center">${num(b.current_stock)}</td>
                <td class="text-center d-none d-md-table-cell">${num(b.standard)}</td>
                <td class="text-center d-none d-md-table-cell">${b.difference > 0 ? '+' : ''}${num(b.difference)}</td>
                <td>${branchState(b)}</td>
                <td class="text-center">${action}</td>
            </tr>`;
        }

        function branchSummary(data) {
            const s = data.summary;
            const surplus = s.surplus_available > 0
                ? `<span class="branch-chip branch-surplus-chip">فائض متاح: ${esc(s.surplus_available)} في ${esc(s.surplus_branches)} ${s.surplus_branches === 1 ? 'فرع' : 'فروع'}</span>`
                : '<span class="branch-chip">لا فائض متاح الآن</span>';

            return `<span class="branch-chip">الصناعة: ${esc(data.industry_stock)}</span>
                <span class="branch-chip">مجموع أرصدة الفروع: ${esc(s.branches_stock_sum)}</span>${surplus}`;
        }

        async function loadTransferOptions(itemId) {
            const body = document.getElementById('transfer-body-' + itemId);
            const panel = document.getElementById('transfer-row-' + itemId);
            body.textContent = 'جاري التحميل...';

            let data;
            try {
                const response = await fetch(body.dataset.optionsUrl, { headers: { 'Accept': 'application/json' } });
                if (!response.ok) throw new Error(response.status);
                data = await response.json();
            } catch (e) {
                body.textContent = 'تعذّر تحميل بيانات الفروع — أعد المحاولة.';
                return;
            }

            if (data.last_update) panel.querySelector('[data-last-update]').textContent = data.last_update;
            panel.querySelector('[data-summary]').innerHTML = branchSummary(data);

            if (!data.branches.length) {
                body.innerHTML = '<span>لا توجد بيانات مخزون قياسي أو أرصدة للفروع لهذه المادة.</span>';
                return;
            }

            const surplusRows = data.branches.filter(b => b.state === 'surplus');
            const otherRows = data.branches.filter(b => b.state !== 'surplus');
            const divider = surplusRows.length && otherRows.length
                ? '<tr class="branch-divider"><td colspan="6">باقي الفروع</td></tr>' : '';

            body.innerHTML = `<div class="table-responsive branch-stock-scroll">
                    <table class="table table-sm mb-0 branch-stock-table">
                        <thead><tr>
                            <th>الفرع</th><th class="text-center">الرصيد</th>
                            <th class="text-center d-none d-md-table-cell">القياسي</th><th class="text-center d-none d-md-table-cell">الفرق</th>
                            <th>الحالة</th><th class="text-center">إجراء</th>
                        </tr></thead>
                        <tbody>${surplusRows.map(b => branchRow(b, data.can_execute)).join('')}${divider}${otherRows.map(b => branchRow(b, data.can_execute)).join('')}</tbody>
                    </table>
                </div>
                <div class="mt-2" style="font-size: .7rem;">
                    <span class="me-3"><span class="branch-legend-swatch is-surplus"></span>فائض متاح</span>
                    <span><span class="branch-legend-swatch is-below"></span>دون القياسي</span>
                </div>`;

            body.querySelectorAll('[data-pull]').forEach(button => button.addEventListener('click', () => {
                const b = data.branches.find(x => String(x.pharmacy_id) === button.dataset.pull);
                const qty = Number(button.closest('tr').querySelector('[data-qty]').value);
                if (!Number.isInteger(qty) || qty < 1 || qty > b.available) {
                    alert(`الكمية يجب أن تكون عدداً صحيحاً بين 1 و${b.available}.`);
                    return;
                }
                if (!confirm(`سحب ${qty} × ${body.dataset.productName} من ${b.name} إلى المستودع المركزي؟`)) return;

                const form = document.getElementById('transferExecuteForm');
                form.action = body.dataset.executeUrl;
                form.pharmacy_id.value = b.pharmacy_id;
                form.quantity.value = qty;
                form.submit(); // submit() لا requestSubmit(): التأكيد أعلاه يكفي، بلا نافذة تأكيد ثانية من onsubmit.
            }));
        }

        function markForRemoval(itemId) {
            if (!confirm('حذف هذا الصنف من الفاتورة؟ سيُطبَّق فوراً عند الضغط على "حفظ التعديلات".')) return;
            document.getElementById('remove_' + itemId).checked = true;
            const row = document.getElementById('item-row-' + itemId);
            row.style.opacity = '0.4';
            row.style.textDecoration = 'line-through';
            // 🩹 تعطيل حقل الكمية بالسطر المحذوف حتى لا يُرسَل بقيمة قد تُربك المعالجة
            row.querySelector('input[type=number]')?.setAttribute('disabled', 'disabled');
        }
    </script>
@endsection
