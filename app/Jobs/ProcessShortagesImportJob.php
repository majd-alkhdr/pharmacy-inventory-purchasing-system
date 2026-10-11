<?php

namespace App\Jobs;

use App\Models\ImportSession;
use App\Services\Inventory\ShortageImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessShortagesImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;
    public $tries   = 1;

    public function __construct(
        public string $sessionId,
        public string $filePath
    ) {}

    public function handle(ShortageImportService $service): void
    {
        $service->processShortagesImport($this->sessionId, $this->filePath);
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
