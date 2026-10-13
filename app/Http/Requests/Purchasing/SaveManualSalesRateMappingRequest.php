<?php

namespace App\Http\Requests\Purchasing;

use App\Models\Product;
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
            'session_id'  => ['required', 'string'],
            'decisions'   => ['nullable', 'array'], // [pending_id => 'new'|product_id|'skip']
            'barcodes'    => ['nullable', 'array'], // [pending_id => barcode]
            'barcodes.*'  => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * 🎯 products.barcode صار إلزامياً وفريداً (migration add_barcode_as_primary_identifier)
     * — فكل سطر مختار بـ"مادة جديدة" يجب أن يحمل باركوداً غير فارغ، غير مكرَّر داخل
     * نفس الطلب، وغير مستخدَم لمادة موجودة؛ نرفضه هنا قبل الوصول لقاعدة البيانات.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $barcodes = $this->barcodes();
            $seen = [];

            foreach ($this->decisions() as $pendingId => $choice) {
                if ($choice !== 'new') {
                    continue;
                }

                $barcode = trim((string) ($barcodes[$pendingId] ?? ''));

                if ($barcode === '') {
                    $validator->errors()->add(
                        "barcodes.{$pendingId}",
                        'الباركود إلزامي لإنشاء مادة جديدة.'
                    );
                    continue;
                }

                if (isset($seen[$barcode])) {
                    $validator->errors()->add(
                        "barcodes.{$pendingId}",
                        'هذا الباركود مُدخَل لأكثر من سطر بنفس الطلب.'
                    );
                    continue;
                }
                $seen[$barcode] = true;

                if (Product::where('barcode', $barcode)->exists()) {
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
     * [pending_id => باركود مُدخَل يدوياً] — فقط للأسطر المختارة بـ "مادة جديدة"؛
     * الأسطر الأخرى (skip/ربط بمنتج موجود) تُرسِل قيمة فارغة أو لا تُرسِل شيئاً،
     * وتُتجاهَل بالخدمة.
     */
    public function barcodes(): array
    {
        return $this->input('barcodes', []);
    }
}
