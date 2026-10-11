<?php

declare(strict_types=1);

namespace App\Services\Shared\Audit;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * 🎯 (أ.2) نقطة الكتابة الوحيدة لسجل النشاط الشامل — كل ميزة بالمرحلتين ب وج
 * (تعديل فاتورة مناقلة، سبب فرق مطابقة، تنفيذ مناقلة، كمية شراء معتمدة) تستدعي
 * record() بدل بناء سجلها الخاص. ج.2 تحديداً **تعيد استخدام** نفس الدالة التي
 * تستخدمها ب.2 — لا نسخة موازية.
 *
 * ⚠️ يجب استدعاؤها **داخل** نفس DB::transaction التي تكتب التغيير نفسه (قاعدة 3):
 * تراجع المعاملة يجب أن يُسقط سطر السجل معها، وإلا بقي سجلٌ لتعديل لم يحدث.
 * الخدمة لا تفتح معاملة بنفسها عمداً — المعاملة ملك المُستدعي (حدود العملية التجارية).
 */
final class AuditLogger
{
    /**
     * @param array<string, mixed>|null $old القيم قبل الإجراء (null لإنشاء جديد)
     * @param array<string, mixed>|null $new القيم بعده (null للحذف)
     * @param array<string, mixed> $context معلومات قراءة إضافية (اسم المادة، رقم المستند...)
     */
    public function record(AuditAction $action, Model $subject, ?array $old = null, ?array $new = null, array $context = []): AuditLog
    {
        return AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => $subject->getMorphClass(),
            'auditable_id' => $subject->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'context' => $context === [] ? null : $context,
        ]);
    }

    /**
     * اختصار لأكثر حالة شيوعاً: تغيير قيمة رقمية واحدة. لا يكتب شيئاً إن لم تتغيّر
     * القيمة فعلاً — حفظ نموذج بلا تعديل حقيقي لا يجب أن يملأ السجل بضجيج.
     *
     * @param array<string, mixed> $context
     */
    public function recordChange(AuditAction $action, Model $subject, string $field, int|float|null $before, int|float|null $after, array $context = []): ?AuditLog
    {
        if ($before == $after) {
            return null;
        }

        return $this->record($action, $subject, [$field => $before], [$field => $after], $context);
    }
}
