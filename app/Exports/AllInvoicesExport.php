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

class AllInvoicesExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    protected array $filters;
    private int $rowIndex = 2;
    private array $shortageRows = [];

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function collection()
    {
        $query = InvoiceItem::with(['product', 'invoice.pharmacy'])
            ->availability($this->filters['availability'] ?? null);

        if (!empty($this->filters['status'])) {
            $query->whereHas('invoice', fn($q) => $q->where('status', $this->filters['status']));
        }
        if (!empty($this->filters['pharmacy_id'])) {
            $query->whereHas('invoice', fn($q) => $q->where('pharmacy_id', $this->filters['pharmacy_id']));
        }
        if (!empty($this->filters['date'])) {
            $query->whereHas('invoice', fn($q) => $q->whereDate('date', $this->filters['date']));
        }
        // 🆕
        if (array_key_exists('redistributed_only', $this->filters) && $this->filters['redistributed_only']) {
            $query->where('is_redistributed', true);
        }

        return $query->get();
    }

    public function headings(): array
    {
        return ['اسم الصيدلية', 'رقم المادة', 'الشركة المصنعة', 'اسم المادة', 'الكمية المشحونة', 'حالة المناقلة', 'رقم الفاتورة', 'نوع الفاتورة', 'مصدر التغطية']; // 🆕 عمود تاسع
    }

    public function map($item): array
    {
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
            '#' . $item->invoice_id,
            $item->invoice->status->label(),
            $item->is_redistributed // 🆕
                ? '🔄 إعادة توزيع من: ' . implode('، ', $item->redistribution_sources ?? [])
                : 'مستودع مركزي',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        // 🩹 9 أعمدة الآن (A→I)، لا 8 — نفس فخّ النطاق الموثَّق أعلى هذا الملف أصلاً
        $sheet->getStyle('A1:I1')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2D3748']],
            'font' => ['color' => ['argb' => 'FFFFFFFF'], 'bold' => true],
        ]);

        foreach ($this->shortageRows as $rowNum) {
            $sheet->getStyle("A{$rowNum}:I{$rowNum}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFDE8E8']],
                'font' => ['color' => ['argb' => 'FFC53030']],
            ]);
        }

        return [];
    }
}
