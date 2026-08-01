@extends('layouts.admin')

@section('page_title', $supplier->name)
@section('page_subtitle', 'المشتريات — إدارة الشركات والأسعار والعروض')

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
        .company-chip {
            display: inline-flex; align-items: center; gap: 6px;
            background: var(--c-primary-soft); color: var(--c-primary-dark);
            border: 1px solid #bae6fd; border-radius: 999px; padding: 4px 12px; font-size: 0.78rem; margin: 3px;
        }
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

    {{-- 🆕 ═══ الشركات المفضّلة — أساس اختيار المورد الجديد بمحرك التوليد ═══ --}}
    <div class="p-3 mb-4 rounded-2" style="background: var(--c-primary-soft); border: 1px solid #bae6fd;">
        <h6 class="fw-bold text-dark mb-2 small">
            <i class="fa-solid fa-industry text-primary me-1"></i> الشركات المصنّعة المفضّلة لهذا المورد
        </h6>
        <p class="text-muted small mb-3" style="font-size: 0.78rem;">
            هذه القائمة هي أساس اختيار المورد تلقائياً عند توليد فواتير الشراء — أي منتج تنتمي شركته المصنّعة لإحدى
            هذه القائمة، يُقترَح هذا المورد له تلقائياً. لو أكثر من مورد يشارك نفس الشركة، سيُطلَب اختيار يدوي وقت
            التوليد لتلك الدفعة فقط.
        </p>

        <div class="mb-3">
            @forelse ($supplier->preferredCompanies as $company)
                <span class="company-chip">
                    <span>{{ $company->company_name }}</span>
                    <form action="{{ route('purchasing.suppliers.companies.destroy', [$supplier->id, $company->id]) }}" method="POST" class="d-inline">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-link btn-sm text-danger p-0" style="font-size: 0.72rem;" onclick="return confirm('حذف هذه الشركة من قائمة المفضّلات؟')">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </form>
                </span>
            @empty
                <span class="text-muted small">لا توجد شركات مفضّلة مضافة بعد — أضف واحدة أدناه.</span>
            @endforelse
        </div>

        <form action="{{ route('purchasing.suppliers.companies.store', $supplier->id) }}" method="POST" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-9">
                <select name="company_name" id="companySelect" class="form-select form-select-sm @error('company_name') is-invalid @enderror" required></select>
                @error('company_name') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-brand btn-sm w-100 rounded-2"><i class="fa-solid fa-plus me-1"></i> إضافة شركة</button>
            </div>
        </form>
    </div>

    {{-- الحقل القديم (محسوبة حياً من supplier_products — معلوماتية بحتة الآن) --}}
    @php($companies = $supplier->distributedCompanies())
    @if (!empty($companies))
        <div class="mb-4">
            <span class="text-muted small d-block mb-1">📌 (قديم/معلوماتي) شركات محسوبة حياً من مواد مرتبطة أصلاً بجدول الأسعار القديم — لا تُستخدم بمنطق الاختيار الفعلي بعد اليوم:</span>
            @foreach ($companies as $company)
                <span class="badge badge-soft-primary px-2 py-1 me-1">{{ $company }}</span>
            @endforeach
        </div>
    @endif

    {{-- ═══ إضافة مادة جديدة لهذا المورد (يبقى كما هو — تمهيداً لعودة الأسعار) ═══ --}}
    <div class="p-3 mb-4 rounded-2" style="background: var(--c-bg); border: 1px solid var(--c-border);">
        <h6 class="fw-bold text-dark mb-2 small"><i class="fa-solid fa-plus-circle text-primary me-1"></i> ربط مادة جديدة بهذا المورد (اختياري حالياً — بلا استخدام فعلي بالتوليد)</h6>
        <p class="text-muted small mb-2" style="font-size: 0.72rem;">💡 شركة المادة المصنّعة تُؤخَذ تلقائياً من بطاقة المادة نفسها فور اختيارها أدناه — لا حاجة لإدخالها هنا مجدداً.</p>
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

    {{-- ═══ جدول المواد المرتبطة + العروض (يبقى كما هو بلا حذف) ═══ --}}
    <div class="border rounded-2 overflow-hidden shadow-sm bg-white">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead>
                    <tr>
                        <th style="width: 28%">المادة</th>
                        <th class="text-center" style="width: 10%">سعر الوحدة</th>
                        <th class="text-center" style="width: 7%">مفضّل</th>
                        <th style="width: 40%">العروض النشطة (بونص + خصم)</th>
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
                                        <span>من {{ $offer->min_quantity }}+: {{ $offer->label() }}</span>
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
                                        <label class="form-label small text-secondary mb-1">بونص (كمية مجانية)</label>
                                        <input type="number" name="bonus_quantity" min="0" class="form-control form-control-sm" placeholder="0">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small text-secondary mb-1">نسبة خصم %</label>
                                        <input type="number" step="0.01" min="0" max="100" name="discount_percent" class="form-control form-control-sm" placeholder="0">
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
                                    <div class="col-12 form-text small">يمكن ملء الحقلين معاً (بونص + خصم بنفس العرض)، أو أحدهما فقط — يجب توفر أحدهما على الأقل.</div>
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

    {{-- 🆕 select2 حر (Tags) لاسم الشركة — يسمح بكتابة اسم جديد مباشرة
         بدل اختيار من قائمة، لأن "الشركة" هنا نص حر يطابق products.company --}}
    $('#companySelect').select2({
        placeholder: 'اكتب اسم الشركة المصنّعة...',
        tags: true,
        allowClear: true,
        minimumInputLength: 1,
        ajax: {
            url: '{{ route('api.products.search') }}',
            dataType: 'json',
            delay: 300,
            data: params => ({ q: params.term }),
            processResults: data => ({
                results: [...new Set(data.results.map(r => r.text.match(/\(([^)]+)\)$/)?.[1]).filter(Boolean))]
                    .map(company => ({ id: company, text: company }))
            }),
        },
        language: { inputTooShort: () => 'اكتب حرفاً للبحث عن شركة موجودة، أو اكتب اسماً جديداً بالكامل...' },
    });

    function toggleOfferForm(spId) {
        document.getElementById('offer-form-' + spId)?.classList.toggle('is-open');
    }
</script>
@endpush
