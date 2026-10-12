<?php

namespace App\Http\Controllers\Households;

use App\Http\Controllers\Controller;
use App\Models\ImportLog;
use App\Services\Households\HouseholdImportHistoryService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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

    public function downloadErrors(
        Request $request,
        ImportLog $importLog,
        HouseholdImportHistoryService $historyService,
    ): StreamedResponse {
        abort_unless($importLog->user_id === $request->user()->id, 404);
        abort_unless($importLog->status === 'FAILED' && $importLog->file_path !== null, 404);

        $errorFilePath = $historyService->errorFilePath($importLog);

        $extension = pathinfo($importLog->file_name, PATHINFO_EXTENSION);
        $fileName = pathinfo($importLog->file_name, PATHINFO_FILENAME).'-loi'.($extension !== '' ? '.'.$extension : '');

        return Storage::disk('local')->download($errorFilePath, $fileName);
    }

    public function destroyImportedRecords(
        Request $request,
        ImportLog $importLog,
        HouseholdImportHistoryService $historyService,
    ): JsonResponse {
        abort_unless($importLog->user_id === $request->user()->id, 404);
        abort_if(in_array($importLog->status, ['PENDING', 'PROCESSING'], true), 409, 'Không thể xóa file đang xử lý.');
        abort_unless($importLog->status === 'SUCCESS', 409, 'Chỉ có thể xóa phiếu của lần nhập thành công.');

        $deleted = $historyService->deleteImportedRecords($importLog);

        return response()->json([
            'message' => "Đã xóa {$deleted['households']} phiếu và {$deleted['persons']} nhân khẩu đã nhập từ file. Lịch sử tải lên vẫn được giữ.",
            'status' => 'ROLLED_BACK',
            'deleted_households' => $deleted['households'],
            'deleted_persons' => $deleted['persons'],
        ]);
    }
}
