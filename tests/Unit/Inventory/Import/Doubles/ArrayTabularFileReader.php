<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Import\Doubles;

use App\Services\Inventory\Import\Contracts\TabularFileReader;
use Generator;

/**
 * قارئ وهمي يُعيد مصفوفة صفوف مباشرة — يسمح باختبار أي مستهلك للعقد بلا ملف Excel.
 * يحترم عقد الواجهة: أرقام الصفوف من 1، والصف الفارغ يُعاد (خلاياه []).
 */
final class ArrayTabularFileReader implements TabularFileReader
{
    /** @param list<array<int, string|int|float|bool|null>|null> $rows  null = صف فارغ */
    public function __construct(private readonly array $rows)
    {
    }

    public function read(string $absolutePath): Generator
    {
        return (function (): Generator {
            foreach (array_values($this->rows) as $index => $row) {
                yield $index + 1 => $row ?? [];
            }
        })();
    }
}
