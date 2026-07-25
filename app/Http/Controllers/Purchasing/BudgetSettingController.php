<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\BudgetSettingRequest;
use App\Models\BudgetSetting;
use Illuminate\Support\Carbon;

/**
 * 🎯 هذه هي بالضبط الشاشة التي أشرتَ لغيابها: "أين إعدادات النظام؟".
 * PurchaseOrderGenerationService::resolveBudgetCap() يستدعي
 * BudgetSetting::currentMonth() — وهذا الموديل كان موجوداً منذ الدفعة
 * الثانية (تصميم قاعدة البيانات)، لكن لم تكن هناك أي شاشة تسمح للمشرف
 * فعلياً بضبط قيمته. بدون هذه الشاشة، BudgetSetting::currentMonth()
 * كانت ستُرجع دائماً null (لا يوجد سطر أصلاً بالجدول لأي شهر)، فيعمل
 * النظام دائماً "بلا سقف ميزانية" بصمت — سلوك صحيح تقنياً، لكنه ليس ما
 * تريده عملياً كصاحب قرار.
 */
class BudgetSettingController extends Controller
{
    public function index()
    {
        $currentMonth = Carbon::now()->startOfMonth();

        $current = BudgetSetting::firstOrNew(['effective_month' => $currentMonth->toDateString()]);

        $history = BudgetSetting::where('effective_month', '<', $currentMonth->toDateString())
            ->orderByDesc('effective_month')
            ->limit(12)
            ->get();

        return view('purchasing.budget-settings', [
            'current' => $current,
            'history' => $history,
        ]);
    }

    public function update(BudgetSettingRequest $request)
    {
        $data = $request->validated();
        $month = Carbon::parse($data['effective_month'])->startOfMonth()->toDateString();

        BudgetSetting::updateOrCreate(
            ['effective_month' => $month],
            [
                'monthly_cap_amount' => $data['monthly_cap_amount'] ?? null,
                'is_enabled'          => $data['is_enabled'] ?? false,
                'notes'               => $data['notes'] ?? null,
            ]
        );

        return back()->with('success', '✅ تم حفظ إعدادات الميزانية لهذا الشهر بنجاح.');
    }
}
