<?php

namespace App\Services\Households;

use App\Models\Household;
use App\Models\ImportLog;
use App\Models\Person;
use App\Models\PersonDisability;
use App\Models\PersonEducation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

class HouseholdImportHistoryService
{
    public function errorFilePath(ImportLog $importLog): string
    {
        abort_unless($importLog->file_path !== null && Storage::disk('local')->exists($importLog->file_path), 404);

        if ($importLog->error_file_path !== null && Storage::disk('local')->exists($importLog->error_file_path)) {
            return $importLog->error_file_path;
        }

        $sourcePath = Storage::disk('local')->path($importLog->file_path);
        $errorFilePath = dirname($importLog->file_path).'/error-'.basename($importLog->file_path);
        $spreadsheet = IOFactory::load($sourcePath);

        $sheet = null;
        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            if ($worksheet->getSheetState() !== 'visible') {
                continue;
            }
            $highestColumn = Coordinate::columnIndexFromString($worksheet->getHighestDataColumn());
            if ($highestColumn >= 15 && $worksheet->getHighestDataRow() >= 5) {
                $sheet = $worksheet;
                break;
            }
        }
        $sheet ??= $spreadsheet->getActiveSheet();

        $messagesByCell = [];
        foreach ($importLog->error_details ?? [] as $error) {
            $message = is_array($error) ? (string) ($error['message'] ?? '') : (string) $error;
            $cell = is_array($error) ? ($error['cell'] ?? null) : null;
            if ($cell === null && is_array($error) && isset($error['row'])) {
                $cell = 'A'.$error['row'];
            }
            if ($cell === null && preg_match('/^Dòng\s+(\d+):\s*(.*)$/u', $message, $matches)) {
                $cell = 'A'.$matches[1];
                $message = $matches[2];
            }
            $cell ??= 'A1';
            if (! preg_match('/^A\d+$/', $cell)) {
                $cell = 'A1';
            }
            $messagesByCell[$cell][] = $message;
        }

        foreach ($messagesByCell as $cell => $messages) {
            $sheet->setCellValue($cell, implode(' | ', array_unique($messages)));
            $sheet->getStyle($cell)->getFont()->getColor()->setARGB('FFDC3545');
            $sheet->getStyle($cell)->getAlignment()->setWrapText(true);
        }
        $sheet->getColumnDimension('A')->setWidth(45);

        try {
            $writerType = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION)) === 'xls' ? 'Xls' : 'Xlsx';
            IOFactory::createWriter($spreadsheet, $writerType)
                ->save(Storage::disk('local')->path($errorFilePath));
        } catch (Throwable $exception) {
            Log::error('Không thể tạo file Excel lỗi từ lịch sử tải lên.', [
                'import_log_id' => $importLog->id,
                'exception' => $exception,
            ]);
            throw $exception;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        $importLog->update(['error_file_path' => $errorFilePath]);

        return $errorFilePath;
    }

    public function deleteImportedRecords(ImportLog $importLog): array
    {
        return DB::transaction(function () use ($importLog): array {
            $householdIds = Household::query()
                ->where('import_log_id', $importLog->id)
                ->lockForUpdate()
                ->pluck('id');
            $personIds = Person::query()
                ->where('import_log_id', $importLog->id)
                ->orWhereIn('household_id', $householdIds)
                ->lockForUpdate()
                ->pluck('id');

            $personCount = $personIds->count();
            PersonDisability::query()->whereIn('person_id', $personIds)->delete();
            PersonEducation::query()
                ->where('import_log_id', $importLog->id)
                ->orWhereIn('person_id', $personIds)
                ->delete();
            Person::query()->whereIn('id', $personIds)->delete();
            $householdCount = Household::query()
                ->where('import_log_id', $importLog->id)
                ->delete();

            $context = $importLog->context ?? [];
            $context['deleted_households'] = $householdCount;
            $context['deleted_persons'] = $personCount;
            $importLog->update([
                'status' => 'ROLLED_BACK',
                'context' => $context,
            ]);

            return [
                'households' => $householdCount,
                'persons' => $personCount,
            ];
        });
    }
}
