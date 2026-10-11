@extends('layouts.admin')

@section('page_title', 'تعديل مستخدم')
@section('page_subtitle', 'الإعدادات — الحسابات والصلاحيات')

@section('content')

    <div class="row justify-content-center g-3">
        <div class="col-lg-6">
            <div class="border rounded-2 shadow-sm bg-white p-4">
                <h6 class="fw-bold mb-3"><i class="fa-solid fa-user-pen text-primary me-1"></i> البيانات والدور</h6>

                @if ($errors->any() && !$errors->has('password'))
                    <div class="alert alert-danger small mb-3">{{ $errors->first() }}</div>
                @endif

                <form method="POST" action="{{ route('admin.users.update', $user->id) }}">
                    @csrf
                    @method('PUT')
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">الاسم</label>
                        <input type="text" name="name" class="form-control" value="{{ old('name', $user->name) }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">البريد الإلكتروني</label>
                        <input type="email" name="email" class="form-control" value="{{ old('email', $user->email) }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">الدور</label>
                        <select name="role_id" class="form-select" required>
                            @foreach ($roles as $role)
                                <option value="{{ $role->id }}" @selected(old('role_id', $user->role_id) == $role->id)>{{ $role->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($user->id !== auth()->id())
                        <div class="form-check mb-3">
                            <input type="checkbox" name="is_active" value="1" class="form-check-input" id="isActive" @checked($user->is_active)>
                            <label class="form-check-label small" for="isActive">الحساب نشِط (إلغاء التحديد يوقفه بلا حذفه)</label>
                        </div>
                    @endif
                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-brand fw-medium"><i class="fa-solid fa-check me-1"></i> حفظ التعديلات</button>
                        <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary">رجوع</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="border rounded-2 shadow-sm bg-white p-4">
                <h6 class="fw-bold mb-3"><i class="fa-solid fa-key text-warning me-1"></i> إعادة تعيين كلمة المرور</h6>
                <p class="text-muted small">لا توجد استعادة عبر البريد الإلكتروني بهذا النظام — الطريقة الوحيدة لتغيير كلمة مرور مستخدم هي من هنا.</p>

                @if ($errors->has('password'))
                    <div class="alert alert-danger small mb-3">{{ $errors->first('password') }}</div>
                @endif

                <form method="POST" action="{{ route('admin.users.reset-password', $user->id) }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">كلمة المرور الجديدة</label>
                        <input type="password" name="password" class="form-control" required minlength="8">
                    </div>
                    <button type="submit" class="btn btn-outline-dark fw-medium">
                        <i class="fa-solid fa-rotate me-1"></i> تحديث كلمة المرور
                    </button>
                </form>
            </div>
        </div>
    </div>

@endsection
