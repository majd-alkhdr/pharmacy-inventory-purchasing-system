@extends('layouts.admin')

@section('page_title', 'شركات مستثناة من الترميم')
@section('page_subtitle', 'العمليات (المناقلات) — إعداد دائم')

@section('content')

    <!-- 1. تنبيه النجاح/الحالة (Flash Session) -->
    @if (session('status'))
        <div class="alert alert-success bg-success bg-opacity-10 text-success border-0 rounded-3 d-flex align-items-center p-3 mb-4"
            role="alert">
            <i class="fa-solid fa-circle-check fs-5 me-2 flex-shrink-0"></i>
            <div class="small fw-medium">{{ session('status') }}</div>
        </div>
    @endif

    <!-- 2. التنبيه التوضيحي المريح للعين (Left-Accent Card) -->
    <div class="alert bg-primary bg-opacity-10 border-0 border-start border-primary border-4 text-dark mb-4 rounded-end-3 p-3"
        role="alert">
        <div class="d-flex align-items-start">
            <i class="fa-solid fa-circle-info text-primary fs-5 me-2 mt-1 flex-shrink-0"></i>
            <div class="small text-body" style="line-height: 1.7;">
                <strong class="text-primary fw-bold">آلية عمل الاستثناء:</strong>
                أي شركة بهذه القائمة تُعامَل كأنها غير موجودة إطلاقاً بأي ملف مبيعات أو جرد صيدلية تُرفَع لاحقاً — بياناتها
                تُتجاهَل عند الاستيراد نفسه، ولن تُرمَّم أي نواقص قديمة تخصّها (حتى عبر زر "ترميم النواقص" اليدوي) طالما
                بقيت هنا.
                <span class="d-block mt-1 text-secondary fw-semibold">
                    <i class="fa-solid fa-shield-halved text-success me-1"></i> جرد المستودع المركزي لا يتأثر إطلاقاً —
                    رصيدها يبقى مُحدَّثاً بشكل طبيعي دائماً.
                </span>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- 3. النموذج (Form Column) -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm rounded-3 bg-white h-100">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center mb-3">
                        <div class="bg-danger bg-opacity-10 text-danger rounded-2 d-flex align-items-center justify-content-center me-2"
                            style="width: 36px; height: 36px;">
                            <i class="fa-solid fa-ban"></i>
                        </div>
                        <h6 class="fw-bold text-dark mb-0">استثناء شركة جديدة</h6>
                    </div>

                    @if ($errors->any())
                        <div
                            class="alert alert-danger bg-danger bg-opacity-10 text-danger border-0 small rounded-3 p-3 mb-3">
                            <i class="fa-solid fa-triangle-exclamation me-1"></i> {{ $errors->first() }}
                        </div>
                    @endif

                    <form method="POST" action="{{ route('inventory.excluded-companies.add') }}">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-dark mb-2">اسم الشركة</label>
                            <select name="company"
                                class="form-select form-select-md border-light-subtle shadow-none rounded-2" required
                                style="color: #2b2b2b;">
                                <option value="">اختر شركة من القائمة...</option>
                                @forelse ($availableCompanies as $company)
                                    <option value="{{ $company }}">{{ $company }}</option>
                                @empty
                                    <option value="" disabled>لا توجد شركات أخرى متاحة للاستثناء</option>
                                @endforelse
                            </select>
                        </div>

                        <button type="submit"
                            class="btn btn-primary w-100 py-2.5 fw-medium rounded-2 shadow-sm d-flex justify-content-center align-items-center">
                            <i class="fa-solid fa-plus me-2"></i> إضافة للاستثناء
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- 4. الجدول (Table Column) -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm rounded-3 bg-white overflow-hidden">
                <div
                    class="card-header bg-light bg-opacity-50 py-3 px-4 border-bottom border-light d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center">
                        <i class="fa-solid fa-list-check text-primary me-2"></i>
                        <h6 class="fw-bold text-dark mb-0">الشركات المستثناة حالياً</h6>
                    </div>
                    <span class="badge bg-primary bg-opacity-10 text-primary fw-bold px-3 py-2 rounded-pill small">
                        {{ $excluded->count() }} شركة
                    </span>
                </div>

                <div class="table-responsive" style="max-height: 480px; overflow-y: auto;">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th class="py-3 px-4 text-secondary fw-semibold">الشركة</th>
                                <th class="py-3 px-4 text-secondary fw-semibold">مستثناة منذ</th>
                                <th class="py-3 px-4 text-center text-secondary fw-semibold no-print" style="width: 80px;">
                                    إزالة</th>
                            </tr>
                        </thead>
                        <tbody class="border-top-0">
                            @forelse ($excluded as $item)
                                <tr>
                                    <td class="py-3 px-4 fw-bold text-dark fs-6">{{ $item->company }}</td>
                                    <td class="py-3 px-4 text-secondary fw-medium">{{ $item->created_at->format('Y-m-d') }}
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <form method="POST"
                                            action="{{ route('inventory.excluded-companies.remove', $item->id) }}"
                                            onsubmit="return confirm('إزالة هذه الشركة من الاستثناء؟ ستعود تُعامَل بشكل طبيعي بأي رفع قادم.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                class="btn btn-sm btn-light text-danger hover-bg-danger rounded-circle p-0 d-inline-flex align-items-center justify-content-center shadow-none border"
                                                style="width: 32px; height: 32px;" title="إزالة من قائمة الاستثناء">
                                                <i class="fa-solid fa-xmark"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <x-empty-state colspan="3" icon="fa-check"
                                    message="لا توجد شركات مستثناة حالياً — كل الشركات تُرمَّم بشكل طبيعي." />
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

@endsection
