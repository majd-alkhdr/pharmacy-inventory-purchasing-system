@extends('layouts.admin')

@section('page_title', 'مواد تحتاج تسوية من المالية')
@section('page_subtitle', 'العمليات (المناقلات) — فروقات مطابقة التحضير المصنَّفة "رصيد وهمي"')

@section('topbar_actions')
    <a href="{{ route('inventory.finance-settlements.export') }}" class="btn btn-success btn-sm rounded-2 fw-medium text-nowrap">
        <i class="fa-solid fa-file-excel me-1"></i> تصدير الجديد ({{ $pendingCount }})
    </a>
    <a href="{{ route('inventory.finance-settlements.export', ['all' => 1]) }}" class="btn btn-outline-success btn-sm rounded-2 text-nowrap">
        تصدير الكل
    </a>
@endsection

@section('content')
    <div class="alert alert-light border small text-secondary mb-3 d-flex justify-content-between flex-wrap gap-2">
        <span>
            <i class="fa-solid fa-circle-info text-primary me-1"></i>
            تنشأ تلقائياً عند اختيار سبب فرق يتطلب تسوية مالية بشاشة مطابقة التحضير. "تصدير الجديد" يُصدّر ما لم يُرسل بعد
            ويُعلّمه مُرسلاً — ثم أرسل الملف للمالية.
        </span>
        <a href="{{ route('inventory.finance-settlements.index', ['show_exported' => $showExported ? 0 : 1]) }}">
            {{ $showExported ? 'إخفاء المُصدَّر سابقاً' : 'عرض المُصدَّر سابقاً أيضاً' }}
        </a>
    </div>

    <div class="border rounded-2 overflow-hidden shadow-sm bg-white">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead>
                    <tr>
                        <th>الفاتورة</th>
                        <th>الصيدلية</th>
                        <th>المادة</th>
                        <th class="text-center">الكمية</th>
                        <th>السبب</th>
                        <th>الإحالة</th>
                        <th>التصدير</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $item)
                        <tr>
                            <td><a href="{{ route('inventory.invoice.reconciliation', $item->invoice_id) }}">#{{ $item->invoice_id }}</a>
                                <span class="d-block text-muted" style="font-size: .7rem;">{{ $item->invoice?->date?->format('Y-m-d') }}</span></td>
                            <td class="fw-bold">{{ $item->pharmacy->name }}</td>
                            <td>{{ $item->product->name }} <span class="d-block text-muted" style="font-size: .7rem;">{{ $item->product->barcode }}</span></td>
                            <td class="text-center fw-bold">{{ $item->quantity }}</td>
                            <td>{{ $item->reason->label }}</td>
                            <td class="text-nowrap">{{ $item->created_at->format('Y-m-d H:i') }}</td>
                            <td>{{ $item->exported_at?->format('Y-m-d H:i') ?? 'لم تُرسل بعد' }}</td>
                        </tr>
                    @empty
                        <x-empty-state colspan="7" icon="fa-check-double" message="لا توجد مواد بانتظار التسوية." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $items->links() }}</div>
@endsection
