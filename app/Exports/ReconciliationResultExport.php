<?php

namespace App\Exports;

use App\Models\Invoice;
use App\Models\TransferReconciliationItem;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * 🎯 (ب.3) نتيجة آخر مطابقة لفاتورة مناقلة — يرسلها موظف العمليات للمستودع ليدقّق الأصناف
 * غير المطابقة. للقراءة فقط: لا يعلّم شيئاً "مصدَّراً" (بخلاف FinanceSettlementExport).
 * يستقبل البنود جاهزة ومرتّبة من TransferReconciliationService::itemsForExport.
 *
 * العمود C نصّي صراحة: الباركودات أرقام طويلة، وإكسل يحوّلها لصيغة علمية (6.28E+12) فيفسد الرمز.
 * تنسيق العمود وحده لا يكفي (القيمة تُكتب رقماً فتضيع الأصفار البادئة) — فتُكتب نصاً بـ bindValue.
 * WithStrictNullComparison: بدونه تُكتب الكمية 0 خانة فارغة (مادة ليست بالفاتورة ⇒ "بالفاتورة" فارغ).
 * العمودان الأخيران فارغان ومميّزان بلون — يملؤهما المستودع، فالملف أداة تشييك لا تقرير فقط.
 */
class ReconciliationResultExport extends DefaultValueBinder implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithColumnFormatting, WithCustomValueBinder, WithStrictNullComparison
{
    private const BARCODE_COLUMN = 'C';

    /** @param Collection<int, TransferReconciliationItem> $items */
    public function __construct(
        private readonly Collection $items,
        private readonly Invoice $invoice,
    ) {
    }

    public function collection()
    {
        return $this->items;
    }

    public function headings(): array
    {
        return [
            'رقم فاتورة المناقلة', 'الصيدلية', 'رمز المادة', 'اسم المادة', 'الشركة',
            'بالفاتورة', 'محضَّر فعلياً', 'الفرق', 'حالة الفرق', 'سبب الفرق',
            'نتيجة تدقيق المستودع', 'ملاحظات المستودع',
        ];
    }

    public function map($item): array
    {
        return [
            $this->invoice->id,
            $this->invoice->pharmacy->name,
            (string) $item->product->barcode,
            $item->product->name,
            $item->product->company,
            $item->invoiced_qty,
            $item->prepared_qty,
            $item->difference,
            self::differenceStatus($item->invoiced_qty, $item->difference),
            $item->reason?->label ?? '',
            null,
            null,
        ];
    }

    /** وصف الفرق بلغة المستودع — مادة ليست بالفاتورة تُميَّز عن مجرد زيادة. */
    public static function differenceStatus(int $invoicedQty, int $difference): string
    {
        return match (true) {
            $difference === 0 => 'مطابق',
            $invoicedQty === 0 => 'مادة غير موجودة بالفاتورة',
            $difference < 0 => 'نقص بالتحضير',
            default => 'زيادة بالتحضير',
        };
    }

    public function columnFormats(): array
    {
        return [self::BARCODE_COLUMN => NumberFormat::FORMAT_TEXT];
    }

    public function bindValue(Cell $cell, $value): bool
    {
        if ($cell->getColumn() === self::BARCODE_COLUMN && $value !== null) {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->getStyle('A1:L1')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2D3748']],
            'font' => ['color' => ['argb' => 'FFFFFFFF'], 'bold' => true],
        ]);

        $lastRow = $this->items->count() + 1;
        if ($lastRow > 1) {
            $sheet->getStyle("K2:L{$lastRow}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFF7E0']],
            ]);
        }

        return [];
    }
}
