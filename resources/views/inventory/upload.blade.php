{{-- resources/views/inventory/upload.blade.php --}}
@extends('layouts.admin')

@section('page_title', 'رفع الجرد اليومي')
@section('page_subtitle', 'العمليات (المناقلات)')

@push('styles')
    <style>
        /* 🆕 بطاقات اختيار وضع الترميم — نمط radio مخفي + label مُنسَّق،
                   يعتمد على selector الأخوة (input:checked + label) بلا حاجة لـ :has() */
        .mode-radio {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }

        .mode-card {
            display: block;
            cursor: pointer;
            border: 1.5px solid var(--c-border);
            border-radius: 10px;
            padding: 14px;
            height: 100%;
            transition: border-color .15s ease, background-color .15s ease;
        }

        .mode-card:hover {
            border-color: var(--c-primary);
        }

        .mode-radio:checked+.mode-card {
            border-color: var(--c-primary);
            background-color: var(--c-primary-soft, rgba(255, 107, 0, .08));
        }

        .mode-radio:focus-visible+.mode-card {
            outline: 2px solid var(--c-primary);
            outline-offset: 2px;
        }

        .mode-card .mode-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--c-primary-soft, rgba(255, 107, 0, .1));
            color: var(--c-primary);
            margin-bottom: .5rem;
        }

        /* 🎨 ضبط ألوان وتراتبية نصوص بطاقات الترميم لتطابق التلميحات المساعدة */
        .mode-title {
            color: var(--c-text-main, inherit);
        }

        .mode-card-title {
            color: var(--c-text-main, inherit);
        }

        .mode-card-desc {
            font-size: .72rem;
            line-height: 1.45;
            margin-top: .25rem;
            color: var(--c-text-muted, rgba(255, 255, 255, .55));
        }
    </style>
@endpush

