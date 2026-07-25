@extends('layouts.admin')

@section('page_title', 'مطابقة البيانات اليدوية')
@section('page_subtitle', 'العمليات (المناقلات) — مبيعات')

@push('styles')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <style>
        .select2-container {
            width: 100% !important;
        }

        .select2-container .select2-selection--single {
            height: 38px;
            padding: 4px 8px;
        }
    </style>
@endpush

@section('content')
    <div class="surface-card p-4 shadow-sm">
        <div class="text-center mb-4">
            <span class="badge bg-warning text-dark px-3 py-2 rounded-pill fw-bold mb-2">
                <i class="fa-solid fa-triangle-exclamation me-1"></i> يتطلب إجراء منك
            </span>
            <h4 class="fw-bold text-dark mb-0">توجد أسماء غير مطابقة في ملف اليوم</h4>
        </div>

        <form id="mappingForm" action="{{ route('inventory.save-mapping') }}" method="POST">
            @csrf
            <input type="hidden" name="session_id" value="{{ $sessionId }}">
            <input type="hidden" name="pharmacies_json" id="pharmacies_json">
            <input type="hidden" name="products_json" id="products_json">

            @if (isset($unmappedPharmacies) && count($unmappedPharmacies) > 0)
                <div class="card mb-4 border-warning">
                    <div class="card-header bg-warning text-dark fw-bold">
                        صيدليات غير مسجلة ({{ count($unmappedPharmacies) }})
                    </div>
                    <div class="card-body">
                        <input type="text" id="searchPharmacies" class="form-control mb-2" placeholder="🔍 بحث سريع...">
                        <div style="max-height: 400px; overflow-y: auto;">
                            <table class="table table-bordered align-middle mb-0">
                                <thead class="table-dark sticky-top">
                                    <tr>
                                        <th>الاسم في الإكسل</th>
                                        <th>الإجراء</th>
                                    </tr>
                                </thead>
                                <tbody id="pharmacyTableBody">
                                    @foreach ($unmappedPharmacies as $index => $name)
                                        <tr data-excel-name="{{ $name }}">
                                            <td><strong>{{ $name }}</strong></td>
                                            <td>
                                                <select class="pharmacy-select form-select"
                                                    data-excel-name="{{ $name }}">
                                                    <option value="NEW" selected>✨ إنشاء كصيدلية جديدة</option>
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
                <div class="card mb-4 border-warning">
                    <div class="card-header bg-warning text-dark fw-bold">
                        مواد غير مسجلة ({{ count($unmappedProducts) }})
                    </div>
                    <div class="card-body">
                        <input type="text" id="searchProducts" class="form-control mb-2" placeholder="🔍 بحث سريع...">
                        <div style="max-height: 500px; overflow-y: auto;">
                            <table class="table table-bordered align-middle mb-0">
                                <thead class="table-dark sticky-top">
                                    <tr>
                                        <th>رقم المادة</th>
                                        <th>اسم المادة</th>
                                        <th>الشركة</th>
                                        <th>الإجراء</th>
                                    </tr>
                                </thead>
                                <tbody id="productTableBody">
                                    @foreach ($unmappedProducts as $index => $prod)
                                        <tr data-barcode="{{ $prod->barcode }}" data-material="{{ $prod->material_name }}"
                                            data-company="{{ $prod->company }}">
                                            <td><strong>{{ $prod->barcode }}</strong></td>
                                            <td>{{ $prod->material_name }}</td>
                                            <td><span class="badge bg-secondary">{{ $prod->company }}</span></td>
                                            <td>
                                                <select class="product-select form-select"
                                                    data-barcode="{{ $prod->barcode }}"
                                                    data-material="{{ $prod->material_name }}"
                                                    data-company="{{ $prod->company }}">
                                                    <option value="NEW" selected>✨ إنشاء كمادة جديدة</option>
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

            <div class="text-center">
                <button type="submit" id="submitBtn" class="btn btn-success btn-lg px-5">
                    ✅ تأكيد المطابقة والبدء بالترميم
                </button>
            </div>
        </form>
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        // ✅ تهيئة كسولة (lazy) لـ select2: لا نُهيّئها لكل الصفوف دفعة واحدة عند
        // تحميل الصفحة (قد يصل عددها لآلاف الصفوف ويُجمّد المتصفح)، بل فقط لحظة
        // ما المستخدم يركّز فعلياً على الحقل المطلوب.
        function initSelect2Lazy(selector, apiRoute, placeholderText) {
            $(document).on('focus', selector + ':not(.select2-initialized)', function() {
                $(this).addClass('select2-initialized').select2({
                    placeholder: placeholderText,
                    allowClear: true,
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
                        inputTooShort: () => 'اكتب حرفًا للبحث...'
                    }
                }).select2('open');
            });
        }

        initSelect2Lazy('.pharmacy-select', '{{ route('api.pharmacies.search') }}', '✨ إنشاء كصيدلية جديدة');
        initSelect2Lazy('.product-select', '{{ route('api.products.search') }}', '✨ إنشاء كمادة جديدة');

        function attachTableSearch(inputId, tbodyId) {
            document.getElementById(inputId)?.addEventListener('input', function() {
                const term = this.value.toLowerCase();
                document.querySelectorAll('#' + tbodyId + ' tr').forEach(row => {
                    row.style.display = row.innerText.toLowerCase().includes(term) ? '' : 'none';
                });
            });
        }
        attachTableSearch('searchPharmacies', 'pharmacyTableBody');
        attachTableSearch('searchProducts', 'productTableBody');

        document.getElementById('mappingForm').addEventListener('submit', function(e) {
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

            // ✅ إظهار شاشة الانتظار الموحدة وتعطيل الزر لمنع الإرسال المزدوج
            if (typeof showGlobalLoading === 'function') {
                showGlobalLoading('جاري حفظ المطابقات وتوليد الفواتير، الرجاء الانتظار ولا تُغلق الصفحة...');
            }
            const btn = document.getElementById('submitBtn');
            btn.disabled = true;

            this.submit();
        });
    </script>
@endpush
