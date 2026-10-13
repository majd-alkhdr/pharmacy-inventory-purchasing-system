{{-- resources/views/purchasing/manual-sales-rate-mapping.blade.php --}}
@extends('layouts.admin')

@section('page_title', 'مطابقة معدّل المبيعات المعلَّق')
@section('page_subtitle', 'المشتريات — مواد لم تُطابِق منتجاً موجوداً تلقائياً')

@push('styles')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <style>
        .select2-container {
            width: 100% !important;
        }

        .select2-container .select2-selection--single {
            height: 32px;
            padding: 2px 8px;
        }
    </style>
@endpush

@section('content')
    <div class="surface-card p-4 shadow-sm">
        <div class="text-center mb-4">
            <span class="badge bg-warning text-dark px-3 py-2 rounded-pill fw-bold mb-2">
                <i class="fa-solid fa-triangle-exclamation me-1"></i> يتطلب إجراء منك
            </span>
            <h4 class="fw-bold text-dark mb-0">مواد وردت بملف المبيعات ولم تُطابِق منتجاً موجوداً تلقائياً</h4>
            <p class="text-muted small mb-0 mt-1">لكل صف: إما إنشاء منتج جديد بنفس الاسم والشركة كما وردا بالملف، أو ربطه
                بمنتج موجود مشابه (فرق تشكيل/مسافات مثلاً).</p>
        </div>

        <form action="{{ route('purchasing.manual-sales-rate.save-mapping') }}" method="POST">
            @csrf
            <input type="hidden" name="session_id" value="{{ $sessionId }}">

            <div class="border rounded-2 overflow-hidden shadow-sm bg-white">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead>
                            <tr>
                                <th>اسم المادة بالملف</th>
                                <th>اسم الشركة بالملف</th>
                                <th class="text-center">الكمية</th>
                                <th style="width: 40%">القرار</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($pending as $row)
                                <tr class="mapping-row" data-pending-id="{{ $row->id }}">
                                    <td class="fw-bold text-dark">{{ $row->material_name }}</td>
                                    <td class="text-secondary">{{ $row->company }}</td>
                                    <td class="text-center">{{ $row->quantity }}</td>
                                    <td>
                                        <input type="hidden" name="decisions[{{ $row->id }}]"
                                            id="decision_{{ $row->id }}" value="new">
                                        <div class="d-flex flex-column gap-1">
                                            <label class="small">
                                                <input type="radio" name="mode_{{ $row->id }}" value="new"
                                                    checked>
                                                إنشاء منتج جديد بنفس الاسم والشركة
                                            </label>
                                            <div id="barcode_wrapper_{{ $row->id }}">
                                                <input type="text"
                                                    name="barcodes[{{ $row->id }}]"
                                                    id="barcode_input_{{ $row->id }}"
                                                    class="form-control form-control-sm @error("barcodes.{$row->id}") is-invalid @enderror"
                                                    value="{{ old("barcodes.{$row->id}") }}"
                                                    placeholder="باركود المادة الجديدة"
                                                    dir="ltr">
                                                @error("barcodes.{$row->id}")
                                                    <div class="invalid-feedback">{{ $message }}</div>
                                                @enderror
                                            </div>
                                            <label class="small">
                                                <input type="radio" name="mode_{{ $row->id }}" value="existing">
                                                ربط بمنتج موجود
                                            </label>
                                            <div id="search_wrapper_{{ $row->id }}" style="display:none;">
                                                <select id="product_choice_{{ $row->id }}"
                                                    class="form-select form-select-sm product-search-select"></select>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <button type="submit" class="btn btn-success btn-lg w-100 mt-4">
                <i class="fa-solid fa-check-double me-1"></i> حفظ القرارات
            </button>
        </form>
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $('.product-search-select').select2({
            placeholder: 'ابحث عن منتج بالاسم أو الشركة...',
            allowClear: true,
            minimumInputLength: 1,
            ajax: {
                url: '{{ route('purchasing.products-search') }}',
                dataType: 'json',
                delay: 300,
                data: params => ({
                    q: params.term
                }),
                processResults: data => data,
            },
            language: {
                inputTooShort: () => 'اكتب حرفاً للبحث...'
            },
        });

        document.querySelectorAll('.mapping-row').forEach(function(row) {
            const id = row.dataset.pendingId;
            const hidden = document.getElementById('decision_' + id);
            const searchWrapper = document.getElementById('search_wrapper_' + id);
            const searchSelect = document.getElementById('product_choice_' + id);
            const barcodeWrapper = document.getElementById('barcode_wrapper_' + id);
            const barcodeInput = document.getElementById('barcode_input_' + id);

            function sync() {
                const mode = row.querySelector('input[name="mode_' + id + '"]:checked').value;
                if (mode === 'new') {
                    searchWrapper.style.display = 'none';
                    barcodeWrapper.style.display = '';
                    hidden.value = 'new';
                } else {
                    searchWrapper.style.display = '';
                    barcodeWrapper.style.display = 'none';
                    barcodeInput.value = ''; // لا باركود "يتيم" إن بدّل المستخدم رأيه بعد الكتابة
                    hidden.value = $(searchSelect).val() || '';
                }
            }

            row.querySelectorAll('input[name="mode_' + id + '"]').forEach(r => r.addEventListener('change', sync));
            $(searchSelect).on('change', sync);
            sync();
        });
    </script>
@endpush
