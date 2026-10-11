@extends('layouts.admin')

@section('page_title', 'إدارة المستخدمين')
@section('page_subtitle', 'الإعدادات — الحسابات والصلاحيات')

@section('topbar_actions')
    <a href="{{ route('admin.users.create') }}" class="btn btn-brand btn-sm rounded-2 fw-medium">
        <i class="fa-solid fa-user-plus me-1"></i> مستخدم جديد
    </a>
@endsection

@section('content')

    @if (session('status'))
        <div class="alert alert-light border small mb-3">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger small mb-3">{{ $errors->first() }}</div>
    @endif

    <div class="border rounded-2 overflow-hidden shadow-sm bg-white">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead>
                    <tr>
                        <th>الاسم</th>
                        <th>البريد الإلكتروني</th>
                        <th>الدور</th>
                        <th class="text-center">الحالة</th>
                        <th class="text-center no-print">إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr>
                            <td class="fw-medium text-dark">
                                {{ $user->name }}
                                @if ($user->id === auth()->id())
                                    <span class="badge brand-badge px-2 py-1 ms-1">أنت</span>
                                @endif
                            </td>
                            <td class="text-muted small">{{ $user->email }}</td>
                            <td><span class="badge brand-badge px-2 py-1">{{ $user->role->name ?? 'بلا دور' }}</span></td>
                            <td class="text-center">
                                @if ($user->is_active)
                                    <span class="badge px-2 py-1" style="background:#f0fdf4;color:#15803d;border:1px solid #dcfce7;">نشِط</span>
                                @else
                                    <span class="badge px-2 py-1" style="background:#fef2f2;color:#dc2626;border:1px solid #fee2e2;">موقوف</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <a href="{{ route('admin.users.edit', $user->id) }}" class="btn btn-sm btn-outline-secondary rounded-pill px-2 py-0 me-1">
                                    <i class="fa-solid fa-pen fa-xs"></i>
                                </a>
                                @if ($user->id !== auth()->id())
                                    <form method="POST" action="{{ route('admin.users.destroy', $user->id) }}" class="d-inline"
                                        onsubmit="return confirm('حذف هذا المستخدم نهائياً؟');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-2 py-0">
                                            <i class="fa-solid fa-trash fa-xs"></i>
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="5" icon="fa-users" message="لا يوجد مستخدمون بعد." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

@endsection
