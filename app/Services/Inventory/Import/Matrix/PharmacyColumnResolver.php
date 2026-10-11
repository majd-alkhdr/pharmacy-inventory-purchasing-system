<?php

declare(strict_types=1);

namespace App\Services\Inventory\Import\Matrix;

use App\Services\Inventory\Import\Support\ArabicTextNormalizer;

/**
 * 🎯 يقرر أي صيدلية مسجَّلة يقصدها كل عمود بالملف — **منطق نقي**: يستقبل الأسماء
 * والمطابقات المحفوظة كبيانات، ولا يعرف الجداول. (تحميلها من قاعدة البيانات مسؤولية
 * المستورد؛ بهذا يُختبر القرار بدون قاعدة بيانات.)
 *
 * الأولوية (بالترتيب):
 *  1. مطابقة محفوظة يدوياً سابقاً (pharmacy_mappings) — قرار بشري سابق يتقدَّم على أي تخمين.
 *  2. اسم صيدلية مسجَّلة يطابق الرأس بعد التطبيع (فواصل/همزات/مسافات).
 *
 * ⚖️ لماذا نطابق بمفتاح مُطبَّع لا بالنص الحرفي؟ كشف الـ ERP كتب "ماهر سليمان / جرمانا"
 * وملفك "ماهر سليمان- جرمانا": نفس الصيدلية. النص الحرفي كان سيجبرك على شاشة
 * مطابقة بكل رفعة بسبب فاصلة. المطابقة المُطبَّعة تحل الحالة الشائعة تلقائياً،
 * وتترك للبشر فقط ما لا يمكن حسمه بأمان.
 */
final class PharmacyColumnResolver
{
    /**
     * @param array<int, string> $pharmacyNamesById  معرّف الصيدلية ← اسمها المسجَّل
     * @param array<string, int> $savedMappings       اسم أصلي محفوظ (pharmacy_mappings.original_name) ← معرّف الصيدلية
     */
    public function resolve(MatrixColumnMap $columns, array $pharmacyNamesById, array $savedMappings): PharmacyResolution
    {
        $idsByNameKey = [];
        foreach ($pharmacyNamesById as $id => $name) {
            $idsByNameKey[ArabicTextNormalizer::key($name)][] = (int) $id;
        }

        $idByMappingKey = [];
        foreach ($savedMappings as $originalName => $pharmacyId) {
            $idByMappingKey[ArabicTextNormalizer::key((string) $originalName)] = (int) $pharmacyId;
        }

        $resolved = [];
        $unresolved = [];
        $ambiguous = [];

        foreach ($columns->pharmacyColumns() as $index => $header) {
            $key = ArabicTextNormalizer::key($header);

            if (isset($idByMappingKey[$key]) && isset($pharmacyNamesById[$idByMappingKey[$key]])) {
                $resolved[$index] = $idByMappingKey[$key];
                continue;
            }

            $candidates = $idsByNameKey[$key] ?? [];

            match (count($candidates)) {
                0 => $unresolved[$index] = $header,
                1 => $resolved[$index] = $candidates[0],
                default => $ambiguous[$index] = $candidates,
            };
        }

        // عمودان (بمفتاحين مختلفين) انتهيا لنفس الصيدلية — مثلاً رأس + اسم بديل محفوظ.
        $columnsByPharmacy = [];
        foreach ($resolved as $index => $pharmacyId) {
            $columnsByPharmacy[$pharmacyId][] = $index;
        }
        $conflicts = array_filter($columnsByPharmacy, fn (array $indexes): bool => count($indexes) > 1);

        return new PharmacyResolution($resolved, $unresolved, $ambiguous, $conflicts, $columns->pharmacyColumns());
    }
}
