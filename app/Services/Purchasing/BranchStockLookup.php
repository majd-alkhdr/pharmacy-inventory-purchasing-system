<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Enums\BranchStockState;
use App\Models\Pharmacy;
use App\Models\PharmacyInventory;
use App\Models\PharmacyMinimumStock;
use App\Services\Inventory\Surplus\SurplusEntry;
use App\Services\Inventory\Surplus\SurplusSnapshot;

/**
 * 🎯 (ج.3) مسؤولية واحدة: «ما حال مادة واحدة بكل فرع؟» — لوحة "الفروع" بتفاصيل فاتورة الشراء.
 * قراءة فقط، ثلاثة استعلامات للمادة كلها (قياسي + رصيد + أسماء)، لا N+1.
 *
 * 🎓 لماذا لا تحسب الفائض بنفسها؟ SurplusEngine هو المصدر الوحيد للفائض (بعد التسامح وتقريب
 * العبوة والتزامات الدورة واستثناء الشركات). هنا نسأل اللقطة الممرَّرة فقط — فلا تتكرر رياضياته
 * ولا يمكن أن تختلف اللوحة عن تنفيذ السحب.
 *
 * الصفوف = اتحاد الصيدليات التي لها صف رصيد أو صف قياسي لهذه المادة.
 */
final class BranchStockLookup
{
    /** @return list<BranchStockRow> مرتّبة للعرض (راجع sortRows) */
    public function forProduct(int $productId, SurplusSnapshot $snapshot): array
    {
        $standards = PharmacyMinimumStock::where('product_id', $productId)->pluck('minimum_quantity', 'pharmacy_id');
        $stock = PharmacyInventory::where('product_id', $productId)->pluck('quantity', 'pharmacy_id');

        $pharmacyIds = array_values(array_unique([...$standards->keys()->all(), ...$stock->keys()->all()]));
        if ($pharmacyIds === []) {
            return [];
        }

        $names = Pharmacy::whereIn('id', $pharmacyIds)->pluck('name', 'id');

        $rows = [];
        foreach ($pharmacyIds as $pharmacyId) {
            $pharmacyId = (int) $pharmacyId;
            $current = $stock->has($pharmacyId) ? (int) $stock[$pharmacyId] : null;
            $standard = $standards->has($pharmacyId) ? (int) $standards[$pharmacyId] : null;
            $entry = $snapshot->entry($pharmacyId, $productId);

            $rows[] = new BranchStockRow(
                pharmacyId: $pharmacyId,
                name: (string) ($names[$pharmacyId] ?? "#{$pharmacyId}"),
                currentStock: $current,
                standard: $standard,
                difference: $current !== null && $standard !== null ? $current - $standard : null,
                state: self::classify($current, $standard, $entry),
                available: $entry?->available() ?? 0,
                committed: $entry?->committed ?? 0,
            );
        }

        return self::sortRows($rows);
    }

    /**
     * دالة نقية: حال الفرع من رصيده وقياسيه ومدخل الفائض (إن وُجد).
     * وجود مدخل باللقطة يعني فائضاً فعّالاً فيزيائياً — المتاح منه يفرّق بين "فائض" و"محجوز كله".
     */
    public static function classify(?int $currentStock, ?int $standard, ?SurplusEntry $entry): BranchStockState
    {
        return match (true) {
            $entry !== null && $entry->available() > 0 => BranchStockState::Surplus,
            $entry !== null => BranchStockState::SurplusReserved,
            $standard === null => BranchStockState::NoStandard,
            $currentStock === null => BranchStockState::Unknown,
            $currentStock >= $standard => BranchStockState::Ok,
            default => BranchStockState::Below,
        };
    }

    /**
     * دالة نقية: الفائض (الأكبر متاحاً أولاً) ← المحجوز ← ضمن القياسي (الأكبر رصيداً) ← دون القياسي
     * (الأكبر نقصاً) ← لا رصيد ← بلا قياسي، وثانوياً بالاسم.
     *
     * @param list<BranchStockRow> $rows
     * @return list<BranchStockRow>
     */
    public static function sortRows(array $rows): array
    {
        usort($rows, function (BranchStockRow $a, BranchStockRow $b): int {
            $byState = $a->state->sortOrder() <=> $b->state->sortOrder();
            if ($byState !== 0) {
                return $byState;
            }

            $byMagnitude = match ($a->state) {
                BranchStockState::Surplus => $b->available <=> $a->available,
                BranchStockState::Ok => ($b->currentStock ?? 0) <=> ($a->currentStock ?? 0),
                BranchStockState::Below => ($a->difference ?? 0) <=> ($b->difference ?? 0),
                default => 0,
            };

            return $byMagnitude !== 0 ? $byMagnitude : strcmp($a->name, $b->name);
        });

        return $rows;
    }
}
