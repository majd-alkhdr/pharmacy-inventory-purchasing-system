<?php

namespace App\Exports;

use App\Models\PurchaseOrder;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * 🎯 تصدير Excel من شاشة "فواتير الشراء المقترحة" (القائمة الكاملة) —
 * يحترم نفس فلاتر الحالة/المورد المُطبَّقة حالياً بالشاشة، بنفس نمط
 * AllInvoicesExport تماماً بقسم المناقلات (اتساق معماري).
 *
 * ⚠️ هذا تصدير "إداري/تقريري" (نظرة شاملة على كل الفواتير)، بخلاف
 * PurchaseOrderExport (تصدير فاتورة واحدة، مصمَّم لإرسالها للمورد) —
 * لذلك هنا **نُظهر عمود الحالة والمورد** (مفيد إدارياً)، ولا نزال نستثني
 * الأعمدة السعرية لنفس السبب (لا أسعار على مستوى السطر بعد).
 */
class AllPurchaseOrdersExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    protected array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    /** @var array<int, int> 🆕 (ج.2) رصيد المركزي (ملف المصفوفة) لكل مادة — استعلام واحد عبر CentralStockReader */
    private array $industryStock = [];

    public function collection()
    {
        $rows = PurchaseOrder::with(['supplier', 'items.product'])
            ->filter($this->filters)
            ->orderByDesc('generated_at')
            ->get()
            ->flatMap(fn (PurchaseOrder $order) => $order->items->map(fn ($item) => (object) [
                'order' => $order,
                'item'  => $item,
            ]));

        $this->industryStock = app(\App\Services\Shared\Stock\CentralStockReader::class)->quantitiesFor($rows->pluck('item.product_id'));

        return $rows;
    }

    public function headings(): array
    {
        // 🆕 (ج.2) "بونص" (غير مستخدم) ← "الكمية المتوفرة في الصناعة" — تقرير داخلي، بنفس الشاشة.
        return ['رقم الفاتورة', 'المورد', 'الحالة', 'تاريخ التوليد', 'الباركود', 'اسم المادة', 'الشركة', 'الكمية المطلوبة', 'الكمية المتوفرة في الصناعة', 'الأولوية'];
    }

    public function map($row): array
    {
        return [
            '#' . $row->order->id,
            $row->order->supplier->name,
            $row->order->status->label(),
            $row->order->generated_at->format('Y-m-d'),
            $row->item->product->barcode,
            $row->item->product->name,
            $row->item->product->company,
            $row->item->finalQuantity(),
            $this->industryStock[$row->item->product_id] ?? '—',
            // 🎯 نص مبسَّط للأولوية بدل الرقم الخام — أوضح لقارئ التقرير
            $this->priorityLabelFor($row->item),
        ];
    }

    private function priorityLabelFor($item): string
    {
        $factors = $item->explanation_factors ?? [];

        return match (true) {
            $item->is_market_shortage                    => 'قنص نواقص سوق',
            ($factors['is_pharmacy_shortage'] ?? false)  => 'نقص فعلي بالصيدليات',
            $item->is_target_item                          => 'صنف تارجت',
            ($factors['is_critical_shortage'] ?? false)  => 'نقص حرج',
            ($factors['is_high_turnover'] ?? false)       => 'دوران عالٍ',
            default                                          => 'عادي',
        };
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->getStyle('A1:J1')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2D3748']],
            'font' => ['color' => ['argb' => 'FFFFFFFF'], 'bold' => true],
        ]);

        return [];
    }
}
