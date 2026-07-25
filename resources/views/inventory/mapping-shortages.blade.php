@extends('layouts.admin')

@section('page_title', 'مطابقة البيانات اليدوية')
@section('page_subtitle', 'العمليات (المناقلات) — نواقص')

@push('styles')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <style>
        .mapping-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.05), 0 2px 4px -2px rgb(0 0 0 / 0.05);
        }

        .table-responsive-custom {
            max-height: 420px;
            overflow-y: auto;
            border-bottom-left-radius: 8px;
            border-bottom-right-radius: 8px;
        }

        .table thead th {
            position: sticky;
            top: 0;
            background-color: var(--c-dark) !important;
            color: #ffffff;
            z-index: 10;
            font-weight: 600;
            border: none;
        }

        .select2-container--default .select2-selection--single {
            height: 40px !important;
            border: 1px solid #cbd5e1 !important;
            border-radius: 6px !important;
            padding: 5px 12px !important;
            background-color: #fff !important;
        }

        .select2-container--default[dir="rtl"] .select2-selection--single .select2-selection__arrow {
            left: 10px !important;
            right: auto !important;
            top: 7px !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 28px !important;
            color: #334155 !important;
            padding-right: 0 !important;
            text-align: right;
        }

        .select2-dropdown {
            border: 1px solid #cbd5e1 !important;
            border-radius: 6px !important;
            box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1);
        }

        .page-sticky-summary {
            position: sticky;
            top: calc(var(--topbar-h) + 16px);
        }
    </style>
@endpush

