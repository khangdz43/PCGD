<?php

namespace App\Services\Households;

use App\Models\ImportLog;
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

        $messagesByCell = [
            'A1' => ['Không thể tạo file ghi chú lỗi tự động. Vui lòng kiểm tra nhật ký xử lý.'],
        ];

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

}
