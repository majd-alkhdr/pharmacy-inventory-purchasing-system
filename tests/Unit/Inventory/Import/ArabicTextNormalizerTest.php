<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Import;

use App\Services\Inventory\Import\Support\ArabicTextNormalizer;
use PHPUnit\Framework\TestCase;

final class ArabicTextNormalizerTest extends TestCase
{
    public function test_erp_and_matrix_spellings_of_the_same_pharmacy_match(): void
    {
        // الحالة الحقيقية: كشف الـ ERP كتبها بـ "/" وملف المصفوفة بـ "-".
        $erp    = ArabicTextNormalizer::key('صيدلية ماهر سليمان / جرمانا');
        $matrix = ArabicTextNormalizer::key('صيدلية ماهر سليمان- جرمانا');
        $spaced = ArabicTextNormalizer::key('صيدلية ماهر سليمان - جرمانا');

        $this->assertSame($erp, $matrix);
        $this->assertSame($erp, $spaced);
    }

    public function test_exact_key_shape_is_locked(): void
    {
        // يثبّت السلوك حرفياً: ة→ه، فواتح الفواصل موحَّدة، مسافات ج عرطوز محفوظة.
        $this->assertSame('صيدليه سهاد-ضاحيه قدسيا', ArabicTextNormalizer::key('صيدلية سهاد - ضاحية قدسيا'));
        $this->assertSame('صيدليه ياسين الحجه-ج عرطوز', ArabicTextNormalizer::key('صيدلية ياسين الحجة-ج عرطوز'));
    }

    public function test_alef_hamza_and_ta_marbuta_variants_match(): void
    {
        $this->assertSame(
            ArabicTextNormalizer::key('صيدلية احلام زكريا'),
            ArabicTextNormalizer::key('صيدلية أحلام زكريا'),
        );
        $this->assertSame(ArabicTextNormalizer::key('صيدلية'), ArabicTextNormalizer::key('صيدليه'));
    }

    public function test_invisible_marks_tabs_and_nbsp_are_neutralised(): void
    {
        $this->assertSame('الاسم', ArabicTextNormalizer::key("\u{200F}الاسم"));      // علامة RTL
        $this->assertSame('كريم 350', ArabicTextNormalizer::key("كريم\t350"));       // Tab
        $this->assertSame('a b', ArabicTextNormalizer::key("a\u{00A0}b"));           // NBSP
        $this->assertSame('a b', ArabicTextNormalizer::key("  a   b  "));            // تكرار مسافات
    }

    public function test_diacritics_and_tatweel_are_removed(): void
    {
        $this->assertSame(
            ArabicTextNormalizer::key('صيدلية'),
            ArabicTextNormalizer::key('صَيْدَليــة'),
        );
    }

    public function test_arabic_indic_digits_become_latin(): void
    {
        $this->assertSame(ArabicTextNormalizer::key('فرع 3'), ArabicTextNormalizer::key('فرع ٣'));
    }

    public function test_latin_letters_are_lowercased(): void
    {
        $this->assertSame('total', ArabicTextNormalizer::key('TOTAL'));
    }

    public function test_different_pharmacies_keep_different_keys(): void
    {
        $this->assertNotSame(
            ArabicTextNormalizer::key('صيدلية هناء بطحيش-كفرسوسة'),
            ArabicTextNormalizer::key('صيدلية هناء يونس - المزة'),
        );
    }

    public function test_key_is_idempotent(): void
    {
        $once = ArabicTextNormalizer::key("\u{200F}صيدلية  أحلام / حرستا");

        $this->assertSame($once, ArabicTextNormalizer::key($once));
    }

    public function test_invalid_utf8_is_repaired_instead_of_crashing(): void
    {
        $key = ArabicTextNormalizer::key("a\xFFb");

        $this->assertTrue(mb_check_encoding($key, 'UTF-8'));
        $this->assertStringStartsWith('a', $key);
        $this->assertStringEndsWith('b', $key);
    }
}
