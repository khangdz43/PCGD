<?php

namespace Tests\Unit\Support;

use App\Support\HouseholdImportChunkReadFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HouseholdImportChunkReadFilterTest extends TestCase
{
    #[DataProvider('cellReadCases')]
    public function test_it_reads_the_header_and_only_rows_inside_the_chunk(
        int $startRow,
        int $endRow,
        int $row,
        bool $expected,
    ): void {
        $filter = new HouseholdImportChunkReadFilter($startRow, $endRow);

        self::assertSame($expected, $filter->readCell('A', $row));
    }

    public static function cellReadCases(): array
    {
        return [
            'header row is always included' => [5, 12, 2, true],
            'row before chunk is excluded' => [5, 12, 4, false],
            'first chunk row is included' => [5, 12, 5, true],
            'middle chunk row is included' => [5, 12, 8, true],
            'last chunk row is included' => [5, 12, 12, true],
            'row after chunk is excluded' => [5, 12, 13, false],
            'second chunk still includes header' => [13, 20, 2, true],
        ];
    }
}
