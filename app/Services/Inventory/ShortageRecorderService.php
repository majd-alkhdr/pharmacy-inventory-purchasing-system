<?php

namespace App\Services\Inventory;

use App\Enums\ShortageStatus;
use App\Models\Shortage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ShortageRecorderService
{
    /**
     * @param Collection<int, array{pharmacy_id:int, product_id:int, quantity:int, occurred_at?:mixed, note?:?string}> $shortages
     */
    public function recordMany(Collection $shortages): void
    {
        if ($shortages->isEmpty()) {
            return;
        }

        // ══ الخطوة 1: تجميع أي تكرار داخل نفس الدفعة نفسها (سواء كانت
        // دفعة مبيعات يومية، ملف استيراد تاريخي، أو دفعة "تنظيف" قادمة
        // من أمر shortages:dedupe) — بنفس منطق الجمع + أحدث تاريخ + دمج
        // الملاحظات إن وُجدت. ══
        $aggregated = $shortages
            ->groupBy(fn (array $row) => $row['pharmacy_id'] . '_' . $row['product_id'])
            ->map(function (Collection $group) {
                $first = $group->first();
                $latestDate = $group
                    ->map(fn (array $r) => Carbon::parse($r['occurred_at'] ?? now()))
                    ->max();

                // 🎯 دعم اختياري: لو مُرِّرت ملاحظات صريحة (كما يفعل أمر
                // dedupe عند دمج صفوف قديمة فعلياً)، نجمعها. غير ذلك (الحالة
                // الشائعة: رفع مبيعات يومي)، نتركها لتوليد الملاحظة التلقائية.
                $combinedNote = $group->pluck('note')->filter()->unique()->implode(' | ');

                return [
                    'pharmacy_id' => $first['pharmacy_id'],
                    'product_id'  => $first['product_id'],
                    'quantity'    => $group->sum('quantity'),
                    'occurred_at' => $latestDate,
                    'note'        => $combinedNote ?: null,
                ];
            })
            ->values();

        DB::transaction(function () use ($aggregated) {
            $pharmacyIds = $aggregated->pluck('pharmacy_id')->unique()->values();
            $productIds  = $aggregated->pluck('product_id')->unique()->values();

            $existingPendingShortages = Shortage::pending()
                ->whereIn('pharmacy_id', $pharmacyIds)
                ->whereIn('product_id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (Shortage $s) => $s->pharmacy_id . '_' . $s->product_id);

            $rowsToInsert = [];

            foreach ($aggregated as $row) {
                $key   = $row['pharmacy_id'] . '_' . $row['product_id'];
                $match = $existingPendingShortages->get($key);

                if ($match) {
                    $mergeNote = $row['note']
                        ?? ('تكرر النقص بتاريخ ' . $row['occurred_at']->toDateString() . " (+{$row['quantity']} وحدة)");

                    $match->forceFill([
                        'quantity'   => $match->quantity + $row['quantity'],
                        'created_at' => $row['occurred_at'],
                        'notes'      => $match->appendResolutionNote($mergeNote),
                    ])->save();

                    continue;
                }

                $rowsToInsert[] = [
                    'pharmacy_id' => $row['pharmacy_id'],
                    'product_id'  => $row['product_id'],
                    'quantity'    => $row['quantity'],
                    'status'      => ShortageStatus::Pending->value,
                    'notes'       => $row['note'],
                    'created_at'  => $row['occurred_at'],
                    'updated_at'  => now(),
                ];
            }

            foreach (array_chunk($rowsToInsert, 500) as $chunk) {
                Shortage::insert($chunk);
            }
        });
    }
}