@section('content')

    <div class="text-center mb-5">
        <span class="badge bg-warning text-dark px-3 py-2 rounded-pill fw-bold mb-2">
            <i class="fa-solid fa-triangle-exclamation me-1"></i> يتطلب إجراء من المستخدم
        </span>
        <h2 class="fw-bold text-slate-800">معالجة البيانات غير المطابقة</h2>
        <p class="text-secondary">يرجى مراجعة وتوجيه المدخلات التالية لضمان سلامة ومطابقة السجلات التاريخية في
            النظام.</p>
    </div>

    {{--
        🩹 ملاحظة هامة: الفورم هون ما عاد يرسل الحقول كمصفوفة HTML تقليدية
        (products[0][barcode], products[1][barcode]...) لأنه مع آلاف الصفوف
        (مثلاً 3406+ مادة معلقة)، عدد حقول الفورم يتجاوز حد PHP الافتراضي
        max_input_vars (=1000). PHP بهاي الحالة يتجاهل الحقول الزايدة عن الحد
        بصمت تام بدون أي خطأ ظاهر، فتضيع بيانات صفوف كاملة بعد رقم معيّن
        (تقريباً بعد أول 250 صف حسب عدد الحقول لكل صف) دون أي تنبيه للمستخدم.
        الحل: تجميع كل البيانات بمصفوفة JS وإرسالها كحقل JSON واحد (بدون حد
        لعدد العناصر) — تماماً بنفس أسلوب فورم مطابقة المبيعات.
    --}}
    <form id="mappingForm" action="{{ route('inventory.shortages.save-mapping') }}" method="POST">
        @csrf
        <input type="hidden" name="session_id" value="{{ $sessionId }}">
        <input type="hidden" name="pharmacies_json" id="pharmacies_json">
        <input type="hidden" name="products_json" id="products_json">

        <div class="row g-4">
            <div class="col-lg-9">

                @if (isset($unmappedPharmacies) && count($unmappedPharmacies) > 0)
                    <div class="card mapping-card border-start border-warning border-4 mb-5">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-0">
                            <h5 class="mb-0 fw-bold text-dark">
                                <i class="fa-solid fa-clinic-medical text-warning me-2"></i> صيدليات غير مسجلة في
                                النظام
                            </h5>
                            <span class="badge bg-warning-subtle text-warning-emphasis fw-bold rounded-pill px-3 py-1">
                                {{ count($unmappedPharmacies) }} صيدلية متبقية
                            </span>
                        </div>
                        <div class="card-body pt-0">
                            <div class="input-group mb-3 shadow-sm rounded">
                                <span class="input-group-text bg-light border-end-0 text-muted"><i
                                        class="fa-solid fa-magnifying-glass"></i></span>
                                <input type="text" id="searchPharmacies" class="form-control border-start-0 bg-light"
                                    placeholder="بحث سريع باسم الصيدلية المفقودة...">
                            </div>

                            <div class="table-responsive-custom border rounded">
                                <table class="table table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th style="width: 50%;">الاسم الوارد في الملف</th>
                                            <th style="width: 50%;">الإجراء المقترح (ربط أو إنشاء جديد)</th>
                                        </tr>
                                    </thead>
                                    <tbody id="pharmacyTableBody">
                                        @foreach ($unmappedPharmacies as $index => $name)
                                            <tr data-excel-name="{{ $name }}">
                                                <td>
                                                    <span class="fw-bold text-secondary"><i
                                                            class="fa-solid fa-store fa-sm me-2 text-muted"></i>{{ $name }}</span>
                                                </td>
                                                <td>
                                                    <select class="pharmacy-select form-select"
                                                        data-excel-name="{{ $name }}">
                                                        <option value="NEW" selected>✨ إنشاء كصيدلية جديدة في
                                                            النظام</option>
                                                    </select>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                @endif

                @if (isset($unmappedProducts) && count($unmappedProducts) > 0)
                    <div class="card mapping-card border-start border-danger border-4 mb-4">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-0">
                            <h5 class="mb-0 fw-bold text-dark">
                                <i class="fa-solid fa-box-open text-danger me-2"></i> مواد وأدوية غير مطابقة
                            </h5>
                            <span class="badge bg-danger-subtle text-danger-emphasis fw-bold rounded-pill px-3 py-1">
                                {{ count($unmappedProducts) }} مادة متبقية
                            </span>
                        </div>
                        <div class="card-body pt-0">
                            <div class="input-group mb-3 shadow-sm rounded">
                                <span class="input-group-text bg-light border-end-0 text-muted"><i
                                        class="fa-solid fa-magnifying-glass"></i></span>
                                <input type="text" id="searchProducts" class="form-control border-start-0 bg-light"
                                    placeholder="بحث سريع بالباركود، الاسم أو اسم الشركة...">
                            </div>

                            <div class="table-responsive-custom border rounded">
                                <table class="table table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th style="width: 20%;">الباركود</th>
                                            <th style="width: 35%;">اسم المادة الوارد</th>
                                            <th style="width: 15%;">الشركة</th>
                                            <th style="width: 30%;">الإجراء المقترح</th>
                                        </tr>
                                    </thead>
                                    <tbody id="productTableBody">
                                        @foreach ($unmappedProducts as $index => $prod)
                                            <tr data-barcode="{{ $prod->barcode }}"
                                                data-material="{{ $prod->material_name }}"
                                                data-company="{{ $prod->company }}">
                                                <td>
                                                    <code class="fw-bold text-dark fs-6">{{ $prod->barcode }}</code>
                                                </td>
                                                <td class="text-dark fw-medium">
                                                    {{ $prod->material_name }}
                                                </td>
                                                <td>
                                                    <span class="badge bg-light text-secondary border px-2 py-1.5"><i
                                                            class="fa-solid fa-industry fa-xs me-1 text-muted"></i>{{ $prod->company }}</span>
                                                </td>
                                                <td>
                                                    <select class="product-select form-select"
                                                        data-barcode="{{ $prod->barcode }}"
                                                        data-material="{{ $prod->material_name }}"
                                                        data-company="{{ $prod->company }}">
                                                        <option value="NEW" selected>✨ إنشاء كمادة جديدة برقم
                                                            {{ $prod->barcode }}</option>
                                                    </select>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                @endif

            </div>

            <div class="col-lg-3">
                <div class="page-sticky-summary">
                    <div class="card mapping-card p-3 bg-white text-center mb-3">
                        <h6 class="fw-bold text-secondary mb-3"><i class="fa-solid fa-chart-pie me-1"></i> ملخص
                            المطابقة اليدوية</h6>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <div class="p-2 bg-warning-subtle rounded text-warning-emphasis">
                                    <h4 class="fw-bold mb-0">
                                        {{ isset($unmappedPharmacies) ? count($unmappedPharmacies) : 0 }}</h4>
                                    <span class="small">صيدليات معلقة</span>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-2 bg-danger-subtle rounded text-danger-emphasis">
                                    <h4 class="fw-bold mb-0">
                                        {{ isset($unmappedProducts) ? count($unmappedProducts) : 0 }}</h4>
                                    <span class="small">مواد معلقة</span>
                                </div>
                            </div>
                        </div>

                        <hr class="text-muted my-2">

                        <button type="submit" id="submitBtn"
                            class="btn btn-success w-100 py-2.5 fw-bold rounded-3 shadow-sm">
                            <i class="fa-solid fa-circle-check me-1"></i> تأكيد وحفظ المطابقة
                        </button>
                    </div>

                    <div class="alert alert-light border small text-secondary shadow-sm">
                        <i class="fa-solid fa-lightbulb text-warning me-1"></i> <strong>نصيحة:</strong> يمكنك كتابة
                        حرف واحد في الحقول المنسدلة للبحث الفوري في قاعدة البيانات عن الأسماء المسجلة رسمياً بدلاً
                        من تكرارها.
                    </div>
                </div>
            </div>
        </div>
    </form>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {

            // ✅ تهيئة كسولة (lazy) لـ select2: لا نُهيّئها لكل الصفوف دفعة واحدة عند
            // تحميل الصفحة (قد يصل عددها لآلاف الصفوف)، بل فقط لحظة ما المستخدم
            // يركّز فعلياً على الحقل المطلوب.
            function initSelect2Lazy(selector, apiRoute, placeholderText) {
                $(document).on('focus', selector + ':not(.select2-initialized)', function() {
                    $(this).addClass('select2-initialized').select2({
                        dir: "rtl",
                        placeholder: placeholderText,
                        allowClear: false,
                        minimumInputLength: 1,
                        ajax: {
                            url: apiRoute,
                            dataType: 'json',
                            delay: 300,
                            data: params => ({
                                q: params.term
                            }),
                            processResults: data => data,
                        },
                        language: {
                            inputTooShort: () => 'اكتب حرفاً أو أكثر للبحث السريع...'
                        }
                    }).select2('open');
                });
            }

            initSelect2Lazy('.pharmacy-select', '{{ route('api.pharmacies.search') }}',
                '✨ إنشاء كصيدلية جديدة في النظام');
            initSelect2Lazy('.product-select', '{{ route('api.products.search') }}',
                '✨ إنشاء كمادة جديدة في النظام');

            function attachTableSearch(inputId, tbodyId) {
                const searchInput = document.getElementById(inputId);
                if (searchInput) {
                    searchInput.addEventListener('input', function() {
                        const term = this.value.toLowerCase().trim();
                        document.querySelectorAll('#' + tbodyId + ' tr').forEach(row => {
                            row.style.display = row.innerText.toLowerCase().includes(term) ? '' :
                                'none';
                        });
                    });
                }
            }
            attachTableSearch('searchPharmacies', 'pharmacyTableBody');
            attachTableSearch('searchProducts', 'productTableBody');

            $('body').on('change', '.pharmacy-select, .product-select', function() {
                if (!$(this).val()) {
                    let text = $(this).hasClass('pharmacy-select') ? '✨ إنشاء كصيدلية جديدة في النظام' :
                        '✨ إنشاء كمادة جديدة في النظام';
                    let opt = new Option(text, 'NEW', true, true);
                    $(this).append(opt).trigger('change');
                }
            });

            // ✅ نمنع الإرسال التقليدي ونبني JSON بدل ذلك (لا حد لعدد العناصر خلافاً
            // لمصفوفات الفورم التقليدية المحدودة بـ max_input_vars)
            $('#mappingForm').on('submit', function(e) {
                e.preventDefault();

                const pharmacies = [];
                document.querySelectorAll('.pharmacy-select').forEach(function(select) {
                    pharmacies.push({
                        excel_name: select.dataset.excelName,
                        mapped_id: select.value || 'NEW',
                    });
                });

                const products = [];
                document.querySelectorAll('.product-select').forEach(function(select) {
                    products.push({
                        barcode: select.dataset.barcode,
                        material_name: select.dataset.material,
                        company: select.dataset.company,
                        mapped_id: select.value || 'NEW',
                    });
                });

                document.getElementById('pharmacies_json').value = JSON.stringify(pharmacies);
                document.getElementById('products_json').value = JSON.stringify(products);

                // ✅ شاشة الانتظار الموحدة (overlay كامل الشاشة) لأن الحفظ هنا قد
                // يشمل توليد فواتير لآلاف السجلات ويستغرق وقتاً ملحوظاً.
                if (typeof showGlobalLoading === 'function') {
                    showGlobalLoading(
                        'جاري حفظ المطابقات وتوليد الفواتير، قد تستغرق العملية دقيقة أو أكثر، الرجاء عدم إغلاق الصفحة...'
                        );
                }

                const btn = $('#submitBtn');
                btn.prop('disabled', true);
                btn.html(
                    '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> جاري معالجة البيانات وبناء الروابط...'
                );

                this.submit();
            });
        });
    </script>
@endpush
