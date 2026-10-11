<?php

namespace App\Services\Inventory\Support;

use App\Models\ImportSession;
use App\Services\Shared\ImportHistory\ImportHistoryService;

/**
 * 🎯 Trait بسيط، لكنه يحل مشكلة تكرار حقيقية: markSession() كانت private
 * method داخل InventoryImportService، وبعد تفكيك الخدمة لعدة كلاسات، كل
 * خدمة تحتاج تُحدّث حالة الجلسة (ImportSession). بدل نسخ نفس الميثود 6 مرات،
 * كل خدمة تستخدم Trait موحّد => لو تغيّر شكل جدول import_sessions، التعديل
 * بمكان واحد فقط.
 *
 * 🆕 (أ.1) نفس النقطة الواحدة تُزامن الآن سجل الرفع الموحَّد أيضاً — كل خدمة خلفية
 * تستخدم هذا الـ Trait تكتب "نجح/فشل/بانتظار إجراء" لملفات جلستها بلا سطر إضافي.
 * ⚖️ app() هنا (لا حقن بالمُنشئ) عمداً: الـ Trait مستخدَم بأربع خدمات بمُنشئات مختلفة؛
 * حقن تبعية فيه كان سيفرض تعديل مُنشئ كل واحدة منها لأجل سطر تزامن واحد.
 */
trait RecordsImportSession
{
    protected function markSession(string $sessionId, string $status, ?string $message = null): void
    {
        ImportSession::where('session_id', $sessionId)->update([
            'status'  => $status,
            'message' => $message,
        ]);

        app(ImportHistoryService::class)->syncSessionStatus($sessionId, $status, $message);
    }
}
