<?php

namespace App\Exports;

use App\Models\PharmacyTransferItem;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * 🎯 تصدير مستند مناقلة مباشرة **واحدة** بين صيدليتين بكامل أصنافها — نفس
 * بنية ReverseTransferExport وInvoiceExport بالضبط، ليتمكن موظف العمليات من
 * إرسالها لفريق التحضير الفعلي وإكمال النقل المادي (الطلب رقم 3 الصريح).
 */
class PharmacyTransferExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    public function __construct(private readonly int $pharmacyTransferId)
    {
    }

    public function collection()
    {
        return PharmacyTransferItem::where('pharmacy_transfer_id', $this->pharmacyTransferId)
            ->with(['product', 'pharmacyTransfer.fromPharmacy', 'pharmacyTransfer.toPharmacy'])
            ->get();
    }

    public function headings(): array
    {
        return ['من صيدلية', 'إلى صيدلية', 'رقم المادة', 'الشركة المصنعة', 'اسم المادة', 'الكمية المنقولة', 'فائض المُرسِلة وقت الاقتراح', 'فجوة المستقبِلة وقت الاقتراح'];
    }

    public function map($item): array
    {
        return [
            $item->pharmacyTransfer->fromPharmacy->name,
            $item->pharmacyTransfer->toPharmacy->name,
            $item->product->barcode,
            $item->product->company,
            $item->product->name,
            $item->quantity,
            $item->from_pharmacy_excess_at_time,
            $item->to_pharmacy_gap_at_time,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->getStyle('A1:H1')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2D3748']],
            'font' => ['color' => ['argb' => 'FFFFFFFF'], 'bold' => true],
        ]);

        return [];
    }
}
