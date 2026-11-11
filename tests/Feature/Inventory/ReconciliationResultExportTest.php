<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Exports\ReconciliationResultExport;
use App\Models\Invoice;
use App\Models\Pharmacy;
use App\Models\Product;
use App\Models\Role;
use App\Models\TransferReconciliation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/** 🎯 (ب.3) تصدير نتيجة آخر مطابقة للمستودع — للقراءة فقط، الفروقات افتراضياً. */
final class ReconciliationResultExportTest extends TestCase
{
    use RefreshDatabase;

    private Invoice $invoice;
    private int $productSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['key' => 'ops_test', 'name' => 'عمليات', 'can_view_transfers_operations' => true]);
        $this->actingAs(User::create(['name' => 'مختبر', 'email' => 'ops@test.local', 'password' => 'secret123', 'role_id' => $role->id, 'is_active' => true]));

        // اسم بـ "/" عمداً — عطل سابق موثّق (SafeExportFilename).
        $pharmacy = Pharmacy::create(['name' => 'رانيا شاهين / جرمانا']);
        $this->invoice = Invoice::create(['pharmacy_id' => $pharmacy->id, 'date' => now()->toDateString(), 'status' => 'processed']);

        Excel::fake();
    }

    /** @param list<array{0: int, 1: int}> $lines [invoiced, prepared] */
    private function reconcile(array $lines): TransferReconciliation
    {
        $reconciliation = TransferReconciliation::create([
            'invoice_id' => $this->invoice->id,
            'items_count' => count($lines),
            'mismatched_count' => count(array_filter($lines, fn (array $l) => $l[0] !== $l[1])),
        ]);
        foreach ($lines as [$invoiced, $prepared]) {
            $i = $this->productSeq++;
            $product = Product::create(['barcode' => '62800000000' . $i, 'name' => "مادة {$i}", 'company' => 'ش']);
            $reconciliation->items()->create(['product_id' => $product->id, 'invoiced_qty' => $invoiced, 'prepared_qty' => $prepared, 'difference' => $prepared - $invoiced]);
        }

        return $reconciliation;
    }

    private function fileName(string $suffix): string
    {
        return "نتيجة-مطابقة-مناقلة-{$this->invoice->id}-{$suffix}-رانيا شاهين - جرمانا.xlsx";
    }

    public function test_default_export_has_only_mismatched_items_biggest_shortage_first(): void
    {
        $this->reconcile([[10, 10], [5, 4], [8, 2], [0, 3]]);

        $this->get(route('inventory.invoice.reconciliation.export', $this->invoice->id))->assertOk();

        Excel::assertDownloaded($this->fileName('فروقات'), function (ReconciliationResultExport $export) {
            $this->assertSame([-6, -1, 3], $export->collection()->pluck('difference')->map(fn ($d) => (int) $d)->all());
            $this->assertSame('628000000002', $export->map($export->collection()->first())[2]);

            return true;
        });
    }

    public function test_all_flag_exports_every_item(): void
    {
        $this->reconcile([[10, 10], [5, 4]]);

        $this->get(route('inventory.invoice.reconciliation.export', [$this->invoice->id, 'all' => 1]))->assertOk();

        Excel::assertDownloaded($this->fileName('كامل'), fn (ReconciliationResultExport $export) => $export->collection()->count() === 2);
    }

    public function test_only_the_latest_reconciliation_is_exported(): void
    {
        $this->reconcile([[10, 1]]);
        $this->reconcile([[4, 3], [6, 6]]);

        $this->get(route('inventory.invoice.reconciliation.export', [$this->invoice->id, 'all' => 1]))->assertOk();

        Excel::assertDownloaded($this->fileName('كامل'), fn (ReconciliationResultExport $export) => $export->collection()->count() === 2);
    }

    public function test_an_invoice_without_reconciliation_goes_back_with_an_error(): void
    {
        $this->get(route('inventory.invoice.reconciliation.export', $this->invoice->id))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_a_fully_matched_reconciliation_without_all_goes_back_with_info(): void
    {
        $this->reconcile([[10, 10]]);

        $this->get(route('inventory.invoice.reconciliation.export', $this->invoice->id))
            ->assertRedirect()
            ->assertSessionHas('info');
    }

    public function test_difference_status_covers_all_four_cases(): void
    {
        $this->assertSame('مطابق', ReconciliationResultExport::differenceStatus(5, 0));
        $this->assertSame('مادة غير موجودة بالفاتورة', ReconciliationResultExport::differenceStatus(0, 3));
        $this->assertSame('نقص بالتحضير', ReconciliationResultExport::differenceStatus(5, -2));
        $this->assertSame('زيادة بالتحضير', ReconciliationResultExport::differenceStatus(5, 2));
    }
}
