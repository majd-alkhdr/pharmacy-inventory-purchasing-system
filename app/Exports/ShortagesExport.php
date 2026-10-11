<?php

namespace App\Exports;

use App\Models\Shortage;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class ShortagesExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    protected array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    /**
     * 🩹 [مُصحَّحة 2026-09] كانت تُعيد بناء نفس شروط WHERE الموجودة أصلاً
     * بـ Shortage::scopeFilter() يدوياً هنا — نسخة ثانية منفصلة لنفس
     * المنطق بالضبط. الخطر: أي تعديل مستقبلي على تعريف الفلترة (كإضافة
     * source اليوم) كان يُنسى تحديثه بأحد المكانين، فيختلف الجدول عن
     * التصدير بصمت بلا أي رسالة خطأ تُنبِّه لذلك. الآن كلاهما (لوحة
     * التحكم + هذا التصدير) يستدعيان نفس الـ scope الواحد بالضبط.
     */
    public function collection()
    {
        return Shortage::filter($this->filters)
            ->with(['pharmacy', 'product'])
            ->orderBy('created_at', 'asc')
            ->get();
    }

    public function headings(): array
    {
        return [
            'اسم الصيدلية',
            'رقم المادة',
            'الشركة المصنعة',
            'اسم المادة',
            'الكمية الناقصة',
            'المصدر', // 🆕
            'تاريخ تسجيل النقص',
            'الحالة',
            'ملاحظات',
        ];
    }

    public function map($shortage): array
    {
        return [
            $shortage->pharmacy->name,
            $shortage->product->barcode,
            $shortage->product->company,
            $shortage->product->name,
            $shortage->quantity,
            // 🆕 نفس منطق status->label() تماماً — Enum Cast، لا نص حر
            $shortage->source->label(),
            $shortage->created_at->format('Y-m-d'),
            $shortage->status->label(),
            $shortage->notes ?? '-',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        // 🩹 عمود جديد (المصدر) أضاف عموداً كاملاً — 9 أعمدة الآن (A→I)
        // بدل 8 (A→H). نفس خطأ نطاق التلوين السابق كان سيتكرر هنا بالضبط
        // لو لم يُحدَّث هذا السطر أيضاً.
        $sheet->getStyle('A1:I1')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2D3748']],
            'font' => ['color' => ['argb' => 'FFFFFFFF'], 'bold' => true],
        ]);

        return [];
    }
}
