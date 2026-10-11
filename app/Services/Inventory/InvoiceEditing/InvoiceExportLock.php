<?php

declare(strict_types=1);

namespace App\Services\Inventory\InvoiceEditing;

use App\Models\Invoice;

/**
 * 🎯 (ب.2) "يُصدَّر فقط النسخة النهائية": أول تصدير لفاتورة يثبّت exported_at، فتُقفل عن
 * التعديل. التصديرات نفسها تقرأ البيانات الحيّة دائماً — فما يُصدَّر هو بالضرورة آخر نسخة
 * محفوظة بعد التعديل، والقفل يضمن ألا تتغيّر بعد أن صارت بيد المستودع.
 *
 * whereNull('exported_at') بكل تحديث: إعادة التصدير لا تُغيّر تاريخ القفل الأول.
 */
final class InvoiceExportLock
{
    public function lockOne(Invoice $invoice): void
    {
        Invoice::whereKey($invoice->id)->whereNull('exported_at')->update(['exported_at' => now()]);
    }

    /**
     * نفس فلاتر شاشة الفواتير/كشف التجهيز (التاريخ إلزامي بهما دائماً).
     *
     * @param array{date?: ?string, status?: ?string, pharmacy_id?: int|string|null} $filters
     */
    public function lockMatching(array $filters): int
    {
        return Invoice::query()
            ->whereNull('exported_at')
            ->when($filters['date'] ?? null, fn ($q, $d) => $q->whereDate('date', $d))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['pharmacy_id'] ?? null, fn ($q, $p) => $q->where('pharmacy_id', (int) $p))
            ->update(['exported_at' => now()]);
    }
}
