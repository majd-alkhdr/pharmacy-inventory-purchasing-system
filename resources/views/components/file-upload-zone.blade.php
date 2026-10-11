{{--
    🎨 إعادة تصميم بصري (بطاقة برأسية + شارة خطوة اختيارية) — الـ @props
    القديمة (name, label, required, multiple, accept, hint) بلا أي تغيير
    أو حذف، فكل استخدام حالي لـ <x-file-upload-zone .../> بالتطبيق
    يستمر بالعمل بلا لمس شاشته. أُضيف prop واحد فقط، اختياري بالكامل:

    step: رقم صحيح اختياري — يعرض شارة رقم دائرية نحاسية قبل التسمية
    (يطابق شكل "1"/"2" بالمرجع الأخير). أي استدعاء لا يمرّره (وهو كل
    استدعاء موجود حالياً بالتطبيق) لا يعرض الشارة إطلاقاً — بلا أي تغيير
    بصري بذلك الموضع تحديداً باستثناء البطاقة المحيطة (راجع الشرح أسفل).

    الاستخدام القديم (لا يزال يعمل تماماً كما هو، بلا شارة رقم):
    <x-file-upload-zone
        name="inventory_file"
        label="ملف جرد المستودع المركزي"
        :required="true"
        accept=".xlsx,.xls,.csv"
        hint="الحد الأقصى 10MB" />

    الاستخدام الجديد (بشارة خطوة رقمية، كما بشاشة "رفع الجرد اليومي"):
    <x-file-upload-zone
        name="inventory_file"
        label="ملف جرد المستودع المركزي"
        :step="1"
        :required="true"
        accept=".xlsx,.xls,.csv" />
--}}
@props([
    'name',
    'label',
    'required' => false,
    'multiple' => false,
    'accept' => '.xlsx,.xls,.csv',
    'hint' => null,
    'step' => null,
])

@php($zoneId = 'uploadZone_' . $name)
@php($inputHtmlName = $multiple ? $name . '[]' : $name)
@php($hasError = $errors->has($name) || $errors->has($name . '.*'))

<div class="dt-upload-wrapper tw-mb-4" id="{{ $zoneId }}_wrapper">
    <div class="tw-flex tw-items-center tw-justify-between tw-mb-3 tw-gap-3">
        <div class="tw-flex tw-items-center tw-gap-2.5 tw-min-w-0">
            @if (!is_null($step))
                <span class="dt-step-badge">{{ $step }}</span>
            @endif
            <span class="tw-text-sm tw-font-bold tw-text-[var(--c-text)] tw-truncate">{{ $label }}</span>
        </div>
        @if ($required)
            <span class="dt-pill dt-pill--required">إلزامي</span>
        @else
            <span class="dt-pill dt-pill--optional">اختياري</span>
        @endif
    </div>

    {{-- 🩹 نفس الإصلاح الأصلي بالضبط: حدود حمراء واضحة عند وجود خطأ تحقق --}}
    <div class="dt-upload-zone {{ $hasError ? 'dt-upload-zone--error' : '' }} tw-flex tw-flex-col tw-items-center tw-justify-center tw-text-center tw-p-6 tw-rounded-xl tw-cursor-pointer"
        id="{{ $zoneId }}" onclick="document.getElementById('{{ $zoneId }}_input').click()">
        <input type="file" name="{{ $inputHtmlName }}" id="{{ $zoneId }}_input" class="tw-hidden"
            accept="{{ $accept }}" @if ($multiple) multiple @endif @required($required)>

        <div id="{{ $zoneId }}_empty">
            <div class="dt-upload-icon tw-mx-auto tw-mb-3">
                <i class="fa-solid fa-cloud-arrow-up"></i>
            </div>
            <p class="tw-text-sm tw-text-[var(--c-text-muted)] tw-mb-1">
                @if ($multiple)
                    اضغط هنا لاختيار ملف واحد أو أكثر، أو اسحبها معاً
                @else
                    اضغط هنا للاختيار أو اسحب الملف
                @endif
            </p>
            <p class="tw-text-xs tw-text-[var(--c-text-faint)]">{{ $accept }}</p>
        </div>

        <div id="{{ $zoneId }}_filled" class="tw-hidden">
            <div class="dt-upload-icon dt-upload-icon--success tw-mx-auto tw-mb-3">
                <i class="fa-solid fa-circle-check"></i>
            </div>
            <p class="tw-font-bold tw-text-sm tw-text-[var(--c-text)] tw-mb-0" id="{{ $zoneId }}_filename">—</p>
        </div>
    </div>

    @error($name)
        <div class="tw-flex tw-items-center tw-gap-1.5 tw-text-[var(--c-danger)] tw-text-xs tw-font-medium tw-mt-2">
            <i class="fa-solid fa-circle-exclamation"></i> {{ $message }}
        </div>
    @enderror
    {{-- أخطاء تحقق خاصة بملف واحد ضمن مجموعة multiple (مثلاً name.2) --}}
    @foreach ($errors->get($name . '.*') as $messages)
        @foreach ($messages as $message)
            <div class="tw-flex tw-items-center tw-gap-1.5 tw-text-[var(--c-danger)] tw-text-xs tw-font-medium tw-mt-2">
                <i class="fa-solid fa-circle-exclamation"></i> {{ $message }}
            </div>
        @endforeach
    @endforeach

    @if ($hint)
        <div class="tw-text-xs tw-text-[var(--c-text-faint)] tw-mt-2">{{ $hint }}</div>
    @endif
