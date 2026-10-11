<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory\Import;

use App\Models\Pharmacy;
use App\Models\PharmacyMapping;
use App\Services\Inventory\PharmacyMappingRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PharmacyMappingRecorderTest extends TestCase
{
    use RefreshDatabase;

    private function recorder(): PharmacyMappingRecorder
    {
        return $this->app->make(PharmacyMappingRecorder::class);
    }

    public function test_new_creates_the_pharmacy_and_a_permanent_mapping(): void
    {
        $saved = $this->recorder()->record([['excel_name' => 'صيدلية جديدة', 'mapped_id' => 'NEW']]);

        $this->assertSame(1, $saved);
        $pharmacy = Pharmacy::where('name', 'صيدلية جديدة')->first();
        $this->assertNotNull($pharmacy);
        $this->assertSame($pharmacy->id, PharmacyMapping::where('original_name', 'صيدلية جديدة')->value('pharmacy_id'));
    }

    public function test_link_maps_the_name_to_an_existing_pharmacy(): void
    {
        $existing = Pharmacy::create(['name' => 'صيدلية أ']);

        $this->recorder()->record([['excel_name' => 'اسم آخر', 'mapped_id' => (string) $existing->id]]);

        $this->assertSame($existing->id, PharmacyMapping::where('original_name', 'اسم آخر')->value('pharmacy_id'));
        $this->assertSame(1, Pharmacy::count());   // لم تُنشأ صيدلية جديدة
    }

    public function test_undecided_rows_are_skipped_never_guessed(): void
    {
        $saved = $this->recorder()->record([
            ['excel_name' => 'أ', 'mapped_id' => null],
            ['excel_name' => 'ب', 'mapped_id' => ''],
            ['excel_name' => '', 'mapped_id' => 'NEW'],
        ]);

        $this->assertSame(0, $saved);
        $this->assertSame(0, Pharmacy::count());
        $this->assertSame(0, PharmacyMapping::count());
    }

    public function test_new_for_an_already_registered_name_reuses_it_and_recording_twice_is_idempotent(): void
    {
        $existing = Pharmacy::create(['name' => 'صيدلية أ']);

        $this->recorder()->record([['excel_name' => 'صيدلية أ', 'mapped_id' => 'NEW']]);
        $this->recorder()->record([['excel_name' => 'صيدلية أ', 'mapped_id' => 'NEW']]);

        $this->assertSame(1, Pharmacy::count());
        $this->assertSame(1, PharmacyMapping::count());
        $this->assertSame($existing->id, PharmacyMapping::first()->pharmacy_id);
    }
}
