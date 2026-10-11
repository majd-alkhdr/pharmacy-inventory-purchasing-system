<?php

namespace App\Http\Requests\Inventory;

use App\Enums\InvoiceGenerationScope;
use App\Enums\RestockingMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 🆕 (2026-09) restocking_mode — يحدّد أي استراتيجية ترميم ستُشغَّل لهذه
 * الدفعة (sales / standard_inventory / hybrid). الملفات المطلوبة تختلف
 * فعلياً حسب الوضع المختار:
 * - sales: ملف المبيعات إلزامي (بلا بيانات مبيعات، لا شيء لهذا الوضع لينفّذه)
 * - standard_inventory / hybrid: ملف واحد على الأقل من ملفات جرد
 *   الصيدليات إلزامي (بلا جرد صيدليات، لا فجوة لمقارنتها بالمخزون القياسي)
 *
 * ملف جرد المستودع المركزي يبقى إلزامياً بالوضعين standard_inventory
 * وhybrid (كلاهما يحتاج معرفة الرصيد المركزي المتاح للتغطية بدقة).
 *
 * 🆕 (2026-09) [تغيير مقصود] أصبح **اختيارياً** بوضع sales تحديداً — بطلب
 * صريح: المخزون المركزي لا يتغيّر بالضرورة يومياً بنفس وتيرة المبيعات،
 * وفرض إعادة رفعه فقط لمعالجة مبيعات اليوم احتكاك حقيقي بلا فائدة. غيابه
 * بهذا الوضع تحديداً يعني: استخدام آخر رصيد مركزي مسجَّل بالفعل (من رفع
 * سابق، عبر أي شاشة)، بلا أي محاولة تحديثه بهذه الدفعة. راجع
 * InventoryUploadService لمنطق التعامل الفعلي مع غيابه.
 *
 * 🆕 warehouse_matrix_file: كشف واحد (مادة × موقع: المركزي وكل الصيدليات) يحل محل
 * inventory_file + pharmacy_inventory_files معاً. الجمع بينه وبينهما ممنوع (مصدران لنفس
 * الحقيقة = التباس: أيهما يفوز؟). حيثما كان الملفان المنفصلان إلزاميين، يكفي الكشف بدلاً منهما.
 */
class UploadSalesAndInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'restocking_mode' => ['required', Rule::in(array_column(RestockingMode::cases(), 'value'))],

            // 🆕 (ب.4) خانتا اختيار بالنموذج (أساسية / نواقص) — واحدة على الأقل. الغياب الكامل
            // للحقل (نموذج قديم مخزَّن بالمتصفح) = الاثنين معاً، أي السلوك القديم.
            'invoice_types'   => ['sometimes', 'array', 'min:1'],
            'invoice_types.*' => [Rule::in(['basic', 'shortages'])],

            // 🆕 صيغ الكشف: xlsx وcsv فقط (xls الثنائي القديم لا تقرؤه المكتبة المتدفقة). txt لأن
            // Laravel قد يخمّن نوع CSV المرفوع كنص عادي.
            'warehouse_matrix_file' => ['nullable', 'file', 'mimes:xlsx,csv,txt', 'max:10240'],

            // 🆕 [مُوسَّع للوضع الرابع] اختياري بوضعَي "المبيعات" و"مخزون قياسي مُفعَّل
            // بالمبيعات" كلاهما — نفس السبب بالضبط (راجع تعليق الكلاس): المخزون
            // المركزي لا يتغيّر بالضرورة بنفس وتيرة المبيعات، وكلا الوضعين يستخدمان
            // آخر رصيد مركزي مسجَّل عند غيابه، بلا محاولة تحديثه بهذه الدفعة.
            'inventory_file' => [
                'nullable',
                'file',
                'mimes:xlsx,xls,csv',
                'max:10240',
                'prohibits:warehouse_matrix_file',
                Rule::requiredIf(fn() => !in_array($this->input('restocking_mode'), [
                    RestockingMode::Sales->value,
                    RestockingMode::SalesGatedStandardInventory->value,
                ], true) && !$this->hasFile('warehouse_matrix_file')),
            ],

            'sales_file' => [
                'nullable',
                'file',
                'mimes:xlsx,xls,csv',
                'max:10240',
                // 🩹 يشمل hybrid الآن أيضاً — الواجهة (upload.blade.php) تعرض
                // شارة "إلزامي" على هذا الحقل بكلا الوضعين (sales وhybrid)
                // بلا فرق بينهما بصرياً؛ لو تركنا الخادم متساهلاً مع hybrid
                // فقط، كان تناقضاً بين ما تعد به الواجهة وما يفرضه الخادم
                // فعلياً. القرار الأبسط والأكثر اتساقاً: اختيار "مدمج" يعني
                // التزاماً بمصدري البيانات معاً بهذه الدفعة.
                // 🆕 مُوسَّع للوضع الرابع أيضاً — المبيعات هي المُحفِّز الوحيد لهذا
                // الوضع، فبلا ملف مبيعات لا يوجد شيء لينفّذه إطلاقاً (تماماً كوضع
                // "المبيعات" الأصلي).
                Rule::requiredIf(fn() => in_array($this->input('restocking_mode'), [
                    RestockingMode::Sales->value,
                    RestockingMode::Hybrid->value,
                    RestockingMode::SalesGatedStandardInventory->value,
                ], true)),
            ],

            // 🩹 عمداً بلا SalesGatedStandardInventory هنا: هذا الوضع لا يُشغِّل فحصاً
            // فورياً شاملاً عند رفع ملف جرد إطلاقاً (usesStandardInventory()=false) —
            // راجع تعليق RestockingMode::usesStandardInventory() للسبب الكامل.
            'pharmacy_inventory_files' => [
                'nullable',
                'array',
                'max:20',
                'prohibits:warehouse_matrix_file',
                Rule::requiredIf(fn() => in_array($this->input('restocking_mode'), [
                    RestockingMode::StandardInventory->value,
                    RestockingMode::Hybrid->value,
                ], true) && !$this->hasFile('warehouse_matrix_file')),
            ],
            'pharmacy_inventory_files.*' => ['file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'warehouse_matrix_file.mimes'       => 'صيغة كشف المستودعات غير مدعومة (xlsx أو csv فقط — احفظ ملفات xls بصيغة xlsx).',
            'warehouse_matrix_file.max'         => 'حجم كشف المستودعات يتجاوز الحد المسموح (10MB).',
            'inventory_file.prohibits'    => 'لا تجمع بين كشف المستودعات وملف الجرد المركزي — الكشف يشمل المركزي.',
            'pharmacy_inventory_files.prohibits' => 'لا تجمع بين كشف المستودعات وملفات جرد الصيدليات — الكشف يشمل الصيدليات.',
            'restocking_mode.required'          => 'يرجى اختيار طريقة الترميم لهذه الدفعة.',
            'invoice_types.min'                 => 'اختر نوع فواتير واحداً على الأقل (أساسية أو نواقص).',
            'invoice_types.*.in'                => 'نوع الفواتير المختار غير صالح.',
            'restocking_mode.in'                 => 'طريقة الترميم المختارة غير صالحة.',
            'inventory_file.required'           => 'الوضع المختار يحتاج ملف جرد المستودع المركزي (اختياري فقط بوضعَي "بناءً على المبيعات" و"مخزون قياسي مُفعَّل بالمبيعات").',
            'inventory_file.mimes'              => 'صيغة ملف الجرد غير مدعومة (xlsx, xls, csv فقط).',
            'inventory_file.max'                => 'حجم ملف الجرد يتجاوز الحد المسموح (10MB).',
            'sales_file.required'                => 'الوضع المختار يحتاج بيانات مبيعات — يرجى إرفاق ملف المبيعات.',
            'sales_file.mimes'                   => 'صيغة ملف المبيعات غير مدعومة (xlsx, xls, csv فقط).',
            'pharmacy_inventory_files.required' => 'الوضع المختار يحتاج جرد صيدليات (بناءً على المخزون القياسي أو المدمج) — يرجى إرفاق ملف واحد على الأقل.',
            'pharmacy_inventory_files.max'      => 'لا يمكن رفع أكثر من 20 ملف جرد صيدليات دفعة واحدة.',
            'pharmacy_inventory_files.*.mimes'  => 'صيغة أحد ملفات جرد الصيدليات غير مدعومة (xlsx, xls, csv فقط).',
            'pharmacy_inventory_files.*.max'    => 'حجم أحد ملفات جرد الصيدليات يتجاوز الحد المسموح (10MB).',
        ];
    }

    public function restockingMode(): RestockingMode
    {
        return RestockingMode::from($this->validated('restocking_mode'));
    }

    /** 🆕 (ب.4) ترجمة خانتي الاختيار إلى قيمة Enum واحدة تُخزَّن على الجلسة. */
    public function invoiceScope(): InvoiceGenerationScope
    {
        $types = $this->validated('invoice_types', ['basic', 'shortages']);

        return match (true) {
            in_array('basic', $types, true) && in_array('shortages', $types, true) => InvoiceGenerationScope::Both,
            in_array('shortages', $types, true) => InvoiceGenerationScope::Shortages,
            default => InvoiceGenerationScope::Basic,
        };
    }
}
