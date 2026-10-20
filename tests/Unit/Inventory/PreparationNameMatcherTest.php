<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Models\Product;
use App\Services\Inventory\Import\Support\MaterialNameKey;
use App\Services\Inventory\Reconciliation\MatchResult;
use App\Services\Inventory\Reconciliation\NameMatchKind;
use App\Services\Inventory\Reconciliation\PreparationNameMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 🎯 (ب.3) يحتاج قاعدة بيانات للمرور الثالث فقط (الكتالوج) — المروران الأولان على بنود الفاتورة. */
final class PreparationNameMatcherTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<int, string> $invoiceProductNames */
    private function matchOne(string $name, array $invoiceProductNames): MatchResult
    {
        $key = MaterialNameKey::key($name);

        return (new PreparationNameMatcher())->match([$key => ['name' => $name, 'row' => 2]], $invoiceProductNames)[$key];
    }

    public function test_exact_match_despite_spaces_diacritics_and_hindi_digits(): void
    {
        $result = $this->matchOne(' بَنادول  ٥٠٠ ', [7 => 'بنادول 500', 8 => 'أموكسيل']);

        $this->assertSame(NameMatchKind::Exact, $result->kind);
        $this->assertSame(7, $result->productId);
    }

    public function test_ta_marbuta_difference_is_approximate_and_only_within_the_invoice(): void
    {
        $result = $this->matchOne('شراب السعله', [7 => 'شراب السعلة']);
        $this->assertSame(NameMatchKind::Approximate, $result->kind);
        $this->assertSame(7, $result->productId);

        // نفس الفرق مع مادة بالكتالوج فقط (لا بالفاتورة) ⇒ لا مطابقة تقريبية.
        Product::create(['barcode' => '1', 'name' => 'مرهم الحكة', 'company' => 'ش']);
        $this->assertSame(NameMatchKind::Unknown, $this->matchOne('مرهم الحكه', [7 => 'شراب السعلة'])->kind);
    }

    public function test_a_name_shared_by_two_invoiced_products_is_ambiguous(): void
    {
        $result = $this->matchOne('بنادول', [7 => 'بنادول', 8 => 'بنادول']);

        $this->assertSame(NameMatchKind::Ambiguous, $result->kind);
        $this->assertNull($result->productId);
    }

    public function test_a_catalog_product_missing_from_the_invoice_is_not_in_document(): void
    {
        $product = Product::create(['barcode' => '1', 'name' => 'فيتامين سي', 'company' => 'ش']);

        $result = $this->matchOne('فيتامين سي', [7 => 'بنادول']);

        $this->assertSame(NameMatchKind::NotInDocument, $result->kind);
        $this->assertSame($product->id, $result->productId);
    }

    public function test_two_catalog_products_with_the_same_name_outside_the_invoice_are_ambiguous(): void
    {
        Product::create(['barcode' => '1', 'name' => 'فيتامين سي', 'company' => 'ش1']);
        Product::create(['barcode' => '2', 'name' => 'فيتامين سي', 'company' => 'ش2']);

        $this->assertSame(NameMatchKind::Ambiguous, $this->matchOne('فيتامين سي', [7 => 'بنادول'])->kind);
    }

    public function test_an_unknown_name_is_unknown(): void
    {
        $this->assertSame(NameMatchKind::Unknown, $this->matchOne('مادة لا وجود لها', [7 => 'بنادول'])->kind);
    }
}