</div>

@once
    @push('styles')
        <style>
            /* 🩹 بطاقة خارجية بارزة (ظل + حدّ) بدل الغلاف المسطّح السابق —
                       تطابق شكل "خطوة" مستقلة بالمرجع الأخير، وتستوعب رأساً يحمل
                       شارة الرقم + التسمية على جهة، والشارة (إلزامي/اختياري) على
                       الجهة الأخرى. */
            .dt-upload-wrapper {
                background-color: var(--c-surface);
                border: 1px solid var(--c-border);
                border-radius: 16px;
                box-shadow: var(--shadow-card);
                padding: 20px;
                transition: border-color .2s ease, background-color .2s ease, box-shadow .2s ease;
            }

            .dt-upload-wrapper:focus-within {
                border-color: var(--c-primary);
            }

            .dt-step-badge {
                width: 26px;
                height: 26px;
                border-radius: 999px;
                background: var(--c-primary);
                color: #fff;
                font-size: .78rem;
                font-weight: 800;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
            }

            .dt-pill {
                display: inline-flex;
                align-items: center;
                white-space: nowrap;
                border-radius: 999px;
                padding: .2rem .65rem;
                font-size: .68rem;
                font-weight: 700;
                border: 1px solid transparent;
                flex-shrink: 0;
            }

            /* 🩹 مبنية على --c-danger/--c-danger-soft (متغيّرات مُتبدّلة مع
                       الثيم) بدل أصناف Tailwind الثابتة (red-400/red-500) التي
                       كانت مضبوطة على تباين الوضع الداكن فقط، وتصبح شبه غير
                       مقروءة أو منخفضة التباين فوق خلفية بيضاء بالوضع الفاتح. */
            .dt-pill--required {
                background: var(--c-danger-soft);
                color: var(--c-danger);
                border-color: var(--c-danger);
            }

            /* 🩹 كذلك هنا: كانت bg-white/5 (تراكب أبيض 5%) — غير مرئية
                       إطلاقاً فوق بطاقة بيضاء بالوضع الفاتح. */
            .dt-pill--optional {
                background: var(--c-surface-hover);
                color: var(--c-text-muted);
                border-color: var(--c-border);
            }

            /* 🩹 var(--c-surface-hover) بدل متغيّر التراكب الخاص المُزال —
                       يعطي تبايناً واضحاً "بطاقة داخل بطاقة" في كلا الوضعين، بدل
                       تراكب أبيض 1.5% كان يختفي عملياً فوق خلفية فاتحة. */
            .dt-upload-zone {
                border: 1.5px dashed var(--c-border);
                background-color: var(--c-surface-hover);
                transition: all .2s ease;
                min-height: 148px;
            }

            .dt-upload-zone:hover,
            .dt-upload-zone.dt-drag-over {
                border-color: var(--c-primary);
                background-color: var(--c-primary-soft);
            }

            .dt-upload-zone--error {
                border-color: var(--c-danger) !important;
                background-color: var(--c-danger-soft) !important;
            }

            /* 🩹 نفس منطق stat-card: دائرة مفرَّغة بدل مربّع ممتلئ */
            .dt-upload-icon {
                width: 48px;
                height: 48px;
                border-radius: 999px;
                display: flex;
                align-items: center;
                justify-content: center;
                background: transparent;
                border: 1.5px solid var(--c-primary);
                color: var(--c-primary);
                font-size: 1.2rem;
            }

            .dt-upload-icon--success {
                border-color: var(--c-success);
                color: var(--c-success);
            }
        </style>
    @endpush

    @push('scripts')
        <script>
            document.querySelectorAll('.dt-upload-wrapper').forEach(wrapper => {
                const zone = wrapper.querySelector('.dt-upload-zone');
                const input = wrapper.querySelector('input[type=file]');
                const empty = wrapper.querySelector('[id$="_empty"]');
                const filled = wrapper.querySelector('[id$="_filled"]');
                const nameEl = wrapper.querySelector('[id$="_filename"]');
                if (!zone || !input) return;

                // يدعم عرض ملف واحد أو عدة ملفات معاً (FileList)
                function showFiles(fileList) {
                    if (!fileList || fileList.length === 0) {
                        empty.classList.remove('tw-hidden');
                        filled.classList.add('tw-hidden');
                        return;
                    }

                    if (fileList.length === 1) {
                        nameEl.textContent = fileList[0].name;
                    } else {
                        const names = Array.from(fileList).map(f => f.name).join('، ');
                        nameEl.textContent = fileList.length + ' ملفات مختارة: ' + names;
                    }

                    empty.classList.add('tw-hidden');
                    filled.classList.remove('tw-hidden');
                    zone.classList.remove('dt-upload-zone--error');
                }

                input.addEventListener('change', () => showFiles(input.files));

                ['dragenter', 'dragover'].forEach(evt => zone.addEventListener(evt, e => {
                    e.preventDefault();
                    e.stopPropagation();
                    zone.classList.add('dt-drag-over');
                }));
                ['dragleave', 'drop'].forEach(evt => zone.addEventListener(evt, e => {
                    e.preventDefault();
                    e.stopPropagation();
                    zone.classList.remove('dt-drag-over');
                }));
                zone.addEventListener('drop', e => {
                    if (e.dataTransfer.files.length) {
                        input.files = e.dataTransfer.files;
                        showFiles(input.files);
                    }
                });
            });
        </script>
    @endpush
@endonce
