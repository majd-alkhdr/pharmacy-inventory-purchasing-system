<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Import;

use App\Services\Inventory\Import\Support\MaterialNameKey;
use PHPUnit\Framework\TestCase;

final class MaterialNameKeyTest extends TestCase
{
    public function test_spaces_diacritics_tatweel_invisible_chars_and_hindi_digits_are_ignored(): void
    {
        $expected = MaterialNameKey::key('بنادول 500 ملغ');

        $this->assertSame($expected, MaterialNameKey::key("  بَنادول\u{00A0}\u{00A0}٥٠٠   ملـغ "));
        $this->assertSame($expected, MaterialNameKey::key("\u{200F}بنادول 500 ملغ\u{FEFF}"));
        $this->assertSame('panadol extra', MaterialNameKey::key('PANADOL  Extra'));
    }

    public function test_distinct_arabic_letters_and_separators_are_not_unified(): void
    {
        // عكس ArabicTextNormalizer عمداً: هذه فروق قد تفصل مادتين مختلفتين.
        $this->assertNotSame(MaterialNameKey::key('شراب السعلة'), MaterialNameKey::key('شراب السعله'));
        $this->assertNotSame(MaterialNameKey::key('أموكسيل'), MaterialNameKey::key('اموكسيل'));
        $this->assertNotSame(MaterialNameKey::key('فيتامين د-3'), MaterialNameKey::key('فيتامين د 3'));
    }
}
