<?php

namespace App\Jobs;

use App\Models\ImportSession;
use App\Services\Inventory\SalesMappingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessSaveMappingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;
    public $tries   = 1;

    public function __construct(
        public string $sessionId,
        public array $pharmacies,
        public array $products
    ) {}

    public function handle(SalesMappingService $service): void
    {
        $result = $service->saveMapping($this->sessionId, $this->pharmacies, $this->products);

        ImportSession::where('session_id', $this->sessionId)->update([
            'status'  => 'completed',
            'message' => $result['warning'] ?? $result['message'] ?? 'تمت العملية.',
        ]);
    }

    public function failed(\Throwable $e): void
    {
        ImportSession::where('session_id', $this->sessionId)->update([
            'status'  => 'failed',
            'message' => 'فشلت عملية حفظ المطابقة: ' . $e->getMessage(),
        ]);
    }
}
