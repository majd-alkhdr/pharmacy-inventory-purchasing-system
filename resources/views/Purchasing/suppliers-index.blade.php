@extends('layouts.admin')

@section('page_title', 'الموردون')
@section('page_subtitle', 'المشتريات')

@section('topbar_actions')
    <a href="{{ route('purchasing.suppliers.create') }}" class="btn btn-brand btn-sm rounded-2 px-3">
        <i class="fa-solid fa-plus fa-xs me-1"></i> إضافة مورد جديد
    </a>
@endsection

@section('content')
<div class="surface-card p-4 shadow-sm">

    <form method="GET" class="mb-3">
        <div class="input-group" style="max-width: 320px;">
            <span class="input-group-text bg-light border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
            <input type="text" name="search" value="{{ request('search') }}" class="form-control border-start-0"
                placeholder="بحث باسم المورد..." onchange="this.form.submit()">
        </div>
    </form>

    <div class="border rounded-2 overflow-hidden shadow-sm bg-white">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead>
                    <tr>
                        <th>اسم المورد</th>
                        <th>جهة الاتصال</th>
                        <th class="text-center">فترة التوريد</th>
                        <th class="text-center">عدد المواد المرتبطة</th>
                        <th class="text-center">الحالة</th>
                        <th class="text-center">الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($suppliers as $supplier)
                        <tr>
                            <td class="fw-bold text-dark">{{ $supplier->name }}</td>
                            <td class="text-secondary">
                                {{ $supplier->contact_person ?: '—' }}
                                @if ($supplier->phone)
                                    <span class="d-block text-muted" style="font-size: 0.72rem;">{{ $supplier->phone }}</span>
                                @endif
                            </td>
                            <td class="text-center text-secondary">{{ $supplier->lead_time_days }} يوماً</td>
                            <td class="text-center fw-bold">{{ $supplier->products_count }}</td>
                            <td class="text-center">
                                @if ($supplier->is_active)
                                    <span class="badge bg-success-subtle text-success px-2 py-1 rounded-2">فعّال</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary px-2 py-1 rounded-2">معطَّل</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <a href="{{ route('purchasing.suppliers.show', $supplier->id) }}" class="btn btn-outline-primary btn-sm rounded-2 px-2.5 fw-medium">
                                    إدارة الأسعار والعروض
                                </a>
                                <a href="{{ route('purchasing.suppliers.edit', $supplier->id) }}" class="btn btn-outline-secondary btn-sm rounded-2 px-2">
                                    <i class="fa-solid fa-pen fa-xs"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="6" icon="fa-truck-field" message="لا يوجد موردون بعد — أضف أول مورد لتبدأ بربط المواد وأسعارها." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3 d-flex justify-content-center">
        {{ $suppliers->links('pagination::bootstrap-5') }}
    </div>
</div>
@endsection