@section('content')
    <div class="surface-card p-4 shadow-sm" style="max-width: 820px; margin: 0 auto;">

        <div class="alert alert-light border small text-secondary mb-4">
            <i class="fa-solid fa-circle-info text-primary me-1"></i>
            اختر طريقة الترميم المناسبة لهذه الدفعة، ثم ارفع الملفات المطلوبة لها فقط. ملف جرد المستودع المركزي
            إلزامي بوضعَي «المخزون القياسي» و«المدمج» — اختياري فقط بوضع «بناءً على المبيعات» (غيابه يعني
            استخدام آخر رصيد مركزي مسجَّل بلا تحديثه بهذه الدفعة).
            <strong>الأسرع:</strong> ارفع «كشف المستودعات» — ملف واحد (مادة × موقع) يغني عن ملف المركزي وملفات الصيدليات كلها.
        </div>

        <form action="{{ route('inventory.upload') }}" method="POST" enctype="multipart/form-data"
            data-loading-message="جاري معالجة الملفات وتنفيذ الترميم..." id="uploadForm">
            @csrf
            {{-- ══════════ اختيار وضع الترميم ══════════ --}}
            <div class="mb-4">
                <label class="fw-bold small mode-title mb-2 d-block">طريقة الترميم لهذه الدفعة</label>
                @error('restocking_mode')
                    <div class="text-danger small mb-2">
                        <i class="fa-solid fa-circle-exclamation me-1"></i>{{ $message }}
                    </div>
                @enderror
                <div class="row g-2">
                    <div class="col-md-3">
                        <input type="radio" name="restocking_mode" id="mode_sales" value="sales" class="mode-radio"
                            data-mode-trigger {{ old('restocking_mode') === 'sales' ? 'checked' : '' }}>
                        <label for="mode_sales" class="mode-card">
                            <span class="mode-icon"><i class="fa-solid fa-cart-shopping"></i></span>
                            <span class="fw-bold d-block small mode-card-title">بناءً على المبيعات</span>
                            <span class="d-block mode-card-desc">
                                يقارن كمية مبيعات كل صيدلية بالرصيد المركزي مباشرة، ويسجّل العجز كـ«نقص مبيعات».
                            </span>
                        </label>
                    </div>
                    <div class="col-md-3">
                        <input type="radio" name="restocking_mode" id="mode_standard" value="standard_inventory"
                            class="mode-radio" data-mode-trigger
                            {{ old('restocking_mode', 'standard_inventory') === 'standard_inventory' ? 'checked' : '' }}>
                        <label for="mode_standard" class="mode-card">
                            <span class="mode-icon"><i class="fa-solid fa-gauge-high"></i></span>
                            <span class="fw-bold d-block small mode-card-title">بناءً على المخزون القياسي</span>
                            <span class="d-block mode-card-desc">
                                يقارن رصيد كل صيدلية بمخزونها القياسي المحدَّد مسبقاً، ويسجّل العجز كـ«نقص مخزون قياسي».
                            </span>
                        </label>
                    </div>
                    <div class="col-md-3">
                        <input type="radio" name="restocking_mode" id="mode_hybrid" value="hybrid" class="mode-radio"
                            data-mode-trigger {{ old('restocking_mode') === 'hybrid' ? 'checked' : '' }}>
                        <label for="mode_hybrid" class="mode-card">
                            <span class="mode-icon"><i class="fa-solid fa-arrow-right-arrow-left"></i></span>
                            <span class="fw-bold d-block small mode-card-title">مدمج (الاثنان معاً)</span>
                            <span class="d-block mode-card-desc">
                                يُشغِّل الأساسين معاً بدفعة واحدة — كل نقص يُسجَّل بمصدره الصحيح تلقائياً.
                            </span>
                        </label>
                    </div>
                    {{-- 🆕 الوضع الرابع: المبيعات تفتح الباب، لكن الكمية = فجوة المخزون القياسي (لا كمية البيع) --}}
                    <div class="col-md-3">
                        <input type="radio" name="restocking_mode" id="mode_sales_gated" value="sales_gated_standard_inventory"
                            class="mode-radio" data-mode-trigger
                            {{ old('restocking_mode') === 'sales_gated_standard_inventory' ? 'checked' : '' }}>
                        <label for="mode_sales_gated" class="mode-card">
                            <span class="mode-icon"><i class="fa-solid fa-bell-concierge"></i></span>
                            <span class="fw-bold d-block small mode-card-title">مخزون قياسي مُفعَّل بالمبيعات</span>
                            <span class="d-block mode-card-desc">
                                البيع يُنبِّه النظام فوراً، لكن الكمية المشحونة = الفجوة إلى المخزون القياسي، لا كمية البيع نفسها.
                            </span>
                        </label>
                    </div>
                </div>
            </div>

            {{-- ══════════ 🆕 مصدر بيانات المخزون ══════════ --}}
            <div class="mb-4">
                <label class="fw-bold small mode-title mb-2 d-block">مصدر بيانات المخزون</label>
                <div class="row g-2">
                    <div class="col-md-6">
                        <input type="radio" name="inventory_source" id="source_matrix" value="matrix" class="mode-radio"
                            data-mode-trigger {{ old('inventory_source', 'matrix') === 'matrix' ? 'checked' : '' }}>
                        <label for="source_matrix" class="mode-card">
                            <span class="mode-icon"><i class="fa-solid fa-table-cells"></i></span>
                            <span class="fw-bold d-block small mode-card-title">كشف المستودعات (ملف واحد)</span>
                            <span class="d-block mode-card-desc">
                                مادة × موقع: المركزي وكل الصيدليات بملف واحد. الأسرع، ويُضاف أي صيدلية جديدة كعمود بلا أي إعداد.
                            </span>
                        </label>
                    </div>
                    <div class="col-md-6">
                        <input type="radio" name="inventory_source" id="source_separate" value="separate" class="mode-radio"
                            data-mode-trigger {{ old('inventory_source') === 'separate' ? 'checked' : '' }}>
                        <label for="source_separate" class="mode-card">
                            <span class="mode-icon"><i class="fa-solid fa-copy"></i></span>
                            <span class="fw-bold d-block small mode-card-title">ملفات منفصلة (الطريقة القديمة)</span>
                            <span class="d-block mode-card-desc">
                                ملف للمركزي + ملف لكل صيدلية. يتيح تمرير الحد الأدنى للفرق وحجم العبوة بملف الصيدلية.
                            </span>
                        </label>
                    </div>
                </div>
            </div>

            {{-- ══════════ ملفات الرفع ══════════ --}}
            <div id="zone_matrix" data-source-zone="matrix" data-required-unless-mode="sales,sales_gated_standard_inventory">
                <x-file-upload-zone name="warehouse_matrix_file" label="كشف المستودعات (المركزي + كل الصيدليات)" :step="1"
                    :required="true" accept=".xlsx,.csv"
                    hint="ملف واحد، الصف الأول رؤوس: رمز المادة ← الاسم ← الشركة ← مستودع الصناعة ← ثم عمود لكل صيدلية (بأي عدد، وتُعرَّف بعنوان العمود لا بموضعه). الفراغ = صفر. السالب يُصفَّر والكسر يُخفَّض لأقرب عدد صحيح مع تنبيه ظاهر بالنتيجة. xlsx أو csv فقط. إلزامي بوضعَي «المخزون القياسي» و«المدمج»." />
            </div>

            <div id="zone_central_inventory" data-source-zone="separate" data-required-unless-mode="sales,sales_gated_standard_inventory">
                <x-file-upload-zone name="inventory_file" label="ملف جرد المستودع المركزي" :step="1"
                    :required="true" accept=".xlsx,.xls,.csv"
                    hint="الترتيب الإجباري: رقم المادة ← اسم الشركة ← اسم المادة ← الكمية ← [اختياري] حجم العبوة ← [اختياري] السعر. إلزامي بوضعَي «المخزون القياسي» و«المدمج» — اختياري بوضع «بناءً على المبيعات»." />
            </div>

            <div id="zone_pharmacy_inventory" data-source-zone="separate" data-mode-zone="standard_inventory,hybrid">
                <x-file-upload-zone name="pharmacy_inventory_files" label="ملف جرد الصيدليات (نقاط البيع)" :step="2"
                    :required="true" :multiple="true" accept=".xlsx,.xls,.csv"
                    hint="الترتيب: اسم الصيدلية ← رمز المادة ← اسم المادة ← الشركة ← الكمية ← [اختياري] حد أدنى للفرق ← [اختياري] حجم العبوة. يمكن رفع أكثر من ملف معاً (صيدلية لكل ملف)." />
            </div>

            <div id="zone_sales" data-mode-zone="sales,hybrid,sales_gated_standard_inventory">
                <x-file-upload-zone name="sales_file" label="ملف مبيعات الصيدليات اليومي" :step="2"
                    :required="true" accept=".xlsx,.xls,.csv"
                    hint="الترتيب الإجباري: رقم المادة ← اسم الشركة ← اسم المادة ← اسم الصيدلية ← الكمية. يُستخدَم مباشرة لحساب كمية الشحن والعجز عند اختيار وضع «بناءً على المبيعات» أو «مدمج»." />
            </div>

            {{-- ══════════ 🆕 (ب.4) نوع الفواتير المولَّدة من هذه الدفعة ══════════ --}}
            @php($selectedInvoiceTypes = old('invoice_types', ['basic', 'shortages']))
            <div class="mb-4 p-3 rounded-2" style="background: var(--c-bg); border: 1px solid var(--c-border);">
                <label class="fw-bold small mode-title mb-2 d-block">الفواتير المطلوب توليدها</label>
                @error('invoice_types')
                    <div class="text-danger small mb-2"><i class="fa-solid fa-circle-exclamation me-1"></i>{{ $message }}</div>
                @enderror
                <div class="d-flex gap-4 flex-wrap">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="invoice_types[]" value="basic" id="invoice_type_basic"
                            @checked(in_array('basic', $selectedInvoiceTypes, true))>
                        <label class="form-check-label small" for="invoice_type_basic">
                            <strong>فواتير أساسية</strong> — ناتج طريقة الترميم المختارة أعلاه (وتسجيل النواقص الجديدة)
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="invoice_types[]" value="shortages" id="invoice_type_shortages"
                            @checked(in_array('shortages', $selectedInvoiceTypes, true))>
                        <label class="form-check-label small" for="invoice_type_shortages">
                            <strong>فواتير نواقص</strong> — ترميم النواقص المسجَّلة من المخزون المركزي
                        </label>
                    </div>
                </div>
                <div class="form-text small">اختر واحداً أو الاثنين. الافتراضي: الاثنين معاً.</div>
            </div>

            <button type="submit" class="btn btn-brand w-100 rounded-2 py-2 fw-medium shadow-sm">
                <i class="fa-solid fa-rocket me-1"></i> بدء المعالجة
            </button>
        </form>
    </div>
