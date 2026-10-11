<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\ImportFileType;
use App\Enums\ImportLogStatus;
use App\Enums\PharmacyTransferStatus;
use App\Enums\ReverseTransferStatus;
use App\Enums\TransferItemExecutionStatus;
use App\Models\CentralInventory;
use App\Models\ImportLog;
use App\Models\Pharmacy;
use App\Models\PharmacyInventory;
use App\Models\PharmacyMinimumStock;
use App\Models\PharmacyTransfer;
use App\Models\Product;
use App\Models\ReverseTransfer;
use App\Models\SurplusCommitment;
use App\Services\Inventory\InterPharmacyTransferPlanner;
use App\Services\Inventory\ParLevelReplenishmentService;
use App\Services\Inventory\PharmacyTransferSuggestionService;
use App\Services\Inventory\ReverseTransferRecorderService;
use App\Services\Inventory\Surplus\SurplusEngine;
use App\Services\Inventory\Transfers\PendingTransfersQuery;
use App\Services\Inventory\Transfers\TransferExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * 🎯 (أ.3 + ب.6 + ب.7) يحل محل ReverseTransferPharmacyInventorySyncTest. ذاك كان يحمي منع
 * "اقتراح نفس الفائض مرتين" عبر طرح الكمية من pharmacy_inventories فور تسجيل العكسية. الآن
 * الحماية نفسها تتحقق عبر التزامات SurplusEngine — واللقطة المرفوعة لا تُمسّ إطلاقاً.
 */
