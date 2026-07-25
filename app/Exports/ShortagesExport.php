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

    public function collection()
    {
        $query = Shortage::with(['pharmacy', 'product'])
            ->orderBy('created_at', 'asc');

        if (!empty($this->filters['status'])) {
            $query->where('status', $this->filters['status']);
        } else {
            $query->where('status', 'pending');
        }

        if (!empty($this->filters['pharmacy_id'])) {
            $query->where('pharmacy_id', $this->filters['pharmacy_id']);
        }

        if (!empty($this->filters['date_from'])) {
            $query->whereDate('created_at', '>=', $this->filters['date_from']);
        }

        if (!empty($this->filters['date_to'])) {
            $query->whereDate('created_at', '<=', $this->filters['date_to']);
        }

        return $query->get();
    }

    public function headings(): array
    {
        return [
            'اسم الصيدلية',
            'رقم المادة',
            'الشركة المصنعة',
            'اسم المادة',
            'الكمية الناقصة',
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
            $shortage->created_at->format('Y-m-d'),
            // 🩹 إصلاح: بعد إضافة Enum Cast على عمود status بموديل Shortage،
            // $shortage->status لم يعد نصاً (string) بل صار كائن ShortageStatus.
            // المقارنة القديمة "=== 'pending'" أصبحت تُرجع false دائماً (كائن لا
            // يساوي نصاً بمقارنة صارمة ===)، فكانت كل الصفوف تُصدَّر "محلول ✅"
            // حتى لو كانت فعلياً معلّقة. الحل: استخدام label() الجاهزة بالـ Enum.
            $shortage->status->label(),
            $shortage->notes ?? '-',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        // 🩹 نفس خطأ نطاق التلوين الذي كان بـ AllInvoicesExport: العناوين هنا
        // 8 أعمدة (A→H) لكن التلوين كان يقتصر على A1:G1 (7 أعمدة)، فعمود
        // "ملاحظات" (H) كان يبقى بدون تلوين الهيدر الغامق. تم تصحيحه لـ A1:H1.
        $sheet->getStyle('A1:H1')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2D3748']],
            'font' => ['color' => ['argb' => 'FFFFFFFF'], 'bold' => true],
        ]);

        return [];
    }
}
