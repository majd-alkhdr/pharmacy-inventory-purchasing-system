<?php
// app/Services/Inventory/ReverseTransferRecorderService.php

namespace App\Services\Inventory;

use App\Enums\ReverseTransferOrigin;
use App\Enums\ReverseTransferStatus;
use App\Models\Pharmacy;
use App\Models\ReverseTransfer;
use App\Models\ReverseTransferItem;
use App\Services\Inventory\Surplus\InventoryCycleResolver;

/**
 * 🎯 المسؤولية الوحيدة: تحويل دفعة "فوائض" مُجمَّعة إلى سجلات فعلية —
 * مستند "مناقلة عكسية" **مقترح** واحد لكل صيدلية بها فائض.
 *
 * 🆕 (ب.5 + ب.6) ══ لم يعد التسجيل يحرّك أي مخزون ══
 * كانت recordMany() تزيد central_inventories بالكمية فوراً وتطرحها من pharmacy_inventories
 * — أي المناقلة "تُنفَّذ" لحظة رصد الفائض بلا قرار بشري. وتلك الزيادة الفورية للمركزي
 * كانت **بالضبط** القناة التي يتسرّب عبرها فائض الصيدليات إلى فاتورة ترميم النواقص (ب.5).
 * الآن: المستند يُنشأ بحالة Suggested ومُوسوماً بدورة الرفع الحالية، والأثر الفعلي يحدث
 * فقط عند "تنفيذ" صريح عبر TransferExecutionService — الذي يحجز الفائض بـ SurplusEngine
 * (فيُلغي تلقائياً أي مناقلة بين صيدليات تعتمد على نفس الفائض). حُذفت دالة
 * applyPharmacyInventoryDecrements: سببها الوحيد (منع المخطِّط من رؤية فائض غادر فعلاً)
 * أصبح يحله جدول الالتزامات بشكل أدق — بلا تعديل لقطة الجرد المرفوعة.
 *
 * ⚠️ Insert فردي (create()) لا Bulk هنا عمداً بخلاف بقية الملفات — الحجم
 * هنا صغير جداً (فوائض فعلية، لا آلاف السطور)، ونحتاج معرِّف كل صف فوراً.
 */
class ReverseTransferRecorderService
{
    public function __construct(
        // افتراضي بالمُنشئ: الاختبارات والاستدعاءات القديمة التي تبني الخدمة يدوياً تبقى تعمل.
        private readonly InventoryCycleResolver $cycleResolver = new InventoryCycleResolver(),
    ) {}

    /**
     * @param array<int, array<int, array{product_id:int, quantity:int, standard_quantity_at_time:int, pharmacy_quantity_at_time:int}>> $itemsByPharmacy
     * @return array<int, array<int, array{item_id:int, quantity:int, pharmacy_name:string}>> [product_id => [{item_id, quantity, pharmacy_name}, ...]]
     *         (يبقى الشكل كما هو لتوافق RestockingResult::reverseTransferSources — معلوماتي فقط الآن)
     */
    public function recordMany(array $itemsByPharmacy): array
    {
        if (empty($itemsByPharmacy)) {
            return [];
        }

        $today = now()->toDateString();
        $cycle = $this->cycleResolver->current();
        $sourcesByProduct = [];

        $pharmacyNames = Pharmacy::whereIn('id', array_keys($itemsByPharmacy))->pluck('name', 'id');

        foreach ($itemsByPharmacy as $pharmacyId => $items) {
            if (empty($items)) {
                continue;
            }

            $transfer = ReverseTransfer::create([
                'pharmacy_id'         => $pharmacyId,
                'date'                => $today,
                'status'              => ReverseTransferStatus::Suggested,
                'origin'              => ReverseTransferOrigin::Restocking,
                'cycle_import_log_id' => $cycle->id,
            ]);

            foreach ($items as $item) {
                $transferItem = ReverseTransferItem::create([
                    'reverse_transfer_id'        => $transfer->id,
                    'product_id'                  => $item['product_id'],
                    'quantity'                    => $item['quantity'],
                    'standard_quantity_at_time' => $item['standard_quantity_at_time'],
                    'pharmacy_quantity_at_time' => $item['pharmacy_quantity_at_time'],
                ]);

                $sourcesByProduct[$item['product_id']][] = [
                    'item_id'       => $transferItem->id,
                    'quantity'      => $item['quantity'],
                    'pharmacy_name' => $pharmacyNames[$pharmacyId] ?? "صيدلية #{$pharmacyId}",
                ];
            }
        }

        return $sourcesByProduct;
    }
}
