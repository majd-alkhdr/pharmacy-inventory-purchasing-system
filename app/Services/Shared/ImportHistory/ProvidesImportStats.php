<?php

declare(strict_types=1);

namespace App\Services\Shared\ImportHistory;

/**
 * 🎯 عقد يطبّقه أي Outcome لمستورد يريد أن "يصف نفسه" لسجل الرفع.
 * المستوردات الجديدة مستقبلاً تطبّقه وتُمرَّر لـ ImportHistoryService مباشرة،
 * بلا أي كود ترجمة بالكونترولر.
 */
interface ProvidesImportStats
{
    public function importStats(): ImportStats;
}
