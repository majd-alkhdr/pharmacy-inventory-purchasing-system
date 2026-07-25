<?php

namespace App\Exports;

use App\Models\InvoiceItem;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class InvoiceExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    protected $invoiceId;
    protected ?string $availability;
    private int $rowIndex = 2;
    private array $shortageRows = [];

    public function __construct($invoiceId, ?string $availability = null)
    {
        $this->invoiceId    = $invoiceId;
        $this->availability = $availability;
    }

    public function collection()
    {
        return InvoiceItem::where('invoice_id', $this->invoiceId)
            ->with(['product', 'invoice.pharmacy'])
            ->availability($this->availability) // 🎯 نُقل لـ Model Scope
            ->get();
    }

    public function headings(): array
    {
        return ['اسم الصيدلية', 'رقم المادة', 'الشركة المصنعة', 'اسم المادة', 'الكمية المشحونة', 'حالة المناقلة'];
    }

    public function map($item): array
    {
        // 🎯 هذا الميثود قبل الريفاكتور كان يحتوي match(true){...} كاملاً
        // مكرراً حرفياً عن AllInvoicesExport. الآن كلاهما يستدعي نفس
        // الـ Enum::fromQuantities() — منطق واحد، مصدر حقيقة واحد.
        if ($item->shortage_qty > 0) {
            $this->shortageRows[] = $this->rowIndex;
        }
        $this->rowIndex++;

        return [
            $item->invoice->pharmacy->name,
            $item->product->barcode,
            $item->product->company,
            $item->product->name,
            $item->displayQty(),
            $item->availability()->label(),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->getStyle('A1:F1')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2D3748']],
            'font' => ['color' => ['argb' => 'FFFFFFFF'], 'bold' => true],
        ]);

        foreach ($this->shortageRows as $rowNum) {
            $sheet->getStyle("A{$rowNum}:F{$rowNum}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFDE8E8']],
                'font' => ['color' => ['argb' => 'FFC53030']],
            ]);
        }

        return [];
    }
}
