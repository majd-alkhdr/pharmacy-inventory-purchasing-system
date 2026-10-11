<?php

namespace App\Exports;

use App\Models\ReverseTransferItem;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * 🎯 تصدير مستند مناقلة عكسية **واحدة** بكامل أصنافها — نفس بنية InvoiceExport
 * بالضبط (المستند المقابل لها بالاتجاه المعاكس: من الصيدلية للمستودع، لا العكس)،
 * لتوفير نفس تجربة "طباعة/تصدير مستند واحد" الموجودة أصلاً للفواتير العادية.
 * ReverseTransfersExport (بصيغة الجمع) يبقى للتقرير المُجمَّع عبر كل المناقلات —
 * هذا الكلاس مختلف تماماً: نطاقه مناقلة واحدة بعينها.
 */
class ReverseTransferExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    public function __construct(private readonly int $reverseTransferId)
    {
    }

    public function collection()
    {
        return ReverseTransferItem::where('reverse_transfer_id', $this->reverseTransferId)
            ->with(['product', 'reverseTransfer.pharmacy'])
            ->get();
    }

    public function headings(): array
    {
        return ['اسم الصيدلية', 'رقم المادة', 'الشركة المصنعة', 'اسم المادة', 'الكمية المُعادة', 'المخزون القياسي وقت الرصد', 'رصيد الصيدلية وقت الرصد'];
    }

    public function map($item): array
    {
        return [
            $item->reverseTransfer->pharmacy->name,
            $item->product->barcode,
            $item->product->company,
            $item->product->name,
            $item->quantity,
            $item->standard_quantity_at_time,
            $item->pharmacy_quantity_at_time,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->getStyle('A1:G1')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2D3748']],
            'font' => ['color' => ['argb' => 'FFFFFFFF'], 'bold' => true],
        ]);

        return [];
    }
}
