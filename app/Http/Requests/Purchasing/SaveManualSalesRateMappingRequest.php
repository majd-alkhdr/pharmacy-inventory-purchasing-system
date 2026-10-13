<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveManualSalesRateMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'session_id' => ['required', 'string'],
            'decisions'  => ['nullable', 'array'], // [pending_id => 'new'|'skip'|product_id]
            'barcodes'   => ['nullable', 'array'], // [pending_id => الباركود المُدخَل يدوياً عند اختيار "مادة جديدة"]
            'barcodes.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * تحقق شرطي لا يمكن التعبير عنه بقواعد rules() الساكنة: الباركود
     * إلزامي فقط للأسطر المختارة بـ "مادة جديدة" (choice === 'new')،
     * ويجب أن يكون فريداً مقابل قاعدة المنتجات الحالية، وفريداً أيضاً
     * بين الأسطر المُرسَلة بهذا الطلب نفسه (لمنع تكرار الباركود بين
     * منتجين جديدين بنفس الدفعة — unique:products,barcode لا يكتشف
     * هذا التكرار الداخلي لأنه يفحص فقط مقابل الصفوف المحفوظة بالفعل).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $decisions = $this->input('decisions', []);
            $barcodes  = $this->input('barcodes', []);

            $seen = [];

            foreach ($decisions as $pendingId => $choice) {
                if ($choice !== 'new') {
                    continue;
                }

                $barcode = trim((string) ($barcodes[$pendingId] ?? ''));

                if ($barcode === '') {
                    $validator->errors()->add(
                        "barcodes.{$pendingId}",
                        'الباركود مطلوب عند إضافة مادة جديدة.'
                    );
                    continue;
                }

                if (isset($seen[$barcode])) {
                    $validator->errors()->add(
                        "barcodes.{$pendingId}",
                        'هذا الباركود مكرر مع صف آخر بنفس القائمة.'
                    );
                    continue;
                }
                $seen[$barcode] = true;

                if (\App\Models\Product::where('barcode', $barcode)->exists()) {
                    $validator->errors()->add(
                        "barcodes.{$pendingId}",
                        'هذا الباركود مستخدَم بالفعل لمادة موجودة.'
                    );
                }
            }
        });
    }

    public function sessionId(): string
    {
        return $this->validated('session_id');
    }

    public function decisions(): array
    {
        return $this->input('decisions', []);
    }

    /**
     * [pending_id => باركود مُدخَل يدوياً] — تُستعمل فقط للأسطر المختارة
     * بـ "مادة جديدة"؛ الأسطر الأخرى (skip/ربط بمنتج موجود) تُرسِل قيمة
     * فارغة أو لا تُرسِل شيئاً، وتُتجاهَل بالخدمة.
     */
    public function barcodes(): array
    {
        return $this->input('barcodes', []);
    }
}
