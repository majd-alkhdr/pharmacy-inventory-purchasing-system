@extends('layouts.admin')

@section('page_title', 'مطابقة روابط التصنيف المعلّقة')
@section('page_subtitle', 'المشتريات — تصنيف المنتجات')

@section('content')
<div class="surface-card p-4 shadow-sm">
    <div class="text-center mb-4">
        <span class="badge bg-warning text-dark px-3 py-2 rounded-pill fw-bold mb-2">
            <i class="fa-solid fa-triangle-exclamation me-1"></i> يتطلب إجراء منك
        </span>
        <h4 class="fw-bold text-dark mb-0">منتجات وردت لها تصنيفات غير معروفة بالقائمة الرسمية</h4>
        <p class="text-muted small mb-0 mt-1">اختر تصنيفاً موجوداً لكل منتج، أو اتركه "تجاهل". لربط منتج بأكثر من تصنيف، أعد استيراد ملف الربط بسطر إضافي له لاحقاً.</p>
    </div>

    <form action="{{ route('purchasing.categories.save-mapping') }}" method="POST">
        @csrf
        <input type="hidden" name="session_id" value="{{ $sessionId }}">

        <div class="border rounded-2 overflow-hidden shadow-sm bg-white">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead>
                        <tr>
                            <th>الباركود</th>
                            <th>اسم المادة</th>
                            <th>التصنيف المطلوب بالملف (غير معروف)</th>
                            <th style="width: 30%">اربطه بتصنيف موجود</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pending as $row)
                            <tr>
                                <td>{{ $row->product->barcode }}</td>
                                <td class="fw-bold text-dark">{{ $row->product->name }}</td>
                                <td><span class="badge bg-danger-subtle text-danger px-2 py-1">{{ $row->requested_category_name }}</span></td>
                                <td>
                                    <select name="decisions[{{ $row->id }}]" class="form-select form-select-sm">
                                        <option value="skip">— تجاهل (بلا تصنيف) —</option>
                                        @foreach ($categories as $category)
                                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <button type="submit" class="btn btn-success btn-lg w-100 mt-4">
            <i class="fa-solid fa-check-double me-1"></i> حفظ المطابقة
        </button>
    </form>
</div>
@endsection
