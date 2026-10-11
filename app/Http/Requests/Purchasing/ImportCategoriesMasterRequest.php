<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 🩹 إصلاح عاجل (2026-08): ملف "قائمة التصنيفات الرئيسية" مصمَّم عمداً
 * ليكون عموداً واحداً فقط (اسم التصنيف). ملف CSV بعمود واحد بلا أي فاصلة
 * (,) بمحتواه غالباً ما يُكتشَف بواسطة fileinfo كـ"text/plain" بدل
 * "text/csv" — وقاعدة `mimes:csv` بـ Laravel تتحقق من الامتداد المُستنتَج
 * من النوع الحقيقي المكتشَف، فترفض الملف رغم أنه CSV صحيح فعلياً بامتداده
 * واسمه ومحتواه. هذا خلل معروف وموثَّق بـ Laravel/PHP، وليس خطأً بالملف.
 *
 * 🎯 الحل: استبدال `mimes:xlsx,xls,csv` بقاعدة `mimetypes:` تسرد كل
 * الأنواع المحتملة فعلياً لملف CSV (بما فيها text/plain لهذه الحالة
 * تحديداً)، بالإضافة لأنواع xlsx/xls القياسية — بدل الاعتماد فقط على
 * تخمين الامتداد. الحماية الفعلية من الملفات الخبيثة تبقى قائمة: هذه
 * القائمة لا تزال تستثني أي نوع تنفيذي أو غير نصي/جدولي.
 */
class ImportCategoriesMasterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'categories_file' => [
                'required',
                'file',
                'max:5120',
                'mimetypes:' . implode(',', [
                    // Excel
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // xlsx
                    'application/vnd.ms-excel', // xls (وأيضاً بعض أنظمة تصنّف csv هكذا)
                    // CSV بكل الاحتمالات الواقعية التي يُصنَّفها بها fileinfo
                    'text/csv',
                    'text/plain', // 🩹 هذا تحديداً ما يحل مشكلتك — ملف عمود واحد بلا فواصل
                    'application/csv',
                    'text/comma-separated-values',
                ]),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'categories_file.required'  => 'يرجى اختيار ملف قائمة التصنيفات.',
            'categories_file.mimetypes' => 'صيغة الملف غير مدعومة — يرجى رفع ملف Excel (xlsx/xls) أو CSV صحيح.',
            'categories_file.max'        => 'حجم الملف يتجاوز الحد المسموح (5MB).',
        ];
    }
}
