@extends('layouts.admin')

@section('page_title', $supplier->exists ? 'تعديل مورد' : 'إضافة مورد جديد')
@section('page_subtitle', 'المشتريات')

@section('content')
<div class="surface-card p-4 shadow-sm" style="max-width: 640px; margin: 0 auto;">

    <form action="{{ $supplier->exists ? route('purchasing.suppliers.update', $supplier->id) : route('purchasing.suppliers.store') }}"
        method="POST">
        @csrf
        @if ($supplier->exists) @method('PUT') @endif

        <div class="mb-3">
            <label class="form-label fw-bold small text-dark">اسم المورد <span class="text-danger">*</span></label>
            <input type="text" name="name" value="{{ old('name', $supplier->name) }}"
                class="form-control @error('name') is-invalid @enderror">
            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label fw-bold small text-dark">جهة الاتصال</label>
                <input type="text" name="contact_person" value="{{ old('contact_person', $supplier->contact_person) }}" class="form-control">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold small text-dark">رقم الهاتف</label>
                <input type="text" name="phone" value="{{ old('phone', $supplier->phone) }}" class="form-control">
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label fw-bold small text-dark">البريد الإلكتروني</label>
                <input type="email" name="email" value="{{ old('email', $supplier->email) }}"
                    class="form-control @error('email') is-invalid @enderror">
                @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold small text-dark">فترة التوريد المتوقعة (بالأيام)</label>
                <input type="number" name="lead_time_days" min="0" value="{{ old('lead_time_days', $supplier->lead_time_days ?? 7) }}" class="form-control">
                <div class="form-text small">تُستخدم لاحقاً بمحرك التنبؤ لحساب "متى يجب أن نطلب"، وليس فقط "كم يجب أن نطلب".</div>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label fw-bold small text-dark">ملاحظات</label>
            <textarea name="notes" rows="3" class="form-control">{{ old('notes', $supplier->notes) }}</textarea>
        </div>

        <div class="form-check mb-4">
            <input type="checkbox" name="is_active" value="1" class="form-check-input" id="isActive"
                {{ old('is_active', $supplier->is_active ?? true) ? 'checked' : '' }}>
            <label class="form-check-label small fw-medium" for="isActive">مورد فعّال (يظهر بخيارات التوليد التلقائي)</label>
        </div>

        <button type="submit" class="btn btn-brand w-100 py-2 fw-medium rounded-2">
            <i class="fa-solid fa-check me-1"></i> {{ $supplier->exists ? 'حفظ التعديلات' : 'إضافة المورد' }}
        </button>
    </form>
</div>
@endsection
