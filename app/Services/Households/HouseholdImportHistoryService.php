<?php

namespace App\Services\Households;

use App\Models\ImportLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

class HouseholdImportHistoryService
{
    public function errorFilePath(ImportLog $importLog): string
    {
        abort_unless($importLog->file_path !== null && Storage::disk('local')->exists($importLog->file_path), 404);

        if ($importLog->error_file_path !== null && Storage::disk('local')->exists($importLog->error_file_path)) {
            $storedPath = $importLog->error_file_path;
            $isXlsx = strtolower(pathinfo($storedPath, PATHINFO_EXTENSION)) === 'xlsx';
            $context = $importLog->context ?? [];
            if ($isXlsx && ($context['error_file_active_sheet_checked'] ?? false)) {
                return $storedPath;
            }

            $path = Storage::disk('local')->path($storedPath);
            $spreadsheet = null;
            try {
                $spreadsheet = IOFactory::load($path);
                $sheet = $spreadsheet->getActiveSheet();
                $targetPath = $isXlsx
                    ? $storedPath
                    : dirname($storedPath) . '/error-' . pathinfo($importLog->file_path, PATHINFO_FILENAME) . '.xlsx';
                $needsRewrite = ! $isXlsx || $sheet->getSheetState() !== 'visible';
                if ($needsRewrite) {
                    $sheet->setSheetState('visible');
                    $sheet->setSelectedCell('A1');
                    $sheet->setTopLeftCell('A1');
                    IOFactory::createWriter($spreadsheet, 'Xlsx')
                        ->save(Storage::disk('local')->path($targetPath));
                }
                $errorFilePath = $targetPath;
            } catch (Throwable $exception) {
                Log::error('Không thể chuẩn hóa file Excel lỗi sang định dạng XLSX.', [
                    'import_log_id' => $importLog->id,
                    'exception' => $exception,
                ]);
                throw $exception;
            } finally {
                $spreadsheet?->disconnectWorksheets();
            }

            $context['error_file_active_sheet_checked'] = true;
            $updates = ['context' => $context];
            if ($errorFilePath !== $storedPath) {
                $updates['error_file_path'] = $errorFilePath;
            }
            $importLog->update($updates);

            if ($errorFilePath !== $storedPath) {
                if (! Storage::disk('local')->delete($storedPath)) {
                    Log::warning('Không thể xóa bản XLS cũ sau khi chuẩn hóa file lỗi.', [
                        'import_log_id' => $importLog->id,
                        'path' => $storedPath,
                    ]);
                }
            }

            return $errorFilePath;
        }

        $errorFilePath = dirname($importLog->file_path) . '/error-' . pathinfo($importLog->file_path, PATHINFO_FILENAME) . '.xlsx';
        $spreadsheet = new Spreadsheet;
        try {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Ket qua loi');
            $sheet->setCellValue('A1', 'Không thể tạo file lỗi từ tệp gốc.');
            $sheet->setCellValue('A2', 'Vui lòng kiểm tra định dạng và cấu trúc file Excel, sau đó tải lại tệp.');
            $sheet->setCellValue('A4', 'Tên tệp');
            $sheet->setCellValue('B4', $importLog->file_name);
            $sheet->setCellValue('A5', 'Thời gian nhập');
            $sheet->setCellValue('B5', $importLog->created_at?->format('d/m/Y H:i:s'));
            $sheet->getStyle('A1:A2')->getFont()->getColor()->setARGB('FFDC3545');
            $sheet->getColumnDimension('A')->setWidth(42);
            $sheet->getColumnDimension('B')->setWidth(64);

            IOFactory::createWriter($spreadsheet, 'Xlsx')
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

        $context = $importLog->context ?? [];
        $context['error_file_active_sheet_checked'] = true;
        $importLog->update(['error_file_path' => $errorFilePath]);
        $importLog->update(['context' => $context]);

        return $errorFilePath;
    }
}
