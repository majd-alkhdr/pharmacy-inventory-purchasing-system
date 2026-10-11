@extends('layouts.admin')

@section('page_title', 'أصناف للمراقبة')
@section('page_subtitle', 'العمليات (المناقلات) — متابعة حركة أصناف محدَّدة')

@section('content')

    @if (session('status'))
        <div class="alert alert-light border small mb-3">{{ session('status') }}</div>
    @endif

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="border rounded-2 shadow-sm bg-white p-3">
                <h6 class="fw-bold mb-3"><i class="fa-solid fa-eye text-primary me-1"></i> رفع ملف أصناف جديدة للمراقبة</h6>
                <form method="POST" action="{{ route('inventory.watched-products.upload') }}" enctype="multipart/form-data">
                    @csrf
                    <x-file-upload-zone name="watched_products_file" label="ملف الأصناف" :required="true"
                        accept=".xlsx,.xls,.csv"
                        hint="الترتيب الإجباري: رقم المادة ← اسم المادة (عمودان فقط). الأصناف يجب أن تكون مسجَّلة بالنظام مسبقاً — هذه الشاشة للمراقبة لا لتعريف مواد جديدة." />
                    <button type="submit" class="btn btn-brand w-100 mt-3 fw-medium">
                        <i class="fa-solid fa-upload me-1"></i> رفع وإضافة للمراقبة
                    </button>
                </form>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="border rounded-2 shadow-sm bg-white overflow-hidden">
                <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0"><i class="fa-solid fa-list-check text-primary me-1"></i> الأصناف المراقَبة حالياً</h6>
                    <span class="badge brand-badge px-2 py-1">{{ $watchedProducts->count() }} مادة</span>
                </div>
                <div class="table-responsive" style="max-height: 480px; overflow-y: auto;">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead>
                            <tr>
                                <th>المادة</th>
                                <th>الشركة</th>
                                <th>الرمز</th>
                                <th class="text-center no-print">إزالة</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($watchedProducts as $watched)
                                <tr>
                                    <td class="fw-medium text-dark">{{ $watched->product->name ?? '؟' }}</td>
                                    <td><span class="badge brand-badge px-2 py-1">{{ $watched->product->company ?? '؟' }}</span></td>
                                    <td class="text-muted small">{{ $watched->product->barcode ?? '—' }}</td>
                                    <td class="text-center">
                                        <form method="POST" action="{{ route('inventory.watched-products.remove', $watched->id) }}"
                                            onsubmit="return confirm('إزالة هذه المادة من قائمة المراقبة؟');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-2 py-0">
                                                <i class="fa-solid fa-xmark fa-xs"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <x-empty-state colspan="4" icon="fa-eye-slash" message="لا توجد أصناف مراقَبة بعد — ارفع ملفاً لإضافة أول صنف." />
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if ($watchedProducts->isNotEmpty())
                <a href="{{ route('inventory.reports.product-movement') }}" class="btn btn-outline-secondary btn-sm rounded-2 mt-2">
                    <i class="fa-solid fa-timeline me-1"></i> عرض تقرير حركة هذه الأصناف
                </a>
            @endif
        </div>
    </div>

@endsection
