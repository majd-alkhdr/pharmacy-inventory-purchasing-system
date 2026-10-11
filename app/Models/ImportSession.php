<?php

namespace App\Models;

use App\Enums\RestockingMode;
use Illuminate\Database\Eloquent\Model;

class ImportSession extends Model
{
    protected $fillable = [
        'session_id',
        'type',
        'status',
        'message',
        'has_sales',
        'restocking_mode',                     // 🆕
        'invoice_scope',                       // 🆕 (ب.4)
        'inventory_file_path',
        'sales_file_path',
        'pharmacy_inventory_file_path',       // ⚠️ قديم — لم يعد يُكتَب إليه، أُبقي بلا حذف
        'pharmacy_inventory_file_paths',       // 🆕 مصفوفة مسارات (يدعم عدة ملفات دفعة واحدة)
        'shortages_file_path',
        'excluded_restocking_companies',
        'pharmacy_inventory_pharmacy_ids',
        'warehouse_matrix_file_path',          // 🆕 كشف المستودعات (المصفوفة)
        'import_diagnostics',                  // 🆕 تحذيرات الاستيراد (JSON)
    ];

    protected $casts = [
        'has_sales'                        => 'boolean',
        'restocking_mode'                  => RestockingMode::class, // 🆕
        'invoice_scope'                    => \App\Enums\InvoiceGenerationScope::class, // 🆕 (ب.4)
        'excluded_restocking_companies'   => 'array',
        'pharmacy_inventory_pharmacy_ids' => 'array',
        'pharmacy_inventory_file_paths'   => 'array', // 🆕
        'import_diagnostics'              => 'array', // 🆕
    ];

    /** 🆕 هل هذه الجلسة استيراد "كشف المستودعات" (المصفوفة)؟ */
    public function isMatrixSession(): bool
    {
        return !empty($this->warehouse_matrix_file_path);
    }

    public function redirectRouteName(): ?string
    {
        return match ($this->status) {
            'needs_pending_products' => 'inventory.pending-inventory.show',
            'needs_mapping' => $this->type === 'shortages'
                ? 'inventory.shortages.mapping.show'
                : 'inventory.mapping.show',
            default => null,
        };
    }

    public function resolveRedirectUrl(): ?string
    {
        $routeName = $this->redirectRouteName();

        return $routeName ? route($routeName, $this->session_id) : null;
    }

    public function excludedRestockingCompanies(): array
    {
        return $this->excluded_restocking_companies ?? [];
    }

    /** 🆕 دائماً مصفوفة، حتى لو NULL بقاعدة البيانات (جلسة قديمة قبل هذا التحديث) */
    public function pharmacyInventoryFilePaths(): array
    {
        return $this->pharmacy_inventory_file_paths ?? [];
    }

    /**
     * 🆕 نسخة آمنة من restocking_mode — أي جلسة قديمة أُنشئت قبل هذا
     * التحديث ستحمل القيمة الافتراضية بقاعدة البيانات (standard_inventory)
     * تلقائياً فور تشغيل المايجريشن، فهذه الدالة عملياً لن تُعيد null أبداً
     * لجلسات حقيقية — لكنها موجودة كخط دفاع أخير صريح بدل الاعتماد الضمني
     * على أن العمود لن يكون NULL أبداً بأي ظرف.
     */
    public function restockingMode(): RestockingMode
    {
        return $this->restocking_mode ?? RestockingMode::StandardInventory;
    }

    /** 🆕 (ب.4) نفس فلسفة restockingMode(): جلسة بلا قيمة = السلوك القديم (الاثنين معاً). */
    public function invoiceScope(): \App\Enums\InvoiceGenerationScope
    {
        return $this->invoice_scope ?? \App\Enums\InvoiceGenerationScope::Both;
    }
}
