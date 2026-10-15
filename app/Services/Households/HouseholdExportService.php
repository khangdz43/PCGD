<?php

namespace App\Services\Households;

use App\Models\Household;
use App\Models\Person;
use App\Models\User;
use App\Support\DataAccessScope;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HouseholdExportService
{
    public function export(User $user, array $filters, array $queryFilters): StreamedResponse
    {
        $path = base_path('file-mau.XLS');
        abort_unless(is_file($path), 404, 'Không tìm thấy file mẫu Excel.');

        $spreadsheet = IOFactory::createReaderForFile($path)->load($path);
        $sheet = $this->dataSheet($spreadsheet);
        $query = Household::with([
            'province',
            'commune',
            'village',
            'persons.ethnicity',
            'persons.disability',
            'persons.educations',
        ]);
        DataAccessScope::households($query, $user);

        if (filled($queryFilters['q'] ?? null)) {
            $search = trim((string) $queryFilters['q']);
            $query->where(fn ($builder) => $builder
                ->where('household_code', 'like', "%{$search}%")
                ->orWhere('head_first_name', 'like', "%{$search}%")
                ->orWhere('head_last_name', 'like', "%{$search}%"));
        }
        if (filled($filters['school_code'] ?? null)) {
            $query->whereHas('persons.educations', fn ($builder) => $builder
                ->where('school_code', $filters['school_code']));
        }
        if (filled($filters['school_year'] ?? null) && $filters['school_year'] !== 'all') {
            $query->where('school_year', $filters['school_year']);
        }
        foreach (['province_code', 'commune_code', 'village_code'] as $field) {
            if (filled($queryFilters[$field] ?? null)) {
                $query->where($field, $queryFilters[$field]);
            }
        }
        if (! empty($filters['household_ids'])) {
            $query->whereIn('id', $filters['household_ids']);
        }

        $sequence = 1;
        $row = 5;
        $templateLastRow = $sheet->getHighestRow();
        foreach ($query->orderBy('household_code')->get() as $household) {
            foreach ($household->persons as $person) {
                $this->writeExportRow($sheet, $row++, $sequence++, $household, $person);
            }
        }

        $lastExportRow = $row - 1;
        if ($lastExportRow > $templateLastRow) {
            $firstOverflowRow = $templateLastRow + 1;
            $lastColumnIndex = Coordinate::columnIndexFromString('AY');
            for ($columnIndex = 1; $columnIndex <= $lastColumnIndex; $columnIndex++) {
                $column = Coordinate::stringFromColumnIndex($columnIndex);
                $sheet->duplicateStyle(
                    $sheet->getStyle($column.'5'),
                    $column.$firstOverflowRow.':'.$column.$lastExportRow
                );
            }
            $rowHeight = $sheet->getRowDimension(5)->getRowHeight();
            for ($overflowRow = $firstOverflowRow; $overflowRow <= $lastExportRow; $overflowRow++) {
                $sheet->getRowDimension($overflowRow)->setRowHeight($rowHeight);
            }
        }

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xls($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, 'phieu-dieu-tra-'.now()->format('Ymd-His').'.xls', [
            'Content-Type' => 'application/vnd.ms-excel',
        ]);
    }

    private function writeExportRow(Worksheet $sheet, int $row, int $sequence, object $household, ?Person $person): void
    {
        $education = $person?->educations->sortByDesc('school_year')->first();
        $disability = $person?->disability;
        $date = $person?->dob ? strtotime((string) $person->dob) : false;
        $values = [
            'B' => $sequence,
            'C' => $person?->last_name ?? $household->head_last_name,
            'D' => $person?->first_name ?? $household->head_first_name,
            'E' => $date ? date('j', $date) : null,
            'F' => $date ? date('n', $date) : null,
            'G' => $date ? date('Y', $date) : null,
            'H' => $person?->gender === 'NU' ? 'X' : null,
            'I' => $person?->ethnicity?->name,
            'J' => $person?->religion,
            'K' => $person?->priority_type,
            'L' => $household->head_last_name,
            'M' => $household->head_first_name,
            'N' => $household->address ?: $household->village?->name,
            'O' => $household->household_code,
            'P' => match ($household->residence_type) {
                'TAM_TRU' => 'Tạm trú',
                'KHAC' => 'Khác',
                default => 'Thường trú',
            },
            'Q' => $household->residence_status,
            'R' => $education?->academic_block,
            'S' => $education?->current_class,
            'V' => $education?->school_code,
            'W' => $education?->graduation_level,
            'X' => $education?->is_complementary ? 'X' : null,
            'Y' => $education?->graduation_year,
            'Z' => $education?->vocational_grad_level,
            'AB' => $education?->vocational_grad_year,
            'AC' => $education?->finished_class,
            'AD' => $education?->finished_year,
            'AE' => $education?->dropped_class,
            'AF' => $education?->dropped_year,
            'AG' => $education?->literacy_current_class,
            'AH' => $education?->literacy_completed_class,
            'AI' => $education?->literacy_relapse_level,
            'AJ' => $disability?->mobility_disability ? 'X' : null,
            'AK' => $disability?->hearing_speech_disability ? 'X' : null,
            'AL' => $disability?->visual_disability ? 'X' : null,
            'AM' => $disability?->mental_disability ? 'X' : null,
            'AN' => $disability?->intellectual_disability ? 'X' : null,
            'AO' => $disability?->learning_disability ? 'X' : null,
            'AP' => $disability?->autism ? 'X' : null,
            'AQ' => $disability?->other_disability ? 'X' : null,
            'AR' => $disability?->has_disability_cert ? 'X' : null,
            'AS' => $disability?->can_study ? 'X' : null,
            'AT' => $disability?->special_circumstance,
            'AU' => $disability?->special_circumstance_detail,
            'AV' => $person?->relationship_with_head ?? 'Chủ hộ',
            'AW' => $person?->parent_name,
            'AX' => $person?->phone,
            'AY' => $person?->note,
        ];

        foreach ($values as $column => $value) {
            if ($value !== null && $value !== '') {
                $sheet->setCellValueExplicit($column.$row, (string) $value, DataType::TYPE_STRING);
            }
        }
    }

    private function dataSheet(Spreadsheet $spreadsheet): Worksheet
    {
        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            if (
                $this->normalize($this->cell($sheet, 'O2')) === 'sophieu'
                && $this->normalize($this->cell($sheet, 'AY2')) === 'ghichu'
            ) {
                return $sheet;
            }
        }

        return $spreadsheet->getActiveSheet();
    }

    private function cell(Worksheet $sheet, string $coordinate): string
    {
        return trim((string) $sheet->getCell($coordinate)->getFormattedValue());
    }

    private function normalize(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return strtolower(preg_replace('/[^a-z0-9]/i', '', $ascii === false ? $value : $ascii));
    }
}
