<?php

namespace App\Http\Controllers\Households;

use App\Http\Controllers\Controller;
use App\Models\Household;
use App\Models\ImportLog;
use App\Models\Person;
use App\Models\PersonDisability;
use App\Models\PersonEducation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HouseholdImportHistoryController extends Controller
{
    public function __invoke(Request $request): View
    {
        $imports = ImportLog::query()
            ->where('user_id', $request->user()->id)
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('households.import-history', compact('imports'));
    }

    public function download(Request $request, ImportLog $importLog): StreamedResponse
    {
        abort_unless($importLog->user_id === $request->user()->id, 404);
        abort_if(in_array($importLog->status, ['PENDING', 'PROCESSING'], true), 409, 'File đang được xử lý.');
        abort_unless($importLog->file_path !== null && Storage::disk('local')->exists($importLog->file_path), 404);

        return Storage::disk('local')->download($importLog->file_path, $importLog->file_name);
    }

    public function downloadErrors(Request $request, ImportLog $importLog): StreamedResponse
    {
        abort_unless($importLog->user_id === $request->user()->id, 404);
        abort_unless($importLog->status === 'FAILED' && $importLog->file_path !== null, 404);

        if ($importLog->error_file_path === null || ! Storage::disk('local')->exists($importLog->error_file_path)) {
            $this->createErrorFile($importLog);
        }

        $extension = pathinfo($importLog->file_name, PATHINFO_EXTENSION);
        $fileName = pathinfo($importLog->file_name, PATHINFO_FILENAME).'-loi'.($extension !== '' ? '.'.$extension : '');

        return Storage::disk('local')->download($importLog->error_file_path, $fileName);
    }

    private function createErrorFile(ImportLog $importLog): void
    {
        abort_unless(Storage::disk('local')->exists($importLog->file_path), 404);

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
        } catch (\Throwable $exception) {
            Log::error('Không thể tạo file Excel lỗi từ lịch sử tải lên.', [
                'import_log_id' => $importLog->id,
                'exception' => $exception,
            ]);
            throw $exception;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        $importLog->update(['error_file_path' => $errorFilePath]);
    }

    public function destroyImportedRecords(Request $request, ImportLog $importLog): JsonResponse
    {
        abort_unless($importLog->user_id === $request->user()->id, 404);
        abort_if(in_array($importLog->status, ['PENDING', 'PROCESSING'], true), 409, 'Không thể xóa file đang xử lý.');
        abort_unless($importLog->status === 'SUCCESS', 409, 'Chỉ có thể xóa phiếu của lần nhập thành công.');

        $deleted = DB::transaction(function () use ($importLog): array {
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

        return response()->json([
            'message' => "Đã xóa {$deleted['households']} phiếu và {$deleted['persons']} nhân khẩu đã nhập từ file. Lịch sử tải lên vẫn được giữ.",
            'status' => 'ROLLED_BACK',
            'deleted_households' => $deleted['households'],
            'deleted_persons' => $deleted['persons'],
        ]);
    }
}
