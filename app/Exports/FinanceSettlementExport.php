<?php

namespace App\Exports;

use App\Models\FinanceSettlementItem;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * 🎯 (ب.3) ملف "مواد تحتاج تسوية من المالية" — يُصدّره موظف العمليات يدوياً ويرسله للمالية
 * (لا حساب/دور مالية بالنظام بقرار صريح). يستقبل المجموعة جاهزة: الكونترولر يقرر أي بنود
 * (غير المصدَّرة فقط أو الكل) ويُعلِّمها مصدَّرة بنفس الطلب.
 */
class FinanceSettlementExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    /** @param Collection<int, FinanceSettlementItem> $items */
    public function __construct(private readonly Collection $items)
    {
    }

    public function collection()
    {
        return $this->items;
    }

    public function headings(): array
    {
        return ['رقم فاتورة المناقلة', 'تاريخ الفاتورة', 'الصيدلية', 'رمز المادة', 'اسم المادة', 'الشركة', 'الكمية', 'السبب', 'تاريخ الإحالة'];
    }

    public function map($item): array
    {
        return [
            $item->invoice_id,
            $item->invoice?->date?->format('Y-m-d'),
            $item->pharmacy->name,
            $item->product->barcode,
            $item->product->name,
            $item->product->company,
            $item->quantity,
            $item->reason->label,
            $item->created_at->format('Y-m-d H:i'),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->getStyle('A1:I1')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2D3748']],
            'font' => ['color' => ['argb' => 'FFFFFFFF'], 'bold' => true],
        ]);

        return [];
    }
}
