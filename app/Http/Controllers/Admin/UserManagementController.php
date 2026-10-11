<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    public function index()
    {
        $users = User::with('role')->orderBy('name')->get();

        return view('admin.users.index', compact('users'));
    }

    public function create()
    {
        $roles = Role::orderBy('name')->get();

        return view('admin.users.create', compact('roles'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role_id'  => ['required', 'exists:roles,id'],
        ], [
            'name.required'     => 'يرجى إدخال اسم المستخدم.',
            'email.required'    => 'يرجى إدخال البريد الإلكتروني.',
            'email.unique'       => 'هذا البريد الإلكتروني مُستخدَم بالفعل.',
            'password.required' => 'يرجى إدخال كلمة مرور.',
            'password.min'       => 'كلمة المرور يجب ألا تقل عن 8 خانات.',
            'role_id.required'  => 'يرجى اختيار دور للمستخدم.',
        ]);

        User::create([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'password'  => $data['password'], // 🎯 الـ cast hashed بموديل User يُشفِّرها تلقائياً
            'role_id'   => $data['role_id'],
            'is_active' => true,
        ]);

        return redirect()->route('admin.users.index')->with('status', '✅ تم إنشاء المستخدم بنجاح.');
    }

    public function edit(User $user)
    {
        $roles = Role::orderBy('name')->get();

        return view('admin.users.edit', compact('user', 'roles'));
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name'    => ['required', 'string', 'max:255'],
            'email'   => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'role_id' => ['required', 'exists:roles,id'],
        ], [
            'name.required'  => 'يرجى إدخال اسم المستخدم.',
            'email.required' => 'يرجى إدخال البريد الإلكتروني.',
            'email.unique'    => 'هذا البريد الإلكتروني مُستخدَم بالفعل.',
            'role_id.required' => 'يرجى اختيار دور للمستخدم.',
        ]);

        // 🎯 منع تعطيل أو تغيير دور آخر حساب أدمن نشِط بالنظام — نفس منطق
        // منع الحذف أدناه، لكن هنا عبر "تغيير الدور" لا الحذف المباشر.
        $isLastActiveAdmin = $user->isAdmin()
            && User::whereHas('role', fn($q) => $q->where('key', Role::ADMIN))->where('is_active', true)->count() <= 1;

        if ($isLastActiveAdmin && (int) $data['role_id'] !== $user->role_id) {
            return back()->withErrors(['role_id' => 'لا يمكن تغيير دور آخر مدير نشِط بالنظام — أنشئ مديراً آخر أولاً.'])->withInput();
        }

        $user->update([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'role_id'   => $data['role_id'],
            'is_active' => $isLastActiveAdmin ? true : $request->boolean('is_active'),
        ]);

        return redirect()->route('admin.users.index')->with('status', '✅ تم تحديث بيانات المستخدم.');
    }

    /** 🎯 إجراء منفصل عمداً عن update() — إعادة تعيين كلمة المرور فعل مختلف جوهرياً عن تعديل البيانات، بتحقّق مختلف */
    public function resetPassword(Request $request, User $user)
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ], [
            'password.required' => 'يرجى إدخال كلمة المرور الجديدة.',
            'password.min'        => 'كلمة المرور يجب ألا تقل عن 8 خانات.',
        ]);

        $user->update(['password' => $data['password']]);

        return redirect()->route('admin.users.index')->with('status', "🔑 تم تحديث كلمة مرور {$user->name} بنجاح.");
    }

    public function destroy(User $user)
    {
        // 🎯 منع حذف آخر حساب أدمن نشِط — بلا هذا الفحص، خطأ بسيط يمكن أن
        // يُقفِل النظام بالكامل عن أي إدارة مستقبلية (لا أحد يملك صلاحية
        // إدارة المستخدمين بعدها لإصلاح الوضع).
        if ($user->isAdmin() && User::whereHas('role', fn($q) => $q->where('key', Role::ADMIN))->where('is_active', true)->count() <= 1) {
            return back()->withErrors(['delete' => 'لا يمكن حذف آخر حساب مدير نشِط بالنظام.']);
        }

        // 🎯 منع حذف المستخدم لحسابه الخاص أثناء تسجيل دخوله به
        if ($user->id === auth()->id()) {
            return back()->withErrors(['delete' => 'لا يمكنك حذف حسابك الخاص أثناء تسجيل دخولك به.']);
        }

        $user->delete();

        return redirect()->route('admin.users.index')->with('status', '🗑️ تم حذف المستخدم.');
    }
}
