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
            $extension = strtolower(pathinfo($storedPath, PATHINFO_EXTENSION));
            $context = $importLog->context ?? [];
            if (in_array($extension, ['xlsx', 'xlsm'], true)) {
                $path = Storage::disk('local')->path($storedPath);
                if ($extension === 'xlsx' && $this->hasMacros($path)) {
                    $macroPath = preg_replace('/\.xlsx$/i', '.xlsm', $storedPath);
                    if (! is_string($macroPath) || ! Storage::disk('local')->move($storedPath, $macroPath)) {
                        throw new \RuntimeException('Không thể đặt đúng định dạng macro cho file Excel lỗi.');
                    }
                    $storedPath = $macroPath;
                    $path = Storage::disk('local')->path($storedPath);
                    $importLog->update(['error_file_path' => $storedPath]);
                }

                if (! ($context['error_file_view_options_checked'] ?? false)) {
                    $this->ensureErrorWorkbookView($path);
                    $context['error_file_view_options_checked'] = true;
                    $context['error_file_active_sheet_checked'] = true;
                    $importLog->update(['context' => $context]);
                }

                return $storedPath;
            }

            $spreadsheet = null;
            try {
                $path = Storage::disk('local')->path($storedPath);
                $spreadsheet = IOFactory::load($path);
                $sheet = $spreadsheet->getActiveSheet();
                $targetPath = dirname($storedPath) . '/error-' . pathinfo($importLog->file_path, PATHINFO_FILENAME) . '.xlsx';
                $sheet->setSheetState('visible');
                $sheet->setSelectedCell('A1');
                $sheet->setTopLeftCell('A1');
                $spreadsheet->setShowHorizontalScroll(true);
                $spreadsheet->setShowVerticalScroll(true);
                IOFactory::createWriter($spreadsheet, 'Xlsx')
                    ->save(Storage::disk('local')->path($targetPath));
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

            $context['error_file_view_options_checked'] = true;
            $importLog->update([
                'context' => $context,
                'error_file_path' => $errorFilePath,
            ]);

            if (! Storage::disk('local')->delete($storedPath)) {
                Log::warning('Không thể xóa bản XLS cũ sau khi chuẩn hóa file lỗi.', [
                    'import_log_id' => $importLog->id,
                    'path' => $storedPath,
                ]);
            }

            return $errorFilePath;
        }

        $errorFilePath = dirname($importLog->file_path) . '/error-' . pathinfo($importLog->file_path, PATHINFO_FILENAME) . '.xlsx';
        $spreadsheet = new Spreadsheet;
        try {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Ket qua loi');
            $sheet->setCellValue('A1', 'Không thể tạo file lỗi từ tệp gốc.');
            $sheet->setCellValue(
                'A2',
                $importLog->context['processing_error']
                    ?? 'Vui lòng kiểm tra định dạng và cấu trúc file Excel, sau đó tải lại tệp.',
            );
            $sheet->setCellValue('A3', 'Vui lòng tải biểu mẫu mới nhất và giữ nguyên cấu trúc cột trước khi nhập lại.');
            $sheet->setCellValue('A4', 'Tên tệp');
            $sheet->setCellValue('B4', $importLog->file_name);
            $sheet->setCellValue('A5', 'Thời gian nhập');
            $sheet->setCellValue('B5', $importLog->created_at?->format('d/m/Y H:i:s'));
            $sheet->getStyle('A1:A3')->getFont()->getColor()->setARGB('FFDC3545');
            $sheet->getColumnDimension('A')->setWidth(42);
            $sheet->getColumnDimension('B')->setWidth(64);
            $spreadsheet->setShowHorizontalScroll(true);
            $spreadsheet->setShowVerticalScroll(true);

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
        $context['error_file_view_options_checked'] = true;
        $importLog->update([
            'error_file_path' => $errorFilePath,
            'context' => $context,
        ]);

        return $errorFilePath;
    }

    private function hasMacros(string $path): bool
    {
        $archive = new \ZipArchive;
        if ($archive->open($path) !== true) {
            throw new \RuntimeException('Không thể mở file Excel lỗi để kiểm tra macro.');
        }

        try {
            return $archive->locateName('xl/vbaProject.bin') !== false;
        } finally {
            if (! $archive->close()) {
                throw new \RuntimeException('Không thể hoàn tất kiểm tra macro của file Excel lỗi.');
            }
        }
    }

    private function ensureErrorWorkbookView(string $path): void
    {
        $archive = new \ZipArchive;
        if ($archive->open($path) !== true) {
            throw new \RuntimeException('Không thể mở file Excel lỗi để kiểm tra chế độ xem.');
        }

        try {
            $workbookIndex = $archive->locateName('xl/workbook.xml');
            if ($workbookIndex === false) {
                throw new \RuntimeException('File Excel lỗi không có thông tin trang tính hợp lệ.');
            }

            $workbookXml = $archive->getFromIndex($workbookIndex);
            $document = new \DOMDocument;
            if ($workbookXml === false || ! $document->loadXML($workbookXml, LIBXML_NONET)) {
                throw new \RuntimeException('Không thể đọc thông tin trang tính trong file Excel lỗi.');
            }

            $xpath = new \DOMXPath($document);
            $xpath->registerNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $sheets = $xpath->query('/main:workbook/main:sheets/main:sheet');
            if ($sheets === false || $sheets->length === 0) {
                throw new \RuntimeException('File Excel lỗi không có trang tính.');
            }

            $activeTab = $xpath->query('/main:workbook/main:bookViews/main:workbookView/@activeTab');
            $activeIndex = $activeTab !== false && $activeTab->length > 0
                ? (int) $activeTab->item(0)->nodeValue
                : 0;
            if ($activeIndex < 0 || $activeIndex >= $sheets->length) {
                throw new \RuntimeException('File Excel lỗi có trang tính đang chọn không hợp lệ.');
            }

            $activeSheet = $sheets->item($activeIndex);
            $needsSave = false;
            if ($activeSheet->getAttribute('state') !== '' && $activeSheet->getAttribute('state') !== 'visible') {
                $activeSheet->setAttribute('state', 'visible');
                $needsSave = true;
            }

            $workbookViews = $xpath->query('/main:workbook/main:bookViews/main:workbookView');
            if ($workbookViews !== false && $workbookViews->length > 0) {
                $workbookView = $workbookViews->item(0);
                foreach (['showHorizontalScroll', 'showVerticalScroll', 'showSheetTabs'] as $attribute) {
                    if ($workbookView->getAttribute($attribute) !== 'true') {
                        $workbookView->setAttribute($attribute, 'true');
                        $needsSave = true;
                    }
                }
            }

            if ($needsSave) {
                $updatedWorkbookXml = $document->saveXML();
                if ($updatedWorkbookXml === false || ! $archive->addFromString('xl/workbook.xml', $updatedWorkbookXml)) {
                    throw new \RuntimeException('Không thể cập nhật chế độ xem file Excel lỗi.');
                }
            }
        } finally {
            if (! $archive->close()) {
                throw new \RuntimeException('Không thể hoàn tất cập nhật metadata file XLSX.');
            }
        }
    }
}
