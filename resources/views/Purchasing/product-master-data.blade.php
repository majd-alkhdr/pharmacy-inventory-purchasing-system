@extends('layouts.admin')

@section('page_title', 'بيانات المادة')
@section('page_subtitle', 'المشتريات — تحقق وتصحيح يدوي سريع')

@push('styles')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <style>
        .select2-container { width: 100% !important; }
        .select2-container .select2-selection--single { height: 31px; padding: 1px 6px; }
    </style>
@endpush

@section('content')
    <div class="alert alert-warning small mb-3">
        <i class="fa-solid fa-triangle-exclamation me-1"></i>
        هذه شاشة <strong>تحقق</strong>، لا تجاوز تشغيلي: التصحيح هنا مؤقت، وأول رفع لاحق لـ<strong>ملف الأصناف</strong>
        يحمل قيمة للحقل يكتب فوقه (الخلية الفارغة لا تمس القيمة المخزَّنة). رصيد المستودع المركزي للعرض فقط —
        مصدره الوحيد كشف المستودعات، ومنه تُولَّد فواتير المناقلات.
    </div>

    <div class="filter-bar p-2.5 mb-3">
        <form method="GET" action="{{ route('purchasing.product-master-data.show') }}" class="row g-2 align-items-end">
            <div class="col-md-8">
                <label class="small text-secondary fw-semibold mb-1" style="font-size: 0.75rem;">المادة</label>
                <select name="product_id" id="productSelect" class="form-select form-select-sm">
                    @if ($product)
                        <option value="{{ $product->id }}" selected>{{ $product->name }} ({{ $product->company }})</option>
                    @endif
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-brand btn-sm rounded-2 px-3 w-100"><i class="fa-solid fa-magnifying-glass me-1"></i> عرض</button>
            </div>
        </form>
    </div>

    @if (!$product)
        <div class="text-center text-muted py-5 bg-light border rounded-2">
            <i class="fa-solid fa-box-open fa-2x mb-2 opacity-50"></i>
            <p class="mb-0 fw-medium">اختر مادة لعرض بياناتها ومصدر كل حقل.</p>
        </div>
    @else
        <form method="POST" action="{{ route('purchasing.product-master-data.update', $product) }}">
            @csrf
            @method('PUT')

            <div class="border rounded-2 overflow-hidden shadow-sm bg-white mb-3">
                <div class="px-3 py-2 border-bottom bg-light d-flex justify-content-between flex-wrap gap-2">
                    <span class="fw-bold">{{ $product->name }}</span>
                    <span class="text-muted small">رمز المادة: <span class="font-monospace">{{ $product->barcode }}</span></span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0 small">
                        <thead>
                            <tr>
                                <th>الحقل</th>
                                <th class="text-center">القيمة الحالية</th>
                                <th>آخر تحديث</th>
                                <th style="min-width: 190px;">تصحيح (فارغ = بلا تغيير)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($fields as $row)
                                @php($name = $row->field->value)
                                @php($numeric = !in_array($name, ['name', 'company'], true))
                                <tr>
                                    <td class="fw-semibold">
                                        {{ $row->field->label() }}
                                        <span class="d-block text-muted" style="font-size: .68rem;">يكتب فوقه: {{ $row->field->sourceFile()->label() }}</span>
                                    </td>
                                    <td class="text-center fw-bold">
                                        @if ($row->value === null)
                                            <span class="text-muted">—</span>
                                        @elseif ($name === 'price')
                                            {{ number_format((float) $row->value, 2) }}
                                        @else
                                            {{ $row->value }}
                                        @endif
                                    </td>
                                    <td>
                                        @php($src = $row->source)
                                        @if ($src === null)
                                            <span class="text-muted">لا سجل — قبل بدء التتبع</span>
                                        @elseif ($src->kind === 'manual')
                                            <span class="badge bg-warning text-dark">تصحيح يدوي</span>
                                            {{ $src->actorName() ?? 'غير معروف' }}
                                            <span class="d-block text-muted" style="font-size: .7rem;">{{ $src->at->format('Y-m-d H:i') }}</span>
                                        @elseif ($src->kind === 'upload')
                                            <span class="badge bg-light text-dark border">رفع</span>
                                            <a href="{{ route('import-history.show', $src->importLog->id) }}">{{ $src->importLog->original_filename }}</a>
                                            <span class="d-block text-muted" style="font-size: .7rem;">
                                                {{ $src->importLog->file_type->label() }} — {{ $src->at->format('Y-m-d H:i') }}
                                                — {{ $src->actorName() ?? 'غير معروف' }}
                                            </span>
                                        @else
                                            <span class="badge bg-light text-dark border">ملف آخر</span>
                                            <span class="d-block text-muted" style="font-size: .7rem;">{{ $src->at->format('Y-m-d H:i') }} — مصدر لا يُسجَّل لكل مادة</span>
                                        @endif
                                        @if ($name === 'central_quantity')
                                            <span class="d-block text-muted" style="font-size: .68rem;">تخصم منه فواتير المناقلات بين رفعتين.</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if (!$row->field->isEditable())
                                            <span class="text-muted" style="font-size: .75rem;">
                                                <i class="fa-solid fa-lock me-1"></i> للعرض فقط — يُحدَّث من {{ $row->field->sourceFile()->label() }} وحده
                                            </span>
                                        @else
                                        <input type="{{ $numeric ? 'number' : 'text' }}" name="{{ $name }}"
                                            value="{{ old($name) }}"
                                            placeholder="{{ $row->value ?? '' }}"
                                            @if ($numeric) min="{{ $name === 'pack_size' ? 1 : 0 }}" step="{{ $name === 'price' ? '0.01' : '1' }}" @endif
                                            class="form-control form-control-sm @error($name) is-invalid @enderror">
                                        @error($name)
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="px-3 py-2 border-top d-flex justify-content-end">
                    <button type="submit" class="btn btn-brand btn-sm rounded-2 px-4"><i class="fa-solid fa-floppy-disk me-1"></i> حفظ التصحيح</button>
                </div>
            </div>
        </form>

        <div class="border rounded-2 overflow-hidden shadow-sm bg-white">
            <div class="px-3 py-2 border-bottom bg-light fw-bold small">سجل التصحيحات اليدوية لهذه المادة</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0 small">
                    <thead>
                        <tr>
                            <th>الوقت</th>
                            <th>المستخدم</th>
                            <th>الحقل</th>
                            <th class="text-center">قبل</th>
                            <th class="text-center">بعد</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($corrections as $entry)
                            @php($fieldEnum = \App\Enums\ProductMasterField::tryFrom($entry->context['field'] ?? ''))
                            <tr>
                                <td class="text-muted">{{ $entry->created_at->format('Y-m-d H:i') }}</td>
                                <td>{{ $entry->user?->name ?? 'غير معروف' }}</td>
                                <td>{{ $fieldEnum?->label() ?? ($entry->context['field'] ?? '—') }}</td>
                                <td class="text-center">{{ $entry->old_values[$fieldEnum?->value] ?? '—' }}</td>
                                <td class="text-center fw-bold">{{ $entry->new_values[$fieldEnum?->value] ?? '—' }}</td>
                            </tr>
                        @empty
                            <x-empty-state colspan="5" icon="fa-clock-rotate-left" message="لا تصحيحات يدوية لهذه المادة." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $('#productSelect').select2({
            placeholder: 'ابحث عن المادة بالاسم أو الشركة...', minimumInputLength: 1,
            ajax: { url: '{{ route('purchasing.products-search') }}', dataType: 'json', delay: 300, data: p => ({ q: p.term }), processResults: d => d },
            language: { inputTooShort: () => 'اكتب حرفاً للبحث...' },
        });
    </script>
@endpush
