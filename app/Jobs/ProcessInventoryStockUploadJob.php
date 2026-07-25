<?php

namespace App\Jobs;

use App\Models\ImportSession;
use App\Services\Inventory\CentralInventoryStockService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessInventoryStockUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;
    public $tries   = 1;

    public function __construct(
        public string $sessionId,
        public string $invPath
    ) {}

    public function handle(CentralInventoryStockService $service): void
    {
        $service->processStockUpload($this->sessionId, $this->invPath);
    }

    public function failed(\Throwable $e): void
    {
        ImportSession::where('session_id', $this->sessionId)->update([
            'status'  => 'failed',
            'message' => 'فشلت المعالجة في الخلفية: ' . $e->getMessage(),
        ]);
    }
}
