<?php

namespace App\Jobs;

use App\Models\ImportSession;
use App\Services\Inventory\InventoryUploadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessInventoryUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;
    public $tries   = 1;

    public function __construct(
        public string $sessionId,
        // 🆕 (2026-09) أصبح اختيارياً — بوضع "بناءً على المبيعات" تحديداً
        // يمكن عدم رفع ملف جرد مركزي جديد إطلاقاً، فيُستخدَم آخر رصيد
        // مسجَّل بالفعل بلا أي محاولة تحديثه. راجع InventoryUploadService
        // لمنطق التعامل الفعلي مع غيابه.
        public ?string $invPath = null,
        public ?string $salesPath = null,
        // 🆕 كان ?string $pharmacyInvPath — أصبح مصفوفة لدعم عدة ملفات
        // جرد صيدليات دفعة واحدة (راجع InventoryUploadService للدمج الفعلي)
        public array $pharmacyInvPaths = [],
        // 🆕 كشف المستودعات (المصفوفة) — يحل محل $invPath و$pharmacyInvPaths معاً.
        public ?string $matrixPath = null,
    ) {}

    public function handle(InventoryUploadService $service): void
    {
        $service->processInventoryUpload($this->sessionId, $this->invPath, $this->salesPath, $this->pharmacyInvPaths, $this->matrixPath);
    }

    public function failed(\Throwable $e): void
    {
        ImportSession::where('session_id', $this->sessionId)->update([
            'status'  => 'failed',
            'message' => 'فشلت المعالجة في الخلفية: ' . $e->getMessage(),
        ]);

        // 🆕 (أ.1) هذا المسار لا يمر بـ RecordsImportSession — فيُزامَن سجل الرفع صراحة.
        app(\App\Services\Shared\ImportHistory\ImportHistoryService::class)
            ->syncSessionStatus($this->sessionId, 'failed', 'فشلت المعالجة في الخلفية: ' . $e->getMessage());
    }
}