final class SurplusCommitmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Pharmacy $overstocked;
    private Pharmacy $needy;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startCycle();

        $this->overstocked = Pharmacy::create(['name' => 'صيدلية فائضة']);
        $this->needy = Pharmacy::create(['name' => 'صيدلية محتاجة']);
        $this->product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'ش']);
        CentralInventory::create(['product_id' => $this->product->id, 'quantity' => 0]);

        // فائض فعّال 80 (100 − 20) — فجوة 40 لدى المحتاجة (50 − 10).
        PharmacyInventory::create(['pharmacy_id' => $this->overstocked->id, 'product_id' => $this->product->id, 'quantity' => 100]);
        PharmacyMinimumStock::create(['pharmacy_id' => $this->overstocked->id, 'product_id' => $this->product->id, 'minimum_quantity' => 20]);
        PharmacyInventory::create(['pharmacy_id' => $this->needy->id, 'product_id' => $this->product->id, 'quantity' => 10]);
        PharmacyMinimumStock::create(['pharmacy_id' => $this->needy->id, 'product_id' => $this->product->id, 'minimum_quantity' => 50]);
    }

    private function startCycle(): ImportLog
    {
        return ImportLog::create([
            'file_type' => ImportFileType::WarehouseMatrix, 'original_filename' => 'matrix.xlsx',
            'status' => ImportLogStatus::Succeeded, 'started_at' => now(), 'finished_at' => now(),
        ]);
    }

    private function recordReverseSuggestion(): ReverseTransfer
    {
        (new ParLevelReplenishmentService(new ReverseTransferRecorderService()))->replenish([$this->overstocked->id]);

        return ReverseTransfer::firstOrFail();
    }

    private function engine(): SurplusEngine
    {
        return $this->app->make(SurplusEngine::class);
    }

    private function executor(): TransferExecutionService
    {
        return $this->app->make(TransferExecutionService::class);
    }

    public function test_recording_a_reverse_transfer_moves_no_stock_and_tags_the_current_cycle(): void
    {
        $transfer = $this->recordReverseSuggestion();

        $this->assertSame(ReverseTransferStatus::Suggested, $transfer->status);
        $this->assertSame(ImportLog::latest('id')->value('id'), $transfer->cycle_import_log_id);
        $this->assertSame(100, (int) PharmacyInventory::where('pharmacy_id', $this->overstocked->id)->value('quantity'));
        $this->assertSame(0, (int) CentralInventory::where('product_id', $this->product->id)->value('quantity'));
        $this->assertSame(80, $this->engine()->snapshot()->available($this->overstocked->id, $this->product->id));
    }

    public function test_executing_the_reverse_transfer_commits_the_surplus_and_hides_the_competing_pharmacy_transfer(): void
    {
        $reverse = $this->recordReverseSuggestion();
        $this->app->make(PharmacyTransferSuggestionService::class)->refresh();
        $this->assertSame(1, $this->app->make(PendingTransfersQuery::class)->pharmacyTransfers()->count());

        $outcome = $this->executor()->executeReverseTransfer($reverse->id);

        $this->assertTrue($outcome->wasFullyExecuted());
        $this->assertSame(80, (int) SurplusCommitment::sum('quantity'));
        $this->assertSame(0, $this->engine()->snapshot()->available($this->overstocked->id, $this->product->id));
        // الإلغاء المتبادل: المقترحة بين الصيدليات اختفت من قائمة المعلَّق بلا أي حذف لها.
        $this->assertSame(0, $this->app->make(PendingTransfersQuery::class)->pharmacyTransfers()->count());
        $this->assertSame(1, PharmacyTransfer::suggested()->count());
        // ولم يعد المخطِّط يقترح الفائض نفسه مرة أخرى.
        $this->assertSame([], $this->app->make(InterPharmacyTransferPlanner::class)->plan());
        // اللقطة المرفوعة لم تُمسّ.
        $this->assertSame(100, (int) PharmacyInventory::where('pharmacy_id', $this->overstocked->id)->value('quantity'));
    }

    public function test_executing_the_pharmacy_transfer_first_supersedes_the_reverse_transfer_item(): void
    {
        $reverse = $this->recordReverseSuggestion();
        $this->app->make(PharmacyTransferSuggestionService::class)->refresh();
        $pharmacyTransfer = PharmacyTransfer::firstOrFail();

        $this->executor()->executePharmacyTransfer($pharmacyTransfer->id);

        // 40 حُجزت للمحتاجة ⇒ المتاح 40 < 80 (كمية العكسية) ⇒ العكسية اختفت من المعلَّق.
        $this->assertSame(40, $this->engine()->snapshot()->available($this->overstocked->id, $this->product->id));
        $this->assertSame(0, $this->app->make(PendingTransfersQuery::class)->reverseTransfers()->count());

        // ومحاولة تنفيذها مباشرة تُرفض كلياً (لا تنفيذ جزئي لكمية بند) وتتراجع بلا أثر.
        try {
            $this->executor()->executeReverseTransfer($reverse->id);
            $this->fail('كان يجب رفض تنفيذ عكسية فقد بندها الوحيد فائضه.');
        } catch (RuntimeException) {
        }

        $this->assertSame(ReverseTransferStatus::Suggested, $reverse->fresh()->status);
        $this->assertSame(40, (int) SurplusCommitment::sum('quantity'));
        $this->assertSame(PharmacyTransferStatus::Completed, $pharmacyTransfer->fresh()->status);
    }

    public function test_a_document_with_one_covered_and_one_uncovered_item_executes_the_covered_one_only(): void
    {
        $second = Product::create(['barcode' => '222', 'name' => 'أموكسيل', 'company' => 'ش']);
        PharmacyInventory::create(['pharmacy_id' => $this->overstocked->id, 'product_id' => $second->id, 'quantity' => 30]);
        PharmacyMinimumStock::create(['pharmacy_id' => $this->overstocked->id, 'product_id' => $second->id, 'minimum_quantity' => 10]);

        $reverse = $this->recordReverseSuggestion();
        $this->assertSame(2, $reverse->items()->count());

        // التزام خارجي يستهلك فائض المادة الثانية بالكامل (20).
        $other = ReverseTransfer::create(['pharmacy_id' => $this->overstocked->id, 'date' => now()->toDateString(), 'cycle_import_log_id' => $reverse->cycle_import_log_id]);
        $otherItem = $other->items()->create(['product_id' => $second->id, 'quantity' => 20, 'standard_quantity_at_time' => 10, 'pharmacy_quantity_at_time' => 30]);
        \Illuminate\Support\Facades\DB::transaction(fn () => $this->engine()->commitIfAvailable($this->engine()->currentCycle(), $this->overstocked->id, $second->id, 20, $otherItem));

        $outcome = $this->executor()->executeReverseTransfer($reverse->id);

        $this->assertSame(1, $outcome->executedCount);
        $this->assertSame(1, $outcome->supersededCount);
        $statuses = $reverse->items()->pluck('execution_status', 'product_id');
        $this->assertSame(TransferItemExecutionStatus::Executed, $statuses[$this->product->id]);
        $this->assertSame(TransferItemExecutionStatus::Superseded, $statuses[$second->id]);
    }

    public function test_a_new_upload_cycle_resets_commitments_and_archives_old_suggestions(): void
    {
        $reverse = $this->recordReverseSuggestion();
        $this->executor()->executeReverseTransfer($reverse->id);
        $this->assertSame(0, $this->engine()->snapshot()->available($this->overstocked->id, $this->product->id));

        // رفعة كشف جديدة = دورة جديدة: الالتزامات القديمة لم تعد تُطرح (لا حذف، فقط خارج الدورة).
        $this->travel(1)->minutes();
        $this->startCycle();
        $this->assertSame(80, $this->engine()->snapshot()->available($this->overstocked->id, $this->product->id));

        // مقترحة من الدورة السابقة لم تُنفَّذ ⇒ أرشيف قراءة فقط.
        $stale = ReverseTransfer::create(['pharmacy_id' => $this->overstocked->id, 'date' => now()->toDateString(), 'status' => ReverseTransferStatus::Suggested, 'cycle_import_log_id' => $reverse->cycle_import_log_id]);
        $stale->items()->create(['product_id' => $this->product->id, 'quantity' => 10, 'standard_quantity_at_time' => 20, 'pharmacy_quantity_at_time' => 100]);

        $this->expectException(RuntimeException::class);
        $this->executor()->executeReverseTransfer($stale->id);
    }
}
