<?php

namespace App\Exports;

use App\Models\PurchaseOrder;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * 🎯 تصدير فاتورة شراء واحدة كملف Excel — الغرض المعلَن صراحة: **إرسالها
 * للمورد مباشرة** ليعرف ماذا يجهّز، وليس تقريراً داخلياً تحليلياً.
 *
 * 🎓 قرار تصميمي مهم: **لا تُعرَض أي أعمدة سعرية** (سعر الوحدة/الإجمالي)
 * — لأن محرك القرار الحالي لا يحسب أسعاراً على مستوى السطر بعد (راجع
 * وثيقة التصميم §9: "لا أسعار حالياً، ستعود لاحقاً"). عرض أعمدة بقيمة
 * "0.00" بمستند يُرسَل لمورد خارجي مُربِك ومهني بشكل سيئ. **حين تعود
 * الأسعار للنظام مستقبلاً، يجب إضافة عمودَي unit_cost وline_total_cost
 * هنا** (نقطة تذكير صريحة لأي مطوّر يعمل على هذا الملف لاحقاً).
 *
 * 🎓 أعمدة "الأولوية" و"التبرير" (لماذا اقتُرح هذا الصنف) **لا تُصدَّر
 * عمداً** — هذه معلومات داخلية لصانع القرار بالصيدلية، لا تخصّ المورد
 * ولا ينبغي أن يراها.
 */
class PurchaseOrderExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithTitle
{
    public function __construct(
        private readonly PurchaseOrder $order,
    ) {
    }

    public function collection()
    {
        return $this->order->items()->with('product')->get();
    }

    public function title(): string
    {
        return 'فاتورة شراء #' . $this->order->id;
    }

    public function headings(): array
    {
        // 🆕 (ج.2) عمود "بونص" حُذف (غير مستخدم بالشركة إطلاقاً). لم يُستبدل هنا بـ"الكمية المتوفرة
        // في الصناعة" عمداً: هذا المستند يُرسَل للمورد، ورصيدنا الداخلي ليس من شأنه.
        return ['الباركود', 'اسم المادة', 'الشركة المصنّعة', 'الكمية المطلوبة', 'ملاحظات'];
    }

    public function map($item): array
    {
        return [
            $item->product->barcode,
            $item->product->name,
            $item->product->company,
            $item->finalQuantity(),
            $item->hasCancelledQuantity() ? "أُلغي منها {$item->cancelled_qty} وحدة لاحقاً" : '',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        // ══ ترويسة معلوماتية أعلى الجدول: اسم المورد، تاريخ التوليد، حالة الفاتورة ══
        $sheet->insertNewRowBefore(1, 4);
        $sheet->setCellValue('A1', 'فاتورة شراء مقترحة #' . $this->order->id);
        $sheet->setCellValue('A2', 'المورد: ' . $this->order->supplier->name);
        $sheet->setCellValue('A3', 'تاريخ التوليد: ' . $this->order->generated_at->format('Y-m-d'));
        $sheet->setCellValue('A4', 'الحالة: ' . $this->order->status->label());

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2:A4')->getFont()->setBold(true);

        // الترويسة الفعلية للجدول أصبحت بالسطر 5 بعد الإدراج أعلاه
        $sheet->getStyle('A5:E5')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2D3748']],
            'font' => ['color' => ['argb' => 'FFFFFFFF'], 'bold' => true],
        ]);

        return [];
    }
}
