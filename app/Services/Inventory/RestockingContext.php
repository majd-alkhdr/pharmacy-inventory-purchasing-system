<?php
// app/Services/Inventory/RestockingContext.php

namespace App\Services\Inventory;

use App\Enums\RestockingMode;

/**
 * 🎯 حزمة الإدخال الموحَّدة التي يستقبلها أي RestockingStrategy::execute().
 *
 * لماذا DTO واحد بدل تمرير معاملات منفصلة (pharmacyIds, excludedCompanies,
 * sessionId...) لكل استراتيجية؟ لأن الاستراتيجيات الثلاث تحتاج تركيبات
 * مختلفة من نفس الحقول (Sales تحتاج sessionId فقط، StandardInventory
 * تحتاج pharmacyIds+excludedCompanies فقط، Hybrid تحتاج الاثنين معاً) —
 * توحيد الشكل يعني أن الـ Resolver/Controller يبني سياقاً واحداً بلا
 * حاجة لمعرفة أي حقل "تستخدمه" كل استراتيجية داخلياً؛ هذا تفصيل خاص بها.
 */
final class RestockingContext
{
    /**
     * @param array<int> $pharmacyIds الصيدليات التي تحدَّث جردها فعلياً بهذه الدفعة (يُستخدَم من StandardInventory وHybrid)
     * @param array<string> $excludedCompanies شركات مستثناة من الترميم لهذه الدفعة (كلا الاستراتيجيتين)
     * @param string|null $sessionId جلسة الاستيراد المؤقتة التي تحوي صفوف temporary_sales (يُستخدَم من Sales وHybrid فقط)
     * @param array<string, int> $reconcileAgainst 🆕 ["pharmacyId_productId" => الكمية المشحونة فعلياً من استراتيجية أخرى بنفس هذه الدفعة]
     *        يُستخدَم حصراً من StandardInventoryRestockingStrategy عند التشغيل ضمن Hybrid — راجع
     *        تعليق HybridRestockingStrategy الكامل. فارغة افتراضياً (لا تصالح) لأي استدعاء منفرد عادي.
     */
    public function __construct(
        public readonly RestockingMode $mode,
        public readonly array $pharmacyIds = [],
        public readonly array $excludedCompanies = [],
        public readonly ?string $sessionId = null,
        public readonly array $reconcileAgainst = [],
    ) {}
}
