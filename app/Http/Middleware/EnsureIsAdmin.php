<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 🎯 لشاشات "إدارة المستخدمين" حصراً — أعلى صرامة من EnsureHasModulePermission
 * (التي تفحص وصولاً لقسم عمل/تقارير، لا صلاحية إدارية). عمداً كلاس مستقل
 * لا مجرد middleware('module:admin') إضافي — إدارة المستخدمين ليست "قسماً"
 * بنفس معنى المناقلات/المشتريات/التقارير، بل صلاحية إدارية من نوع مختلف
 * تماماً؛ خلطها بنفس آلية "أقسام العمل" كان سيُضعِف وضوح الفرق بينهما.
 */
class EnsureIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()?->isAdmin()) {
            abort(403, 'هذه الصفحة مخصَّصة لمدير النظام فقط.');
        }

        return $next($request);
    }
}
