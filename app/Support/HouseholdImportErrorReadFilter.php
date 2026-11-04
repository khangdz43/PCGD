<?php

namespace App\Support;

use App\Services\Households\HouseholdImportErrorCollector;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

final class HouseholdImportErrorReadFilter implements IReadFilter
{
    public function __construct(
        private readonly HouseholdImportErrorCollector $errors,
        private readonly string $worksheetName,
    ) {}

    public function readCell($columnAddress, $row, $worksheetName = ''): bool
    {
        return $worksheetName === $this->worksheetName
            && ($row <= 4 || $this->errors->hasErrorsForRow($row));
    }
}
