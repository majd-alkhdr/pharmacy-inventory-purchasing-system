@extends('layouts.admin')

@section('page_title', 'إدارة الأصناف قيد التصفية')
@section('page_subtitle', 'المشتريات — استثناء يدوي من الشراء')

@push('styles')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <style>
        .select2-container { width: 100% !important; }
        .select2-container .select2-selection--single { height: 38px; padding: 4px 8px; }
    </style>
@endpush

@section('content')
<div class="row g-3" style="max-width: 900px; margin: 0 auto;">

    <div class="col-12">
        <div class="alert alert-light border small text-secondary shadow-sm mb-0">
            <i class="fa-solid fa-circle-info text-primary me-1"></i>
            ضَع أي منتج هنا **قيد التصفية** أثناء تشغيل عرض تصفوي عليه (لتسريع بيع الرصيد الحالي وإنهائه) —
            سيتوقف محرك توليد فواتير الشراء عن اقتراح إعادة طلبه تماماً، بصرف النظر عن أي حساب دوران أو نقص،
            حتى تُلغي هذا العلم يدوياً أو يمر تاريخ الانتهاء المحدَّد (إن حدَّدته).
        </div>
    </div>

    {{-- ═══ إضافة منتج جديد قيد التصفية ═══ --}}
    <div class="col-12">
        <div class="surface-card p-4 shadow-sm">
            <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-tags text-primary me-1"></i> وضع منتج قيد التصفية</h6>
            <form action="{{ route('purchasing.clearance.store') }}" method="POST" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-6">
                    <label class="form-label small fw-bold text-secondary mb-1">المادة</label>
                    <select name="product_id" id="productSelect" class="form-select form-select-sm @error('product_id') is-invalid @enderror" required></select>
                    @error('product_id') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-secondary mb-1">تاريخ انتهاء التصفية (اختياري)</label>
                    <input type="date" name="clearance_until" class="form-control form-control-sm @error('clearance_until') is-invalid @enderror" min="{{ now()->toDateString() }}">
                    @error('clearance_until') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    <div class="form-text small">اتركه فارغاً ليبقى العلم فعّالاً حتى تُلغيه يدوياً.</div>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-brand btn-sm w-100 rounded-2 fw-medium">
                        <i class="fa-solid fa-plus me-1"></i> تفعيل
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- ═══ الأصناف قيد التصفية حالياً ═══ --}}
    <div class="col-12">
        <div class="border rounded-2 overflow-hidden shadow-sm bg-white">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead>
                        <tr>
                            <th>المادة</th>
                            <th>الشركة</th>
                            <th class="text-center">تاريخ الانتهاء</th>
                            <th class="text-center">الإجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($activeClearances as $setting)
                            <tr>
                                <td class="fw-bold text-dark">{{ $setting->product->name }}</td>
                                <td class="text-secondary">{{ $setting->product->company }}</td>
                                <td class="text-center">
                                    @if ($setting->clearance_until)
                                        <span class="badge bg-warning-subtle text-warning-emphasis px-2 py-1 rounded-2">
                                            حتى {{ $setting->clearance_until->format('Y-m-d') }}
                                        </span>
                                    @else
                                        <span class="badge bg-secondary-subtle text-secondary px-2 py-1 rounded-2">بلا تاريخ انتهاء — يدوي فقط</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    <form action="{{ route('purchasing.clearance.destroy', $setting->id) }}" method="POST" onsubmit="return confirm('إلغاء تصفية هذا المنتج؟ سيعود مرشَّحاً طبيعياً بمحرك الشراء فوراً.')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-outline-danger btn-sm rounded-2">
                                            <i class="fa-solid fa-xmark fa-xs me-1"></i> إلغاء التصفية
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <x-empty-state colspan="4" icon="fa-tags" message="لا توجد أصناف قيد التصفية حالياً." />
                        @endforelse
                    </tbody>
                </table>
            </div>
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
</script>
@endpush
