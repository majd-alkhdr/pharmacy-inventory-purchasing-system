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
        public string $invPath,
        public ?string $salesPath = null
    ) {}

    // 🎯 التغيير الوحيد المطلوب هنا: type-hint الخدمة المتخصصة بدل الخدمة
    // العملاقة القديمة. Laravel's Service Container يحلّ باقي الاعتماديات
    // (CentralInventoryStockService, ShortageFulfillmentService,
    // SalesMappingService, InvoiceGenerationService) تلقائياً.
    public function handle(InventoryUploadService $service): void
    {
        $service->processInventoryUpload($this->sessionId, $this->invPath, $this->salesPath);
    }

    public function failed(\Throwable $e): void
    {
        ImportSession::where('session_id', $this->sessionId)->update([
            'status'  => 'failed',
            'message' => 'فشلت المعالجة في الخلفية: ' . $e->getMessage(),
        ]);
    }
}
