@extends('layouts.admin')

@section('page_title', 'سجل الرفع')
@section('page_subtitle', 'كل الملفات المرفوعة بالقسمين — من رفع، متى، وماذا حدث')

@section('content')
    {{-- آخر رفعة ناجحة لكل نوع ملف --}}
    @if ($latestByType->isNotEmpty())
        <div class="row g-2 mb-3">
            @foreach ($latestByType as $latest)
                <div class="col-md-3 col-sm-6">
                    <a href="{{ route('import-history.show', $latest->id) }}" class="text-decoration-none">
                        <div class="p-2.5 rounded-2 h-100" style="background: var(--c-bg); border: 1px solid var(--c-border);">
                            <div class="small fw-bold text-dark">{{ $latest->file_type->label() }}</div>
                            <div class="text-muted" style="font-size: .72rem;">
                                آخر نجاح: {{ $latest->displayTime()->format('Y-m-d H:i') }}
                                @if ($latest->hasWarnings())
                                    <span class="text-warning">⚠️ {{ $latest->rows_warned }}</span>
                                @endif
                            </div>
                        </div>
                    </a>
                </div>
            @endforeach
        </div>
    @endif

    <div class="filter-bar p-2.5 mb-3">
        <form method="GET" action="{{ route('import-history.index') }}" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="small text-secondary fw-semibold mb-1" style="font-size: 0.75rem;">نوع الملف</label>
                <select name="file_type" class="form-select form-select-sm rounded-2">
                    <option value="">كل الأنواع</option>
                    @foreach ($fileTypes as $type)
                        <option value="{{ $type->value }}" @selected(request('file_type') === $type->value)>{{ $type->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="small text-secondary fw-semibold mb-1" style="font-size: 0.75rem;">الحالة</label>
                <select name="status" class="form-select form-select-sm rounded-2">
                    <option value="">كل الحالات</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="small text-secondary fw-semibold mb-1" style="font-size: 0.75rem;">من</label>
                <input type="date" name="date_from" value="{{ request('date_from') }}" class="form-control form-control-sm rounded-2">
            </div>
            <div class="col-md-2">
                <label class="small text-secondary fw-semibold mb-1" style="font-size: 0.75rem;">إلى</label>
                <input type="date" name="date_to" value="{{ request('date_to') }}" class="form-control form-control-sm rounded-2">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-brand btn-sm rounded-2 w-100"><i class="fa-solid fa-filter fa-xs me-1"></i> تصفية</button>
                <a href="{{ route('import-history.index') }}" class="btn btn-outline-secondary btn-sm rounded-2">تصفير</a>
            </div>
        </form>
    </div>

    <div class="border rounded-2 overflow-hidden shadow-sm bg-white">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead>
                    <tr>
                        <th>النوع</th>
                        <th>اسم الملف</th>
                        <th>رفعه</th>
                        <th>الوقت</th>
                        <th>الحالة</th>
                        <th class="text-center">ناجح</th>
                        <th class="text-center">متجاهَل</th>
                        <th class="text-center">تحذيرات</th>
                        <th class="text-center">تفاصيل</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr>
                            <td class="fw-bold">{{ $log->file_type->label() }}</td>
                            <td class="text-break" style="max-width: 220px;">{{ $log->original_filename }}</td>
                            <td>{{ $log->user?->name ?? '—' }}</td>
                            <td class="text-nowrap">{{ $log->displayTime()->format('Y-m-d H:i') }}</td>
                            <td>{{ $log->status->label() }}</td>
                            <td class="text-center">{{ $log->rows_succeeded ?? '—' }}</td>
                            <td class="text-center">{{ $log->rows_skipped ?? '—' }}</td>
                            <td class="text-center {{ $log->hasWarnings() ? 'text-warning fw-bold' : '' }}">{{ $log->rows_warned ?? '—' }}</td>
                            <td class="text-center">
                                <a href="{{ route('import-history.show', $log->id) }}" class="btn btn-outline-secondary btn-sm rounded-2 px-2 py-1"><i class="fa-solid fa-eye fa-xs"></i></a>
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="9" icon="fa-file-circle-question" message="لا توجد رفوعات تطابق الفلترة." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $logs->links() }}</div>
@endsection
