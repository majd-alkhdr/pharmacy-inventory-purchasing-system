<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 🎯 بلا أي طريق registration() هنا أو براوتس التطبيق — بقرار صريح: لا
 * تسجيل ذاتي إطلاقاً، الحسابات تُنشأ فقط عبر شاشة "إدارة المستخدمين"
 * (مدير النظام فقط). كذلك بلا أي طريق لاستعادة كلمة مرور عبر بريد
 * إلكتروني — الاستعادة تتم فقط عبر المدير من نفس شاشة إدارة المستخدمين.
 */
class AuthController extends Controller
{
    public function showLoginForm()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ], [
            'email.required'    => 'يرجى إدخال البريد الإلكتروني.',
            'email.email'        => 'صيغة البريد الإلكتروني غير صحيحة.',
            'password.required' => 'يرجى إدخال كلمة المرور.',
        ]);

        // 🎯 فحص is_active قبل محاولة المصادقة أصلاً — رسالة واضحة
        // ("الحساب موقوف") بدل رسالة "بيانات دخول خاطئة" المضلِّلة لحساب
        // بياناته صحيحة فعلياً لكنه موقوف فقط.
        $user = User::where('email', $credentials['email'])->first();
        if ($user && !$user->is_active) {
            return back()->withErrors(['email' => 'تم إيقاف هذا الحساب. تواصل مع مدير النظام.'])->onlyInput('email');
        }

        if (!Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'بيانات الدخول غير صحيحة.'])->onlyInput('email');
        }

        $request->session()->regenerate();

        // 🆕 لا intended() بعد الآن — أي وجهة محفوظة مسبقاً بالجلسة (من محاولة
        // وصول سابقة وهو غير مسجَّل) قد لا تناسب دوره إطلاقاً (هذا بالضبط ما
        // كان يحدث). الوجهة محسوبة طازجة من دوره الفعلي دائماً، بلا استثناء.
        return redirect(self::defaultRedirectFor(Auth::user()));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    // 🆕 public static (كانت private) — لتُستدعى أيضاً من صفحة الحظر 403
    // الجديدة (راجع الجزء الثاني)، بلا تكرار نفس منطق الفرز بمكانين.
    public static function defaultRedirectFor(User $user): string
    {
        if ($user->hasModuleAccess('transfers_operations')) {
            return route('inventory.upload.form');
        }
        if ($user->hasModuleAccess('purchasing_operations')) {
            return route('purchasing.orders.create');
        }
        if ($user->hasModuleAccess('transfers_reports') || $user->hasModuleAccess('purchasing_reports')) {
            return route('inventory.reports.inventory-value');
        }

        Auth::logout();

        return route('login');
    }
}
