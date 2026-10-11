@extends('layouts.admin')

@section('page_title', 'تفاصيل رفع #' . $log->id)
@section('page_subtitle', $log->file_type->label())

@section('topbar_actions')
    <a href="{{ route('import-history.index') }}" class="btn btn-outline-secondary btn-sm rounded-2">
        سجل الرفع <i class="fa-solid fa-arrow-left fa-xs ms-1"></i>
    </a>
@endsection

@section('content')
    <div class="surface-card p-4 shadow-sm">
        <div class="row g-3 mb-4 small">
            <div class="col-md-4"><span class="text-muted d-block">الملف</span><strong class="text-break">{{ $log->original_filename }}</strong></div>
            <div class="col-md-2"><span class="text-muted d-block">رفعه</span><strong>{{ $log->user?->name ?? '—' }}</strong></div>
            <div class="col-md-3"><span class="text-muted d-block">بدأ / انتهى</span>
                <strong>{{ $log->started_at->format('Y-m-d H:i:s') }}</strong> / {{ $log->finished_at?->format('H:i:s') ?? '—' }}</div>
            <div class="col-md-3"><span class="text-muted d-block">الحالة</span><strong>{{ $log->status->label() }}</strong></div>
        </div>

        <div class="row g-2 mb-4">
            <div class="col-4"><x-stat-card icon="fa-circle-check" label="صفوف ناجحة" :value="$log->rows_succeeded ?? 0" unit="صف" /></div>
            <div class="col-4"><x-stat-card icon="fa-forward" label="صفوف متجاهَلة" :value="$log->rows_skipped ?? 0" unit="صف" /></div>
            <div class="col-4"><x-stat-card icon="fa-triangle-exclamation" label="تحذيرات" :value="$log->rows_warned ?? 0" unit="تحذير" /></div>
        </div>

        @if ($log->message)
            <div class="alert alert-light border small mb-4" style="white-space: pre-line;">{{ $log->message }}</div>
        @endif

        @if ($diagnostics && $diagnostics->hasWarnings())
            <h6 class="fw-bold small text-dark mb-2">تفاصيل التحذيرات</h6>
            @foreach (\App\Enums\ImportWarning::cases() as $warning)
                @continue($diagnostics->count($warning) === 0)
                <div class="border rounded-2 mb-3 bg-white">
                    <div class="p-2 border-bottom bg-light small fw-bold">
                        {{ $warning->label() }} — {{ $diagnostics->count($warning) }}
                        @if ($diagnostics->isTruncated($warning))
                            <span class="text-muted fw-normal">(تُعرض أول {{ count($diagnostics->samples($warning)) }} عيّنة)</span>
                        @endif
                    </div>
                    <div class="table-responsive" style="max-height: 280px;">
                        <table class="table table-sm mb-0 small">
                            @php($samples = $diagnostics->samples($warning))
                            <thead><tr>@foreach (array_keys($samples[0] ?? []) as $col)<th>{{ $col }}</th>@endforeach</tr></thead>
                            <tbody>
                                @foreach ($samples as $sample)
                                    <tr>@foreach ($sample as $value)<td>{{ $value }}</td>@endforeach</tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        @endif
    </div>
@endsection
