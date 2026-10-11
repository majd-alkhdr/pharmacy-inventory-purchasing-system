@extends('layouts.admin')

@section('page_title', 'مستخدم جديد')
@section('page_subtitle', 'الإعدادات — الحسابات والصلاحيات')

@section('content')

    <div class="row justify-content-center">
        <div class="col-lg-6">
            <div class="border rounded-2 shadow-sm bg-white p-4">
                @if ($errors->any())
                    <div class="alert alert-danger small mb-3">{{ $errors->first() }}</div>
                @endif

                <form method="POST" action="{{ route('admin.users.store') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">الاسم</label>
                        <input type="text" name="name" class="form-control" value="{{ old('name') }}" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">البريد الإلكتروني</label>
                        <input type="email" name="email" class="form-control" value="{{ old('email') }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">كلمة المرور</label>
                        <input type="password" name="password" class="form-control" required minlength="8">
                        <div class="form-text small">8 خانات على الأقل. أخبر المستخدم بها بطريقة آمنة — لن تظهر مرة أخرى.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">الدور</label>
                        <select name="role_id" class="form-select" required>
                            <option value="">اختر دوراً...</option>
                            @foreach ($roles as $role)
                                <option value="{{ $role->id }}" @selected(old('role_id') == $role->id)>{{ $role->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-brand fw-medium"><i class="fa-solid fa-check me-1"></i> إنشاء المستخدم</button>
                        <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary">إلغاء</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

@endsection
