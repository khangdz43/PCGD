<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

final class HouseholdImportChunkReadFilter implements IReadFilter
{
    public function __construct(
        private readonly int $startRow,
        private readonly int $endRow,
    ) {}

    /**
     * @param string $columnAddress
     * @param int $row
     * @param string $worksheetName
     */
    public function readCell($columnAddress, $row, $worksheetName = ''): bool
    {
        return $row === 2 || ($row >= $this->startRow && $row <= $this->endRow);
    }
}
