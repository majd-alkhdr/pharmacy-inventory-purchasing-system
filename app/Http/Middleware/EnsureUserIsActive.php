<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 🎯 يُشغَّل بكل طلب مُصادَق عليه (بلا استثناء) — يلتقط حالة "تم إيقاف
 * هذا الحساب بينما كان مسجَّلاً دخوله بالفعل" فوراً بالطلب التالي، لا
 * ينتظر انتهاء صلاحية الجلسة طبيعياً. فحص is_active وقت تسجيل الدخول
 * فقط (بـ AuthController) لا يكفي وحده — يمنع دخولاً جديداً، لكن لا يوقف
 * جلسة قائمة بالفعل.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && !$user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'تم إيقاف هذا الحساب. تواصل مع مدير النظام.',
            ]);
        }

        return $next($request);
    }
}
