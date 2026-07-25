{{--
    🎯 نُقلت من import-shortages.blade.php (التي كانت تحتوي الـ HTML+JS الكامل
    للـ drag&drop) بينما upload.blade.php كان يستخدم <input type="file"> عادي
    بدون drag&drop إطلاقاً. الآن كلاهما يستخدمان نفس المكوّن، فسلوك رفع
    الملفات موحّد بكل الشاشات (تناسق تجربة المستخدم UX Consistency).

    الاستخدام:
    <x-file-upload-zone
        name="inventory_file"
        label="ملف جرد المستودع المركزي"
        :required="true"
        accept=".xlsx,.xls,.csv"
        hint="الحد الأقصى 10MB" />
--}}
@props([
    'name',
    'label',
    'required' => false,
    'accept' => '.xlsx,.xls,.csv',
    'hint' => null,
])

@php($zoneId = 'uploadZone_' . $name)

<div class="file-upload-wrapper mb-3" id="{{ $zoneId }}_wrapper">
    <label class="form-label fw-bold text-dark d-flex align-items-center justify-content-between mb-2">
        <span>{{ $label }}</span>
        @if ($required)
            <span class="badge rounded-pill bg-danger-subtle text-danger px-2 py-0.5 small" style="font-size:0.7rem">إلزامي</span>
        @else
            <span class="badge rounded-pill bg-secondary-subtle text-secondary px-2 py-0.5 small" style="font-size:0.7rem">اختياري</span>
        @endif
    </label>

    <div class="upload-zone p-3 text-center" id="{{ $zoneId }}" onclick="document.getElementById('{{ $zoneId }}_input').click()">
        <input type="file" name="{{ $name }}" id="{{ $zoneId }}_input" class="d-none" accept="{{ $accept }}" @required($required)>
        <div id="{{ $zoneId }}_empty">
            <i class="fa-solid fa-cloud-arrow-up fa-2x text-muted mb-2 opacity-75"></i>
            <p class="text-muted small mb-0">اضغط هنا للاختيار أو اسحب الملف ({{ $accept }})</p>
        </div>
        <div id="{{ $zoneId }}_filled" class="d-none">
            <i class="fa-solid fa-circle-check fa-2x text-success mb-2"></i>
            <p class="fw-bold text-dark small mb-0" id="{{ $zoneId }}_filename">—</p>
        </div>
    </div>
    @if ($hint)
        <div class="form-text text-muted small mt-1" style="font-size: 0.75rem;">{{ $hint }}</div>
    @endif
</div>

@once
    @push('styles')
        <style>
            /* 🎯 CSS دُمج هنا داخل المكوّن نفسه (بدل بقائه محلياً بصفحة import-shortages
               فقط) ليصبح المكوّن "مكتفياً ذاتياً": أي صفحة تستخدم مكوّن رفع الملفات
               تحصل على نفس المظهر تلقائياً دون الحاجة لنسخ هذا الـ CSS بكل صفحة. */
            .file-upload-wrapper {
                background-color: var(--c-bg);
                border: 1px solid var(--c-border);
                border-radius: 10px;
                padding: 16px;
                transition: border-color .2s ease;
            }
            .file-upload-wrapper:focus-within {
                border-color: var(--c-primary);
            }
            .upload-zone {
                border: 2px dashed #cbd5e1;
                background-color: var(--c-bg);
                border-radius: 10px;
                transition: all 0.2s ease;
                cursor: pointer;
            }
            .upload-zone:hover {
                border-color: var(--c-primary);
                background-color: var(--c-primary-soft);
            }
        </style>
    @endpush

    @push('scripts')
        <script>
            // 🎯 سكربت واحد عام يُهيّئ أي عنصر ينتهي id بـ "_input" داخل .upload-zone
            // بدل تكرار نفس منطق drag&drop لكل حقل رفع على حدة.
            document.querySelectorAll('.file-upload-wrapper').forEach(wrapper => {
                const zone   = wrapper.querySelector('.upload-zone');
                const input  = wrapper.querySelector('input[type=file]');
                const empty  = wrapper.querySelector('[id$="_empty"]');
                const filled = wrapper.querySelector('[id$="_filled"]');
                const nameEl = wrapper.querySelector('[id$="_filename"]');
                if (!zone || !input) return;

                function showFile(file) {
                    if (!file) { empty.classList.remove('d-none'); filled.classList.add('d-none'); return; }
                    nameEl.textContent = file.name;
                    empty.classList.add('d-none');
                    filled.classList.remove('d-none');
                }

                input.addEventListener('change', () => showFile(input.files[0]));

                ['dragenter', 'dragover'].forEach(evt => zone.addEventListener(evt, e => {
                    e.preventDefault(); e.stopPropagation();
                    zone.style.borderColor = 'var(--c-primary)';
                    zone.style.backgroundColor = 'var(--c-primary-soft)';
                }));
                ['dragleave', 'drop'].forEach(evt => zone.addEventListener(evt, e => {
                    e.preventDefault(); e.stopPropagation();
                    zone.style.borderColor = '';
                    zone.style.backgroundColor = '';
                }));
                zone.addEventListener('drop', e => {
                    if (e.dataTransfer.files.length) {
                        input.files = e.dataTransfer.files;
                        showFile(e.dataTransfer.files[0]);
                    }
                });
            });
        </script>
    @endpush
@endonce
