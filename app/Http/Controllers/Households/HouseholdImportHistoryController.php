<?php

namespace App\Http\Controllers\Households;

use App\Http\Controllers\Controller;
use App\Models\ImportLog;
use App\Services\Households\HouseholdImportHistoryService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class HouseholdImportHistoryController extends Controller
{
    public function __invoke(Request $request): View
    {
        $imports = ImportLog::query()
            ->where('user_id', $request->user()->id)
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString();
        $pendingImportIds = ImportLog::query()
            ->where('status', 'PENDING')
            ->oldest('created_at')
            ->oldest('id')
            ->pluck('id');
        $queuePositions = $pendingImportIds->values()->mapWithKeys(
            fn (int $id, int $index): array => [$id => $index + 1],
        );
        $queueTotal = $pendingImportIds->count();

        return view('households.import-history', compact('imports', 'queuePositions', 'queueTotal'));
    }

    public function download(Request $request, ImportLog $importLog): BinaryFileResponse
    {
        abort_unless($importLog->user_id === $request->user()->id, 404);
        abort_if(in_array($importLog->status, ['PENDING', 'PROCESSING'], true), 409, 'File đang được xử lý.');
        abort_unless($importLog->file_path !== null && Storage::disk('local')->exists($importLog->file_path), 404);

        return response()->download(
            Storage::disk('local')->path($importLog->file_path),
            $importLog->file_name,
        );
    }

    public function downloadErrors(
        Request $request,
        ImportLog $importLog,
        HouseholdImportHistoryService $historyService,
    ): BinaryFileResponse {
        abort_unless($importLog->user_id === $request->user()->id, 404);
        abort_unless($importLog->status === 'FAILED' && $importLog->file_path !== null, 404);

        $errorFilePath = $historyService->errorFilePath($importLog);

        $extension = pathinfo($importLog->file_name, PATHINFO_EXTENSION);
        $fileName = pathinfo($importLog->file_name, PATHINFO_FILENAME).'-loi'.($extension !== '' ? '.'.$extension : '');

        return response()->download(
            Storage::disk('local')->path($errorFilePath),
            $fileName,
        );
    }

    public function destroy(Request $request, ImportLog $importLog): JsonResponse
    {
        abort_unless($importLog->user_id === $request->user()->id, 404);
        abort_if(
            in_array($importLog->status, ['PENDING', 'PROCESSING'], true),
            409,
            'Không thể xóa lịch sử khi file đang được xử lý.',
        );

        $paths = array_unique(array_filter([
            $importLog->file_path,
            $importLog->error_file_path,
        ]));

        foreach ($paths as $path) {
            if (Storage::disk('local')->exists($path) && ! Storage::disk('local')->delete($path)) {
                throw new \RuntimeException('Không thể xóa file khỏi lịch sử tải lên.');
            }
        }

        $importLog->delete();

        return response()->json(['message' => 'Đã xóa lịch sử tải file']);
    }
}
