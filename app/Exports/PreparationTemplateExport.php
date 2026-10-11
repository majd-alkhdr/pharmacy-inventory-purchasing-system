<?php

namespace App\Exports;

use App\Models\InvoiceItem;
use App\Services\Inventory\Import\Preparation\PreparationColumns;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * 🎯 (ب.3) قالب ملف التحضير لفاتورة بعينها: رمز المادة + اسمها مملوءان، وعمود "الكمية المحضّرة"
 * فارغ يملؤه المحضّر. رؤوسه من PreparationColumns نفسه — فالقالب والمحلِّل لا يختلفان أبداً.
 *
 * العمود A نصّي صراحة: الباركودات أرقام طويلة، وإكسل يحوّلها لصيغة علمية (6.28E+12) فيفسد الرمز.
 */
class PreparationTemplateExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithColumnFormatting
{
    public function __construct(private readonly int $invoiceId)
    {
    }

    public function collection()
    {
        return InvoiceItem::where('invoice_id', $this->invoiceId)->with('product:id,barcode,name')->get();
    }

    public function headings(): array
    {
        return PreparationColumns::headings();
    }

    public function map($item): array
    {
        return [(string) $item->product->barcode, $item->product->name, null];
    }

    public function columnFormats(): array
    {
        return ['A' => NumberFormat::FORMAT_TEXT];
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->getStyle('A1:C1')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2D3748']],
            'font' => ['color' => ['argb' => 'FFFFFFFF'], 'bold' => true],
        ]);

        return [];
    }
}
