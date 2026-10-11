@extends('layouts.admin')

@section('page_title', 'عرض المخزون القياسي')
@section('page_subtitle', 'العمليات (المناقلات) — بحث مادة × صيدلية')

@push('styles')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <style>
        .select2-container { width: 100% !important; }
        .select2-container .select2-selection--single { height: 31px; padding: 1px 6px; }
    </style>
@endpush

@section('content')
    {{-- 🆕 (أ.1) آخر رفعة ناجحة لملف المخزون القياسي — يجيب "هل نجح الرفع؟" قبل أي بحث --}}
    <div class="alert {{ $lastUpload ? 'alert-light border' : 'alert-warning' }} small mb-3 d-flex justify-content-between flex-wrap gap-2">
        <div>
            <i class="fa-solid fa-clock-rotate-left text-primary me-1"></i>
            @if ($lastUpload)
                آخر رفع <strong>ناجح</strong> لملف المخزون القياسي:
                <strong>{{ $lastUpload->displayTime()->format('Y-m-d H:i') }}</strong>
                — {{ $lastUpload->original_filename }}
                — بواسطة {{ $lastUpload->user?->name ?? 'غير معروف' }}
                ({{ $lastUpload->status->label() }}، {{ $lastUpload->rows_succeeded ?? '—' }} صف)
            @else
                لا يوجد رفع ناجح مسجَّل لملف المخزون القياسي بعد.
            @endif
            @if ($lastAttempt && $lastUpload && $lastAttempt->id !== $lastUpload->id)
                <div class="text-danger mt-1">
                    ⚠️ آخر محاولة ({{ $lastAttempt->displayTime()->format('Y-m-d H:i') }}) حالتها: {{ $lastAttempt->status->label() }}
                    — القيم أدناه من الرفع الناجح السابق.
                </div>
            @endif
        </div>
        <a href="{{ route('import-history.index', ['file_type' => 'pharmacy_minimum_stock']) }}" class="small">سجل رفع هذا الملف ←</a>
    </div>

    <div class="filter-bar p-2.5 mb-3">
        <form method="GET" action="{{ route('inventory.standard-stock.lookup') }}" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="small text-secondary fw-semibold mb-1" style="font-size: 0.75rem;">المادة</label>
                <select name="product_id" id="productSelect" class="form-select form-select-sm">
                    @if ($selectedProduct)
                        <option value="{{ $selectedProduct->id }}" selected>{{ $selectedProduct->name }} ({{ $selectedProduct->company }})</option>
                    @endif
                </select>
            </div>
            <div class="col-md-4">
                <label class="small text-secondary fw-semibold mb-1" style="font-size: 0.75rem;">الصيدلية</label>
                <select name="pharmacy_id" id="pharmacySelect" class="form-select form-select-sm">
                    @if ($selectedPharmacy)
                        <option value="{{ $selectedPharmacy->id }}" selected>{{ $selectedPharmacy->name }}</option>
                    @endif
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-brand btn-sm rounded-2 px-3 w-100"><i class="fa-solid fa-magnifying-glass me-1"></i> بحث</button>
                <a href="{{ route('inventory.standard-stock.lookup') }}" class="btn btn-outline-secondary btn-sm rounded-2">تصفير</a>
            </div>
        </form>
        @if ($errors->any())
            <div class="text-danger small mt-2">{{ $errors->first() }}</div>
        @endif
    </div>

    @if ($results === null)
        <div class="text-center text-muted py-5 bg-light border rounded-2">
            <i class="fa-solid fa-magnifying-glass fa-2x mb-2 opacity-50"></i>
            <p class="mb-0 fw-medium">اختر مادة أو صيدلية (أو الاثنين) لعرض المخزون القياسي.</p>
        </div>
    @else
        <div class="border rounded-2 overflow-hidden shadow-sm bg-white">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead>
                        <tr>
                            <th>الصيدلية</th>
                            <th>المادة</th>
                            <th class="text-center">المخزون القياسي</th>
                            <th class="text-center">الرصيد الحالي (آخر كشف)</th>
                            <th class="text-center">الفرق</th>
                            <th class="text-center">حجم العبوة الفعّال</th>
                            <th class="text-center">حد التسامح الفعّال</th>
                            <th class="text-center">آخر تعديل للقيمة</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($results as $row)
                            <tr>
                                <td class="fw-bold">{{ $row->pharmacy->name }}</td>
                                <td>
                                    <span class="d-block text-dark fw-bold">{{ $row->product->name }}</span>
                                    <span class="text-muted" style="font-size: .72rem;">{{ $row->product->barcode }} — {{ $row->product->company }}</span>
                                </td>
                                <td class="text-center fw-bold text-primary fs-6">{{ $row->minimum_quantity }}</td>
                                <td class="text-center">
                                    {{ $row->current_stock ?? '—' }}
                                    @if ($row->current_stock === null)
                                        <span class="d-block text-muted" style="font-size: .68rem;">لا رصيد مرفوع — لا يُقيَّم</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if ($row->current_stock !== null)
                                        @php($diff = $row->current_stock - $row->minimum_quantity)
                                        <span class="{{ $diff < 0 ? 'text-danger' : ($diff > 0 ? 'text-success' : 'text-muted') }} fw-bold">
                                            {{ $diff > 0 ? '+' : '' }}{{ $diff }}
                                        </span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="text-center">{{ $row->effective_pack_size ?: 'بلا قيد' }}</td>
                                <td class="text-center">{{ $row->effective_min_gap }}</td>
                                <td class="text-center text-muted">{{ $row->updated_at?->format('Y-m-d') }}</td>
                            </tr>
                        @empty
                            <x-empty-state colspan="8" icon="fa-circle-question" message="لا يوجد مخزون قياسي مسجَّل يطابق البحث." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-3">{{ $results->links() }}</div>
    @endif
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        function lookupSelect(selector, url, placeholder) {
            $(selector).select2({
                placeholder, allowClear: true, minimumInputLength: 1,
                ajax: { url, dataType: 'json', delay: 300, data: p => ({ q: p.term }), processResults: d => d },
                language: { inputTooShort: () => 'اكتب حرفاً للبحث...' },
            });
        }
        lookupSelect('#productSelect', '{{ route('api.products.search') }}', 'ابحث عن المادة بالاسم أو الشركة...');
        lookupSelect('#pharmacySelect', '{{ route('api.pharmacies.search') }}', 'كل الصيدليات');
    </script>
@endpush
