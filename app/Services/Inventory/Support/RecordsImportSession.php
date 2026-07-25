<?php

namespace App\Services\Inventory\Support;

use App\Models\ImportSession;

/**
 * 🎯 Trait بسيط، لكنه يحل مشكلة تكرار حقيقية: markSession() كانت private
 * method داخل InventoryImportService، وبعد تفكيك الخدمة لعدة كلاسات، كل
 * خدمة تحتاج تُحدّث حالة الجلسة (ImportSession). بدل نسخ نفس الميثود 6 مرات،
 * كل خدمة تستخدم Trait موحّد => لو تغيّر شكل جدول import_sessions، التعديل
 * بمكان واحد فقط.
 */
trait RecordsImportSession
{
    protected function markSession(string $sessionId, string $status, ?string $message = null): void
    {
        ImportSession::where('session_id', $sessionId)->update([
            'status'  => $status,
            'message' => $message,
        ]);
    }
}
