@extends('layouts.admin')

@section('page_title', 'توليد فواتير شراء مقترحة')
@section('page_subtitle', 'المشتريات — نواقص المناقلات أو تحقيق المخزون القياسي للمستودع')

@section('content')
    <div class="surface-card p-4 shadow-sm" style="max-width: 820px; margin: 0 auto;">

        <div class="mb-4">
            <h5 class="fw-bold text-dark mb-1"><i class="fa-solid fa-wand-magic-sparkles text-primary me-1"></i> توليد فواتير
                شراء مقترحة تلقائياً</h5>
            <p class="text-muted small mb-0">
                سيقوم النظام بحساب احتياج كل مادة حسب المصدر الذي تختاره أدناه، واقتراح أفضل مورد حسب الشركة المفضّلة —
                ضمن سقف الميزانية إن وُجد.
            </p>
        </div>

        @if ($lockedSupplierIds->isNotEmpty())
            <div class="alert alert-warning small mb-4">
                <i class="fa-solid fa-lock me-1"></i>
                الموردون التالون لديهم مسودة معلّقة بانتظار قرارك (اعتماد أو رفض)، ولن يُولَّد لهم أي فاتورة جديدة حتى يُبتّ
                بأمرها:
                <strong>{{ $suppliers->whereIn('id', $lockedSupplierIds)->pluck('name')->implode('، ') }}</strong>.
                راجع <a href="{{ route('purchasing.orders.index', ['status' => 'draft']) }}" class="fw-bold">المسودات
                    المعلّقة</a> أولاً.
            </div>
        @endif

        <form action="{{ route('purchasing.orders.store') }}" method="POST">
            @csrf

            {{-- نمط التوليد --}}
            <div class="mb-4">
                <label class="form-label fw-bold text-dark small">نمط توليد الفواتير</label>
                <select name="generation_mode" id="generationModeSelect"
                    class="form-select @error('generation_mode') is-invalid @enderror">
                    @foreach (\App\Enums\PurchaseGenerationMode::cases() as $mode)
                        <option value="{{ $mode->value }}" @selected(old('generation_mode') === $mode->value)>
                            {{ $mode->label() }}
                        </option>
                    @endforeach
                </select>
                @error('generation_mode')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            {{-- مصدر النواقص — يظهر فقط عند نمط يعتمد على النواقص --}}
            <div class="mb-4" id="shortageSourceWrapper">
                <label class="form-label fw-bold text-dark small">مصدر النواقص المعتمَد</label>
                <select name="shortage_source" class="form-select @error('shortage_source') is-invalid @enderror">
                    <option value="" @selected(old('shortage_source') === null)>كلا المصدرين معاً</option>
                    @foreach (\App\Enums\ShortageSource::cases() as $source)
                        <option value="{{ $source->value }}" @selected(old('shortage_source') === $source->value)>
                            {{ $source->label() }}
                        </option>
                    @endforeach
                </select>
                @error('shortage_source')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            {{-- مدة التنبؤ --}}
            <div class="mb-4">
                <label class="form-label fw-bold text-dark small">مدة التنبؤ (بالأيام)</label>
                <input type="number" name="forecast_period_days" min="1" max="365"
                    value="{{ old('forecast_period_days', 90) }}"
                    class="form-control @error('forecast_period_days') is-invalid @enderror" style="max-width: 200px;">
                @error('forecast_period_days')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <div class="form-text small">تُستخدَم فقط بنمط "تحقيق المخزون القياسي للمستودع" — لا أثر لها بنمط "النواقص"
                    وحده.</div>
            </div>

            {{-- 🆕 (ج.1) نطاق التوليد: استراتيجيتان، وكلتاهما تدعم أكثر من مورد (فاتورة مستقلة لكل مورد) --}}
            @php
                $scopeMode = old('supplier_scope_mode', 'direct');
                $oldSupplierIds = array_map('intval', (array) old('supplier_ids', old('supplier_id') ? [old('supplier_id')] : []));
            @endphp
            <div class="mb-4 p-3 rounded-2" style="background: var(--c-bg); border: 1px solid var(--c-border);">
                <label class="form-label fw-bold text-dark small mb-2">نطاق التوليد</label>
                <div class="d-flex gap-4 mb-3 flex-wrap">
                    @foreach (\App\Enums\SupplierScopeMode::cases() as $mode)
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="supplier_scope_mode" id="scope_{{ $mode->value }}"
                                value="{{ $mode->value }}" @checked($scopeMode === $mode->value) data-scope-mode>
                            <label class="form-check-label small" for="scope_{{ $mode->value }}">{{ $mode->label() }}</label>
                        </div>
                    @endforeach
                </div>

                {{-- الاستراتيجية 1: مورد/موردون مباشرة --}}
                <div data-scope-zone="direct">
                    <div class="form-text small mb-1">اختر مورداً أو أكثر — أو لا تختر شيئاً لتوليد فواتير لكل الموردين (يُتخطّى المقفولون تلقائياً).</div>
                    <div class="border rounded-2 bg-white p-2" style="max-height: 220px; overflow-y: auto;">
                        @foreach ($suppliers as $supplier)
                            @php($isLocked = $lockedSupplierIds->contains($supplier->id))
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="supplier_ids[]" value="{{ $supplier->id }}"
                                    id="direct_supplier_{{ $supplier->id }}" data-direct-supplier
                                    @checked($scopeMode === 'direct' && in_array($supplier->id, $oldSupplierIds, true)) @disabled($isLocked)>
                                <label class="form-check-label small" for="direct_supplier_{{ $supplier->id }}">
                                    {{ $supplier->name }} @if ($isLocked)<span class="text-muted">— 🔒 لديه مسودة معلّقة</span>@endif
                                </label>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- الاستراتيجية 2: شركة ← موردون يحملونها --}}
                <div data-scope-zone="company">
                    <div class="row g-2 align-items-end mb-2">
                        <div class="col-md-8">
                            <label class="small fw-bold text-secondary mb-1">الشركة</label>
                            <select name="company" id="companySelect" class="form-select form-select-sm @error('company') is-invalid @enderror">
                                <option value="">— اختر الشركة —</option>
                                @foreach ($companies as $company)
                                    <option value="{{ $company }}" @selected(old('company') === $company)>{{ $company }}</option>
                                @endforeach
                            </select>
                            @error('company')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div id="companySuppliers" class="border rounded-2 bg-white p-2 small text-muted">اختر شركة لعرض الموردين الذين يحملونها.</div>
                </div>

                @error('supplier_ids')
                    <div class="text-danger small mt-2"><i class="fa-solid fa-circle-exclamation me-1"></i>{{ $message }}</div>
                @enderror
                @error('supplier_ids.*')
                    <div class="text-danger small mt-2">{{ $message }}</div>
                @enderror
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label fw-bold text-dark small">سقف ميزانية يدوي (اختياري)</label>
                    <input type="number" step="0.01" min="0" name="manual_budget_override"
                        value="{{ old('manual_budget_override') }}"
                        class="form-control @error('manual_budget_override') is-invalid @enderror"
                        placeholder="اتركه فارغاً لاستخدام الميزانية الشهرية العامة">
                    @error('manual_budget_override')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                    <div class="form-text small">لو تُرك فارغاً وكانت الميزانية الشهرية العامة مفعَّلة بإعدادات النظام،
                        ستُطبَّق تلقائياً.</div>
                </div>
            </div>

            <button type="submit" class="btn btn-brand w-100 py-2 fw-medium rounded-2 shadow-sm">
                <i class="fa-solid fa-gears me-1"></i> فحص الاحتياج وتوليد الفواتير المقترحة
            </button>
        </form>
    </div>

    @push('scripts')
        <script>
            (function() {
                const modeSelect = document.getElementById('generationModeSelect');
                const sourceWrapper = document.getElementById('shortageSourceWrapper');

                function syncVisibility() {
                    const includesShortages = modeSelect.value !== 'standard_stock';
                    sourceWrapper.style.display = includesShortages ? '' : 'none';
                }

                modeSelect.addEventListener('change', syncVisibility);
                syncVisibility();
            })();

            // 🆕 (ج.1) تبديل منطقتي الاستراتيجيتين + جلب موردي الشركة المختارة.
            (function() {
                const oldIds = @json($oldSupplierIds);
                const zones = document.querySelectorAll('[data-scope-zone]');
                const companySelect = document.getElementById('companySelect');
                const target = document.getElementById('companySuppliers');
                const currentMode = () => document.querySelector('[data-scope-mode]:checked')?.value ?? 'direct';
                const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

                function syncScope() {
                    zones.forEach(zone => {
                        const active = zone.dataset.scopeZone === currentMode();
                        zone.style.display = active ? '' : 'none';
                        // حقول المنطقة المخفية لا تُرسل — فلا تختلط اختيارات الاستراتيجيتين.
                        zone.querySelectorAll('input, select').forEach(el => el.disabled = !active || el.dataset.locked === '1');
                    });
                }

                async function loadCompanySuppliers() {
                    const company = companySelect.value;
                    if (!company) {
                        target.innerHTML = 'اختر شركة لعرض الموردين الذين يحملونها.';
                        return;
                    }
                    target.innerHTML = 'جاري التحميل...';
                    const url = '{{ route('purchasing.orders.suppliers-for-company') }}?company=' + encodeURIComponent(company);
                    const { suppliers } = await (await fetch(url, { headers: { 'Accept': 'application/json' } })).json();

                    if (!suppliers.length) {
                        target.innerHTML = '<span class="text-danger">لا يوجد مورد مرتبط بهذه الشركة بعد.</span>';
                        return;
                    }
                    target.innerHTML = '<div class="mb-1 text-dark fw-bold">الموردون المقترحون — اختر واحداً أو أكثر:</div>' + suppliers.map(s => `
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="supplier_ids[]" value="${s.id}" id="company_supplier_${s.id}"
                                ${s.locked ? 'disabled data-locked="1"' : ''} ${oldIds.includes(s.id) && !s.locked ? 'checked' : ''}>
                            <label class="form-check-label" for="company_supplier_${s.id}">${esc(s.name)} ${s.locked ? '<span class="text-muted">— 🔒 لديه مسودة معلّقة</span>' : ''}</label>
                        </div>`).join('');
                    syncScope();
                }

                // الموردون المقفولون بالاستراتيجية المباشرة يبقون معطَّلين دائماً.
                document.querySelectorAll('[data-direct-supplier][disabled]').forEach(el => el.dataset.locked = '1');
                document.querySelectorAll('[data-scope-mode]').forEach(r => r.addEventListener('change', syncScope));
                companySelect.addEventListener('change', loadCompanySuppliers);
                syncScope();
                if (companySelect.value) loadCompanySuppliers();
            })();
        </script>
    @endpush
@endsection