@endsection

@push('scripts')
    <script>
        (function() {
            // 🎓 ثلاثة مفاتيح تتحكم بظهور كل منطقة رفع، وتُقرأ من data-attributes بدل شروط مكتوبة لكل منطقة:
            //   data-mode-zone="a,b"          → تظهر فقط بهذه الأوضاع
            //   data-source-zone="matrix|separate" → تظهر فقط بهذا المصدر (كشف واحد / ملفات منفصلة)
            //   data-required-unless-mode="m,n" → إلزامية إلا بأحد هذه الأوضاع
            // إضافة منطقة جديدة = سمات HTML فقط، بلا أي تعديل هنا.
            const zones = document.querySelectorAll('[data-mode-zone], [data-source-zone], [data-required-unless-mode]');

            const currentMode = () => document.querySelector('input[name="restocking_mode"]:checked')?.value ?? 'standard_inventory';
            const currentSource = () => document.querySelector('input[name="inventory_source"]:checked')?.value ?? 'matrix';

            function syncZones() {
                const mode = currentMode();
                const source = currentSource();

                zones.forEach(zone => {
                    const modeOk = zone.dataset.modeZone ? zone.dataset.modeZone.split(',').includes(mode) : true;
                    const sourceOk = zone.dataset.sourceZone ? zone.dataset.sourceZone === source : true;
                    const visible = modeOk && sourceOk;

                    const markedRequired = zone.querySelector('.dt-pill--required') !== null;
                    const exemptFromRequired = zone.dataset.requiredUnlessMode
                        ? zone.dataset.requiredUnlessMode.split(',').includes(mode)
                        : false;

                    zone.style.display = visible ? '' : 'none';

                    zone.querySelectorAll('input[type="file"]').forEach(input => {
                        if (visible && markedRequired && !exemptFromRequired) {
                            input.setAttribute('required', 'required');
                        } else {
                            input.removeAttribute('required');
                        }

                        // منطقة مخفية لا تُرسِل ملفاً: يمنع تعارض "كشف + ملفات منفصلة" بطلب واحد.
                        if (!visible) {
                            input.value = '';
                        }
                    });
                });
            }

            document.querySelectorAll('[data-mode-trigger]').forEach(radio => {
                radio.addEventListener('change', syncZones);
            });

            syncZones();
        })();
    </script>
@endpush
