@extends('layouts.admin')

@section('page_title', $supplier->name)
@section('page_subtitle', 'المشتريات — إدارة الأسعار والعروض')

@push('styles')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <style>
        .select2-container { width: 100% !important; }
        .select2-container .select2-selection--single { height: 38px; padding: 4px 8px; }
        .offer-chip {
            display: inline-flex; align-items: center; gap: 6px;
            background: var(--c-bg); border: 1px solid var(--c-border);
            border-radius: 999px; padding: 3px 10px; font-size: 0.72rem; margin: 2px;
        }
        .offer-add-row { display: none; background: var(--c-bg); }
        .offer-add-row.is-open { display: table-row; }
    </style>
@endpush

@section('topbar_actions')
    <a href="{{ route('purchasing.suppliers.edit', $supplier->id) }}" class="btn btn-outline-secondary btn-sm rounded-2">
        <i class="fa-solid fa-pen fa-xs me-1"></i> تعديل بيانات المورد
    </a>
    <a href="{{ route('purchasing.suppliers.index') }}" class="btn btn-outline-secondary btn-sm rounded-2">
        كل الموردين
    </a>
@endsection

@section('content')
<div class="surface-card p-4 shadow-sm">

    {{-- ═══ معلومات المورد ═══ --}}
    <div class="row mb-4 pb-3 border-bottom">
        <div class="col-md-3"><span class="text-muted small d-block">جهة الاتصال</span><span class="fw-bold">{{ $supplier->contact_person ?: '—' }}</span></div>
        <div class="col-md-3"><span class="text-muted small d-block">الهاتف</span><span class="fw-bold">{{ $supplier->phone ?: '—' }}</span></div>
        <div class="col-md-3"><span class="text-muted small d-block">فترة التوريد</span><span class="fw-bold">{{ $supplier->lead_time_days }} يوماً</span></div>
        <div class="col-md-3"><span class="text-muted small d-block">الحالة</span>
            @if ($supplier->is_active)
                <span class="badge bg-success-subtle text-success px-2 py-1 rounded-2">فعّال</span>
            @else
                <span class="badge bg-secondary-subtle text-secondary px-2 py-1 rounded-2">معطَّل</span>
            @endif
        </div>
    </div>

    {{-- ═══ إضافة مادة جديدة لهذا المورد ═══ --}}
    <div class="p-3 mb-4 rounded-2" style="background: var(--c-bg); border: 1px solid var(--c-border);">
        <h6 class="fw-bold text-dark mb-2 small"><i class="fa-solid fa-plus-circle text-primary me-1"></i> ربط مادة جديدة بهذا المورد</h6>
        <form action="{{ route('purchasing.suppliers.products.store', $supplier->id) }}" method="POST" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-6">
                <label class="form-label small fw-bold text-secondary mb-1">المادة</label>
                <select name="product_id" id="productSelect" class="form-select form-select-sm @error('product_id') is-invalid @enderror" required></select>
                @error('product_id') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold text-secondary mb-1">سعر الوحدة</label>
                <input type="number" step="0.01" min="0" name="unit_cost" class="form-control form-control-sm @error('unit_cost') is-invalid @enderror" required>
                @error('unit_cost') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-2">
                <div class="form-check mt-4">
                    <input type="checkbox" name="is_preferred" value="1" class="form-check-input" id="isPreferred">
                    <label class="form-check-label small" for="isPreferred">مفضّل</label>
                </div>
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-brand btn-sm w-100 rounded-2"><i class="fa-solid fa-plus"></i></button>
            </div>
        </form>
    </div>

    {{-- ═══ جدول المواد المرتبطة + العروض ═══ --}}
    <div class="border rounded-2 overflow-hidden shadow-sm bg-white">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead>
                    <tr>
                        <th style="width: 30%">المادة</th>
                        <th class="text-center" style="width: 12%">سعر الوحدة</th>
                        <th class="text-center" style="width: 8%">مفضّل</th>
                        <th style="width: 35%">العروض النشطة</th>
                        <th class="text-center" style="width: 15%">الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($supplier->products as $sp)
                        <tr>
                            <td>
                                <span class="d-block fw-bold text-dark">{{ $sp->product->name }}</span>
                                <span class="text-muted" style="font-size: 0.72rem;">{{ $sp->product->company }} — {{ $sp->product->barcode }}</span>
                            </td>
                            <td class="text-center fw-bold">{{ number_format($sp->unit_cost, 2) }}</td>
                            <td class="text-center">
                                @if ($sp->is_preferred)
                                    <i class="fa-solid fa-star text-warning"></i>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                @forelse ($sp->offers as $offer)
                                    <span class="offer-chip">
                                        <span>من {{ $offer->min_quantity }}+: {{ $offer->bonus_type->label() }} ({{ $offer->bonus_value }})</span>
                                        <form action="{{ route('purchasing.supplier-products.offers.destroy', [$sp->id, $offer->id]) }}" method="POST" class="d-inline">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-link btn-sm text-danger p-0" style="font-size: 0.7rem;" onclick="return confirm('حذف هذا العرض؟')">
                                                <i class="fa-solid fa-xmark"></i>
                                            </button>
                                        </form>
                                    </span>
                                @empty
                                    <span class="text-muted" style="font-size: 0.72rem;">لا توجد عروض</span>
                                @endforelse
                                <button type="button" class="btn btn-link btn-sm p-0 d-block mt-1" style="font-size: 0.7rem;" onclick="toggleOfferForm({{ $sp->id }})">
                                    <i class="fa-solid fa-plus fa-xs"></i> إضافة عرض
                                </button>
                            </td>
                            <td class="text-center">
                                <form action="{{ route('purchasing.suppliers.products.destroy', [$supplier->id, $sp->id]) }}" method="POST" onsubmit="return confirm('إلغاء ربط هذه المادة بالمورد؟')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-outline-danger btn-sm rounded-2">
                                        <i class="fa-solid fa-trash-can fa-xs"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <tr id="offer-form-{{ $sp->id }}" class="offer-add-row">
                            <td colspan="5" class="p-3">
                                <form action="{{ route('purchasing.supplier-products.offers.store', $sp->id) }}" method="POST" class="row g-2 align-items-end">
                                    @csrf
                                    <div class="col-md-2">
                                        <label class="form-label small text-secondary mb-1">الحد الأدنى للكمية</label>
                                        <input type="number" name="min_quantity" min="1" class="form-control form-control-sm" required>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small text-secondary mb-1">نوع العرض</label>
                                        <select name="bonus_type" class="form-select form-select-sm" required>
                                            <option value="free_quantity">كمية مجانية</option>
                                            <option value="percent_discount">نسبة خصم %</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small text-secondary mb-1">القيمة</label>
                                        <input type="number" step="0.01" min="0" name="bonus_value" class="form-control form-control-sm" required>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small text-secondary mb-1">من تاريخ</label>
                                        <input type="date" name="starts_at" class="form-control form-control-sm">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small text-secondary mb-1">إلى تاريخ</label>
                                        <input type="date" name="ends_at" class="form-control form-control-sm">
                                    </div>
                                    <div class="col-md-2">
                                        <button type="submit" class="btn btn-brand btn-sm w-100 rounded-2">إضافة العرض</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="5" icon="fa-boxes-stacked" message="لا توجد مواد مرتبطة بهذا المورد بعد." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
    $('#productSelect').select2({
        placeholder: 'ابحث عن المادة بالاسم أو الشركة...',
        allowClear: true,
        minimumInputLength: 1,
        ajax: {
            url: '{{ route('api.products.search') }}',
            dataType: 'json',
            delay: 300,
            data: params => ({ q: params.term }),
            processResults: data => data,
        },
        language: { inputTooShort: () => 'اكتب حرفاً للبحث...' },
    });

    function toggleOfferForm(spId) {
        document.getElementById('offer-form-' + spId)?.classList.toggle('is-open');
    }
</script>
@endpush
