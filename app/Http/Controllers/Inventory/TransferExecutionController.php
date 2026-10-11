<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Services\Inventory\Transfers\TransferExecutionService;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * 🎯 (ب.6/ب.7) أزرار "تنفيذ"/"رفض" لنوعي المناقلات. كونترولر نحيل: كل قرار (الدورة، الحجز،
 * الإلغاء المتبادل، سجل النشاط) داخل TransferExecutionService — هنا ترجمة النتيجة لرسالة فقط.
 *
 * 🎓 لماذا كونترولر جديد لا دوال إضافية بـ InventoryController؟ ذاك تجاوز 800 سطر ويخلط
 * الرفع والفواتير والنواقص؛ هذه مسؤولية واحدة متماسكة (قرارات التنفيذ) تستحق مكانها.
 * أسماء المسارات القديمة (pharmacy-transfer.approve/dismiss) أُبقيت كما هي وأُعيد توجيهها هنا.
 */
class TransferExecutionController extends Controller
{
    public function __construct(
        private readonly TransferExecutionService $executionService,
    ) {
    }

    public function executeReverse(int $id): RedirectResponse
    {
        return $this->respond(fn () => $this->executionService->executeReverseTransfer($id)->message());
    }

    public function dismissReverse(int $id): RedirectResponse
    {
        return $this->respond(function () use ($id) {
            $this->executionService->dismissReverseTransfer($id);

            return '❌ رُفضت المناقلة العكسية — لم يُحجز أي فائض.';
        });
    }

    public function executePharmacy(int $id): RedirectResponse
    {
        return $this->respond(fn () => $this->executionService->executePharmacyTransfer($id)->message());
    }

    public function dismissPharmacy(int $id): RedirectResponse
    {
        return $this->respond(function () use ($id) {
            $this->executionService->dismissPharmacyTransfer($id);

            return '❌ رُفض الاقتراح — لم يُحجز أي فائض.';
        });
    }

    /** @param callable(): string $action */
    private function respond(callable $action): RedirectResponse
    {
        try {
            return back()->with('status', $action());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
