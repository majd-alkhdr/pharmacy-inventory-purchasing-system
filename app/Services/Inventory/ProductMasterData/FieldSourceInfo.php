<?php

declare(strict_types=1);

namespace App\Services\Inventory\ProductMasterData;

use App\Models\AuditLog;
use App\Models\ImportLog;
use Illuminate\Support\Carbon;

/**
 * 🎯 "آخر تحديث" لحقل واحد، مقروءاً من سجلات النظام نفسها (سجل الرفع أو سجل النشاط) لا من نسخة
 * منفصلة — فيبقى مطابقاً لما تعرضه شاشة سجل الرفع لنفس الملف.
 */
final class FieldSourceInfo
{
    public const UPLOAD = 'upload';
    public const MANUAL = 'manual';
    /** كتابة من ملف آخر لا يسجّل مصدره لكل مادة (مثلاً سعر من ملف الجرد المركزي) — الوقت معروف فقط. */
    public const OTHER = 'other';

    private function __construct(
        public readonly string $kind,
        public readonly Carbon $at,
        public readonly ?ImportLog $importLog = null,
        public readonly ?AuditLog $auditLog = null,
    ) {
    }

    public static function upload(ImportLog $log, ?Carbon $at = null): self
    {
        return new self(self::UPLOAD, $at ?? $log->displayTime(), importLog: $log);
    }

    public static function manual(AuditLog $audit, Carbon $at): self
    {
        return new self(self::MANUAL, $at, auditLog: $audit);
    }

    public static function other(Carbon $at): self
    {
        return new self(self::OTHER, $at);
    }

    public function isManual(): bool
    {
        return $this->kind === self::MANUAL;
    }

    public function actorName(): ?string
    {
        return $this->importLog?->user?->name ?? $this->auditLog?->user?->name;
    }
}
