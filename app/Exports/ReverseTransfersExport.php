<?php

namespace App\Exports;

use App\Models\ReverseTransferItem;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * 🎯 نفس أسلوب PreparationExport بالضبط — Joins مباشرة بدل Eloquent
 * Relations، لأن التقرير هنا مسطَّح (لا تجميع)، فلا داعٍ لتحميل نماذج
 * كاملة (ReverseTransfer + Pharmacy + Product) لكل صف فقط لقراءة عمودين
 * منهما. استعلام واحد، بلا N+1 حتى مع with().
 */
class ReverseTransfersExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    public function __construct(private readonly array $filters = []) {}

    public function collection()
    {
        return ReverseTransferItem::query()
            ->join('reverse_transfers', 'reverse_transfers.id', '=', 'reverse_transfer_items.reverse_transfer_id')
            ->join('pharmacies', 'pharmacies.id', '=', 'reverse_transfers.pharmacy_id')
            ->join('products', 'products.id', '=', 'reverse_transfer_items.product_id')
            ->when($this->filters['pharmacy_id'] ?? null, fn($q, $v) => $q->where('reverse_transfers.pharmacy_id', $v))
            ->when($this->filters['date_from'] ?? null, fn($q, $v) => $q->whereDate('reverse_transfers.date', '>=', $v))
            ->when($this->filters['date_to'] ?? null, fn($q, $v) => $q->whereDate('reverse_transfers.date', '<=', $v))
            // 🆕 (ب.6) نفس نطاق جدول التقرير بالشاشة: المنفَّذ فقط، بلا البنود الملغاة تلقائياً.
            ->where('reverse_transfers.status', \App\Enums\ReverseTransferStatus::Executed->value)
            ->where(fn ($q) => $q->whereNull('reverse_transfer_items.execution_status')
                ->orWhere('reverse_transfer_items.execution_status', \App\Enums\TransferItemExecutionStatus::Executed->value))
            ->select([
                'reverse_transfers.date',
                'pharmacies.name as pharmacy_name',
                'products.barcode',
                'products.company',
                'products.name as product_name',
                'reverse_transfer_items.quantity',
                'reverse_transfer_items.standard_quantity_at_time',
                'reverse_transfer_items.pharmacy_quantity_at_time',
            ])
            ->orderByDesc('reverse_transfers.date')
            ->get();
    }

    public function headings(): array
    {
        return [
            'تاريخ المناقلة',
            'اسم الصيدلية',
            'رقم المادة',
            'الشركة المصنعة',
            'اسم المادة',
            'الكمية المُعادة',
            'المخزون القياسي وقت الرصد',
            'رصيد الصيدلية وقت الرصد',
        ];
    }

    public function map($row): array
    {
        return [
            Carbon::parse($row->date)->format('Y-m-d'),
            $row->pharmacy_name,
            $row->barcode,
            $row->company,
            $row->product_name,
            $row->quantity,
            $row->standard_quantity_at_time,
            $row->pharmacy_quantity_at_time,
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
