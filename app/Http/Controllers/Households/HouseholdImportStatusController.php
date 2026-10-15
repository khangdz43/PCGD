<?php

namespace App\Http\Controllers\Households;

use App\Http\Controllers\Controller;
use App\Models\ImportLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class HouseholdImportStatusController extends Controller
{
    public function __invoke(Request $request, ImportLog $importLog): JsonResponse
    {
        abort_unless($importLog->user_id === $request->user()->id, 404);

        return response()->json($this->statusData($importLog));
    }

    public function batch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:20'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $imports = ImportLog::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('id', $validated['ids'])
            ->get([
                'id',
                'status',
                'processed_rows',
                'total_rows',
                'success_rows',
                'error_rows',
                'file_path',
            ]);
        $pendingImportIds = ImportLog::query()
            ->where('status', 'PENDING')
            ->oldest('created_at')
            ->oldest('id')
            ->pluck('id');
        $queuePositions = $pendingImportIds->values()->mapWithKeys(
            fn (int $id, int $index): array => [$id => $index + 1],
        );
        $queueTotal = $pendingImportIds->count();

        return response()->json([
            'statuses' => $imports->mapWithKeys(function (ImportLog $importLog) use ($queuePositions, $queueTotal): array {
                $status = $this->statusData($importLog);
                $status['queue_position'] = $importLog->status === 'PENDING'
                    ? ($queuePositions[$importLog->id] ?? null)
                    : null;
                $status['queue_total'] = $importLog->status === 'PENDING' ? $queueTotal : null;

                return [(string) $importLog->id => $status];
            }),
        ]);
    }

    private function statusData(ImportLog $importLog): array
    {
        $progress = $importLog->status === 'SUCCESS'
            ? 100
            : ($importLog->total_rows > 0
                ? min(100, (int) floor($importLog->processed_rows * 100 / $importLog->total_rows))
                : 0);

        if ($importLog->status === 'FAILED') {
            $progress = min(90, $progress);
        }

        return [
            'status' => $importLog->status,
            'progress' => min(100, $progress),
            'processed_rows' => $importLog->processed_rows,
            'total_rows' => $importLog->total_rows,
            'success_rows' => $importLog->success_rows,
            'error_rows' => $importLog->error_rows,
            'has_error_file' => $importLog->status === 'FAILED'
                && $importLog->file_path !== null
                && Storage::disk('local')->exists($importLog->file_path),
        ];
    }
}
