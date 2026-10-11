@extends('layouts.admin')

@section('page_title', 'مراقبة حركة مواد محدَّدة')
@section('page_subtitle', 'التقارير — المناقلات')

@push('styles')
<style>
    .movement-pill {
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        font-size: .7rem;
        font-weight: 700;
        padding: .2rem .6rem;
        border-radius: 999px;
    }

    .movement-forward { background: #eff6ff; color: #1d4ed8; border: 1px solid #dbeafe; }
    .movement-reverse { background: #f0fdf4; color: #15803d; border: 1px solid #dcfce7; }
    .movement-inter { background: #faf5ff; color: #7e22ce; border: 1px solid #f3e8ff; }
</style>
@endpush

@section('content')

    <div class="alert alert-light border small text-secondary mb-3">
        <i class="fa-solid fa-circle-info text-primary me-1"></i>
        يعرض حركة المناقلات (شحن للصيدليات) والمناقلات العكسية فقط للأصناف المضافة صراحة بشاشة
        "أصناف للمراقبة". حركة المشتريات غير مشمولة حالياً.
        <a href="{{ route('inventory.watched-products.form') }}" class="fw-bold">إدارة قائمة المراقبة</a>
    </div>

    @if ($watchedProducts->isEmpty())
        <div class="border rounded-2 bg-white p-4 text-center text-muted">
            <i class="fa-solid fa-eye-slash fa-2x mb-2 d-block"></i>
            لا توجد أصناف مراقَبة بعد.
            <a href="{{ route('inventory.watched-products.form') }}">أضف أول صنف من هنا</a>.
        </div>
    @else
        <form method="GET" action="{{ route('inventory.reports.product-movement') }}" class="filter-bar p-2.5 mb-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label class="small text-secondary fw-semibold mb-1" style="font-size:.75rem">المادة</label>
                    <select name="product_id" class="form-select form-select-sm rounded-2" onchange="this.form.submit()">
                        <option value="">كل الأصناف المراقَبة معاً</option>
                        @foreach ($watchedProducts as $product)
                            <option value="{{ $product->id }}" @selected($selectedProductId == $product->id)>{{ $product->name }} ({{ $product->company }})</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </form>

        <div class="border rounded-2 overflow-hidden shadow-sm bg-white">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead>
                        <tr>
                            <th>التاريخ</th>
                            <th>المادة</th>
                            <th>نوع الحركة</th>
                            <th>من</th>
                            <th>إلى</th>
                            <th class="text-center">الكمية</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr>
                                <td class="text-muted small">{{ \Carbon\Carbon::parse($row->date)->format('Y-m-d') }}</td>
                                <td class="fw-medium text-dark">{{ $row->product_name }}</td>
                                <td>
                                    @if ($row->movement_type === 'forward')
                                        <span class="movement-pill movement-forward"><i class="fa-solid fa-arrow-left"></i> مناقلة</span>
                                    @elseif ($row->movement_type === 'inter_pharmacy')
                                        <span class="movement-pill movement-inter"><i class="fa-solid fa-right-left"></i> بين صيدليتين</span>
                                    @else
                                        <span class="movement-pill movement-reverse"><i class="fa-solid fa-arrow-right"></i> مناقلة عكسية</span>
                                    @endif
                                </td>
                                <td>{{ $row->from_label }}</td>
                                <td>{{ $row->to_label }}</td>
                                <td class="text-center fw-bold">{{ number_format($row->quantity, $row->quantity == (int) $row->quantity ? 0 : 2) }}</td>
                            </tr>
                        @empty
                            <x-empty-state colspan="6" icon="fa-timeline" message="لا توجد حركة مسجَّلة بعد لهذا الاختيار." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

@endsection
