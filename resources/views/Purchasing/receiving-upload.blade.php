@extends('layouts.admin')

@section('page_title', 'تسجيل استلام فعلي')
@section('page_subtitle', 'المشتريات — قنص نواقص السوق')

@section('content')
<div class="surface-card p-4 shadow-sm" style="max-width: 700px; margin: 0 auto;">

    <div class="mb-4">
        <h6 class="fw-bold text-dark mb-1"><i class="fa-solid fa-truck-ramp-box text-primary me-1"></i> تسجيل ما تم استلامه فعلياً بالمستودع</h6>
        <p class="text-muted small mb-0">
            ارفع ملف الاستلام الخاص بفاتورة شراء مُرسَلة للمورد. سيقارن النظام الكميات المستلمة
            بالكميات المطلوبة، ويُحدِّث المخزون تلقائياً، ويكتشف أي أصناف "مقطوعة من السوق".
        </p>
    </div>

    @if ($orders->isEmpty())
        <div class="alert alert-warning small">لا توجد حالياً أي فواتير شراء بحالة "مُرسَلة" أو "استلام جزئي" بانتظار تسجيل استلام.</div>
    @else
        <form action="{{ route('purchasing.receiving.upload') }}" method="POST" enctype="multipart/form-data">
            @csrf

            <div class="mb-3">
                <label class="form-label fw-bold small text-dark">فاتورة الشراء</label>
                <select name="purchase_order_id" class="form-select @error('purchase_order_id') is-invalid @enderror" required>
                    <option value="">اختر الفاتورة...</option>
                    @foreach ($orders as $order)
                        <option value="{{ $order->id }}" @selected(old('purchase_order_id') == $order->id)>
                            #{{ $order->id }} — {{ $order->supplier->name }} ({{ $order->status->label() }})
                        </option>
                    @endforeach
                </select>
                @error('purchase_order_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <x-file-upload-zone name="receipt_file" label="ملف الاستلام" :required="true" accept=".xlsx,.xls,.csv"
                hint="ترتيب الأعمدة: باركود ← اسم المادة ← الشركة ← الكمية المستلمة ← تاريخ الصلاحية (اختياري)" />

            <button type="submit" class="btn btn-brand w-100 py-2 fw-medium rounded-2 mt-2">
                <i class="fa-solid fa-upload me-1"></i> رفع ومطابقة الملف
            </button>
        </form>
    @endif
</div>
@endsection
