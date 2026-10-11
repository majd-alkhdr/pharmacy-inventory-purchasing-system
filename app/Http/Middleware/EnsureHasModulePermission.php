<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 🎯 يُستخدَم كـ middleware('module:transfers_operations') بمجموعات
 * الراوتس — لا يعرف شيئاً عن أي دور تحديداً بالاسم، فقط "هل يملك هذا
 * المستخدم الصلاحية المطلوبة أم لا؟" (عبر User::hasModuleAccess()).
 * فصل هذا القرار عن أي منطق دور محدَّد يعني أن إضافة دور خامس مستقبلاً
 * لا يحتاج أي تعديل هنا إطلاقاً — فقط زرع دور جديد بالصلاحيات المناسبة.
 */
class EnsureHasModulePermission
{
    public function handle(Request $request, Closure $next, string $moduleKey): Response
    {
        $user = $request->user();

        if (!$user || !$user->hasModuleAccess($moduleKey)) {
            abort(403, 'ليست لديك صلاحية الوصول لهذا القسم.');
        }

        return $next($request);
    }
}
