<?php

namespace App\Services\Inventory\Support;

use Illuminate\Support\Facades\DB;

/**
 * 🎯 كانت هذه دالة private اسمها bulkUpdateByMap داخل God Service.
 * تبني استعلام UPDATE ... CASE WHEN ضخم لتحديث آلاف الصفوف دفعة واحدة
 * بدل حلقة foreach فيها استعلام UPDATE منفصل لكل صف (بطيء جداً على آلاف
 * السجلات). أصبحت الآن Trait عام قابل لإعادة الاستخدام من أي خدمة.
 */
trait BulkCaseUpdater
{
    protected function bulkUpdateByMap(string $table, string $whereColumn, array $map, string $setColumn, string $sessionId): void
    {
        if (empty($map)) {
            return;
        }

        foreach (array_chunk($map, 500, true) as $chunk) {
            $cases    = "CASE `{$whereColumn}` ";
            $bindings = [];

            foreach ($chunk as $key => $value) {
                $cases .= 'WHEN ? THEN ? ';
                $bindings[] = $key;
                $bindings[] = $value;
            }
            $cases .= 'END';

            $keys          = array_keys($chunk);
            $placeholders  = implode(',', array_fill(0, count($keys), '?'));

            $sql = "UPDATE `{$table}` SET `{$setColumn}` = {$cases} "
                . "WHERE `session_id` = ? AND `{$whereColumn}` IN ({$placeholders})";

            $bindings[] = $sessionId;
            foreach ($keys as $k) {
                $bindings[] = $k;
            }

            DB::statement($sql, $bindings);
        }
    }
}
