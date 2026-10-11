<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Enums\AuditAction;
use App\Enums\ImportFileType;
use App\Enums\ImportLogStatus;
use App\Enums\ProductMasterField;
use App\Models\AuditLog;
use App\Models\CentralInventory;
use App\Models\CentralInventoryPrice;
use App\Models\ImportLog;
use App\Models\Product;
use App\Models\ProductFieldSource;
use App\Models\Role;
use App\Models\User;
use App\Services\Inventory\CentralInventoryPriceRecorderService;
use App\Services\Inventory\Import\MaterialMaster\MaterialMasterImporter;
use App\Services\Inventory\ProductMasterData\FieldSourceInfo;
use App\Services\Inventory\ProductMasterData\ProductMasterDataQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Tests\TestCase;

/**
 * 🎯 شاشة بيانات المادة: التصحيح اليدوي مؤقت (الرفع التالي يكتب فوقه إن حمل قيمة)، والخلية الفارغة
 * بملف الأصناف لا تمس أي قيمة مخزَّنة — يدوية كانت أو من رفع سابق.
 */
final class ProductMasterDataTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = ['رمز المادة', 'اسم المادة', 'الشركة', 'السعر', 'حجم العبوة', 'الحد الأدنى للفرق'];

    private User $user;
    private Product $product;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['key' => 'pm_test', 'name' => 'مشتريات', 'can_view_purchasing_operations' => true]);
        $this->user = User::create(['name' => 'موظف المشتريات', 'email' => 'pm@test.local', 'password' => 'secret123', 'role_id' => $role->id, 'is_active' => true]);
        $this->product = Product::create(['barcode' => '111', 'name' => 'بنادول', 'company' => 'شركة أ', 'pack_size' => 10]);
        CentralInventoryPrice::create(['product_id' => $this->product->id, 'price' => 10, 'effective_from' => now()->subDay()->toDateString()]);
        CentralInventory::create(['product_id' => $this->product->id, 'quantity' => 40]);
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function uploadMaster(array $row): ImportLog
    {
        $path = $this->tempFiles[] = sys_get_temp_dir().'/pm-'.bin2hex(random_bytes(6)).'.xlsx';
        $writer = new XlsxWriter();
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(self::HEADER));
        $writer->addRow(Row::fromValues($row));
        $writer->close();

        $log = ImportLog::create(['file_type' => ImportFileType::MaterialMaster, 'original_filename' => 'أصناف.xlsx', 'status' => ImportLogStatus::Processing, 'started_at' => now(), 'user_id' => $this->user->id]);
        $this->app->make(MaterialMasterImporter::class)->import($path, $log->id);
        $log->update(['status' => ImportLogStatus::Succeeded, 'finished_at' => now()]);

        return $log;
    }

    private function correct(array $input)
    {
        return $this->put(route('purchasing.product-master-data.update', $this->product), $input);
    }

    /** @return array<string, ?FieldSourceInfo> */
    private function sources(): array
    {
        $out = [];
        foreach ($this->app->make(ProductMasterDataQuery::class)->snapshot($this->product->fresh()) as $row) {
            $out[$row->field->value] = $row->source;
        }

        return $out;
    }

    public function test_manual_correction_writes_the_real_value_audits_it_and_marks_the_source_manual(): void
    {
        $this->correct(['price' => '12.5', 'pack_size' => '6', 'name' => ''])
            ->assertRedirect(route('purchasing.product-master-data.show', ['product_id' => $this->product->id]))
            ->assertSessionHas('success');

        $this->assertSame(12.5, CentralInventoryPrice::latestFor($this->product->id));
        $this->assertSame(6, (int) $this->product->fresh()->pack_size);
        $this->assertSame('بنادول', $this->product->fresh()->name); // فارغ = بلا تغيير

        $audits = AuditLog::where('action', AuditAction::ProductMasterFieldCorrected)->get();
        $this->assertCount(2, $audits);
        $priceAudit = $audits->firstWhere('context.field', 'price');
        $this->assertSame([10.0, 12.5], [(float) $priceAudit->old_values['price'], (float) $priceAudit->new_values['price']]);
        $this->assertSame($this->user->id, $priceAudit->user_id);

        $sources = $this->sources();
        foreach (['price', 'pack_size'] as $field) {
            $this->assertSame(FieldSourceInfo::MANUAL, $sources[$field]->kind, $field);
        }
        $this->assertNull($sources['name']); // لا سجل قبل أي رفع/تصحيح
    }

    public function test_submitting_the_stored_value_again_writes_and_audits_nothing(): void
    {
        $this->correct(['price' => '10', 'pack_size' => '10'])->assertSessionHas('info');

        $this->assertSame(0, AuditLog::count());
        $this->assertSame(1, CentralInventoryPrice::where('product_id', $this->product->id)->count());
        $this->assertSame(0, ProductFieldSource::count());
    }

    public function test_a_later_upload_with_values_overwrites_manual_edits_but_blank_cells_leave_them(): void
    {
        $this->correct(['price' => '12.5', 'pack_size' => '6', 'company' => 'شركة مصحَّحة']);
        $this->travel(5)->seconds();

        // ملف الأصناف: سعر محدَّد، حجم العبوة والشركة فارغان.
        $log = $this->uploadMaster(['111', null, null, 14, null, null]);

        $this->assertSame(14.0, CentralInventoryPrice::latestFor($this->product->id));       // الرفع كتب فوق اليدوي
        $this->assertSame(6, (int) $this->product->fresh()->pack_size);                     // الفارغ لم يمس اليدوي
        $this->assertSame('شركة مصحَّحة', $this->product->fresh()->company);

        $sources = $this->sources();
        $this->assertSame(FieldSourceInfo::UPLOAD, $sources['price']->kind);
        $this->assertSame($log->id, $sources['price']->importLog->id);
        $this->assertSame(FieldSourceInfo::MANUAL, $sources['pack_size']->kind);
        $this->assertSame(FieldSourceInfo::MANUAL, $sources['company']->kind);

        // رفع لاحق يحدّد حجم العبوة ⇒ يكتب فوق اليدوي كالمعتاد.
        $this->travel(5)->seconds();
        $this->uploadMaster(['111', null, null, null, 12, null]);
        $this->assertSame(12, (int) $this->product->fresh()->pack_size);
        $this->assertSame(14.0, CentralInventoryPrice::latestFor($this->product->id)); // السعر الفارغ هذه المرة لم يُمس
        $this->assertSame(FieldSourceInfo::UPLOAD, $this->sources()['pack_size']->kind);
    }

    public function test_blank_master_cells_never_zero_or_clear_values_from_a_previous_upload(): void
    {
        $this->uploadMaster(['111', 'بنادول إكسترا', 'شركة ب', 11, 8, 3]);
        $this->travel(5)->seconds();
        $this->uploadMaster(['111', null, null, null, null, null]); // صف بلا أي قيمة

        $p = $this->product->fresh();
        $this->assertSame(['بنادول إكسترا', 'شركة ب', 8, 3], [$p->name, $p->company, (int) $p->pack_size, (int) $p->min_reorder_gap]);
        $this->assertSame(11.0, CentralInventoryPrice::latestFor($p->id));
    }

    public function test_central_quantity_is_read_only_and_sourced_from_the_latest_matrix_upload(): void
    {
        $matrix = ImportLog::create(['file_type' => ImportFileType::WarehouseMatrix, 'original_filename' => 'كشف.xlsx', 'status' => ImportLogStatus::Succeeded, 'started_at' => now(), 'finished_at' => now()]);
        $this->assertSame($matrix->id, $this->sources()['central_quantity']->importLog->id);

        // أي محاولة تعديل تُرفض صراحة — حتى مع حقل آخر صالح بنفس الطلب (لا يُطبَّق شيء).
        $this->correct(['central_quantity' => '33', 'pack_size' => '6'])->assertSessionHasErrors('central_quantity');
        $this->assertSame(40, (int) CentralInventory::where('product_id', $this->product->id)->value('quantity'));
        $this->assertSame(10, (int) $this->product->fresh()->pack_size);
        $this->assertSame(0, AuditLog::count());

        $this->get(route('purchasing.product-master-data.show', ['product_id' => $this->product->id]))
            ->assertOk()
            ->assertDontSee('name="central_quantity"', false)
            ->assertSee('للعرض فقط');

        $this->travel(5)->seconds();
        $next = ImportLog::create(['file_type' => ImportFileType::WarehouseMatrix, 'original_filename' => 'كشف-2.xlsx', 'status' => ImportLogStatus::Succeeded, 'started_at' => now(), 'finished_at' => now()]);
        $this->assertSame($next->id, $this->sources()['central_quantity']->importLog->id);
    }

    public function test_master_upload_skips_and_flags_a_row_with_zero_pack_size_without_failing_the_file(): void
    {
        $other = Product::create(['barcode' => '222', 'name' => 'سيتامول', 'company' => 'شركة أ', 'pack_size' => 4]);
        $path = $this->tempFiles[] = sys_get_temp_dir().'/pm-'.bin2hex(random_bytes(6)).'.xlsx';
        $writer = new XlsxWriter();
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(self::HEADER));
        $writer->addRow(Row::fromValues(['111', 'اسم جديد', null, 99, 0, null])); // صف 2: عبوة صفر
        $writer->addRow(Row::fromValues(['222', null, null, null, 12, null]));     // صف 3: سليم
        $writer->close();

        $outcome = $this->app->make(MaterialMasterImporter::class)->import($path);

        // الصف المعطوب لم يُطبَّق منه شيء (لا الاسم ولا السعر)، والسليم طُبِّق.
        $this->assertSame(['بنادول', 10], [$this->product->fresh()->name, (int) $this->product->fresh()->pack_size]);
        $this->assertSame(10.0, CentralInventoryPrice::latestFor($this->product->id));
        $this->assertSame(12, (int) $other->fresh()->pack_size);

        $summary = implode(' ', $outcome->summaryLines());
        $this->assertStringContainsString('حجم عبوة صفر', $summary);
        // رقم الصف بتفاصيل التحذير (تُحفظ بسجل الرفع وتُعرض بشاشته) — نفس نمط بقية التحذيرات.
        $this->assertSame(2, $outcome->diagnostics->toArray()['samples']['skipped_row'][0]['row']);
    }

    public function test_a_price_written_by_another_file_is_not_attributed_to_the_last_master_upload(): void
    {
        $this->uploadMaster(['111', null, null, 11, null, null]);
        $this->travel(5)->seconds();
        $this->app->make(CentralInventoryPriceRecorderService::class)->recordChanges([$this->product->id => 13.0]); // ملف الجرد المركزي مثلاً

        $source = $this->sources()['price'];
        $this->assertSame(FieldSourceInfo::OTHER, $source->kind);
    }

    public function test_screen_renders_with_sources_and_rejects_invalid_values(): void
    {
        $log = $this->uploadMaster(['111', null, null, 11, null, null]);
        $this->correct(['pack_size' => '6']);

        $this->get(route('purchasing.product-master-data.show'))->assertOk()->assertSee('اختر مادة');
        $this->get(route('purchasing.product-master-data.show', ['product_id' => $this->product->id]))
            ->assertOk()
            ->assertSee('أصناف.xlsx')
            ->assertSee(route('import-history.show', $log->id), false)
            ->assertSee('تصحيح يدوي')
            ->assertSee(ProductMasterField::PackSize->label());

        $this->correct(['pack_size' => '0', 'price' => '-1'])->assertSessionHasErrors(['pack_size', 'price']);
        $this->assertSame(6, (int) $this->product->fresh()->pack_size);
    }

    public function test_screen_requires_purchasing_access(): void
    {
        $role = Role::create(['key' => 'ops_only', 'name' => 'عمليات', 'can_view_transfers_operations' => true]);
        $other = User::create(['name' => 'عمليات', 'email' => 'ops@test.local', 'password' => 'secret123', 'role_id' => $role->id, 'is_active' => true]);

        $this->actingAs($other)->put(route('purchasing.product-master-data.update', $this->product), ['pack_size' => '3'])->assertForbidden();
        $this->assertSame(10, (int) $this->product->fresh()->pack_size);
    }
}
