<?php

namespace App\Http\Controllers\Households;

use App\Http\Controllers\Controller;
use App\Models\ImportLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HouseholdImportNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $imports = ImportLog::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('status', ['SUCCESS', 'FAILED'])
            ->whereNull('notification_read_at')
            ->oldest('created_at')
            ->limit(5)
            ->get();

        return response()->json([
            'notifications' => $imports->map(fn (ImportLog $importLog): array => [
                'id' => $importLog->id,
                'status' => $importLog->status,
                'message' => $importLog->status === 'SUCCESS'
                    ? "File \"{$importLog->file_name}\" đã nhập thành công: {$importLog->success_rows} dòng."
                    : "File \"{$importLog->file_name}\" nhập thất bại: {$importLog->success_rows} dòng thành công, {$importLog->error_rows} dòng lỗi.",
                'action_url' => route('households.import-history', [], false),
                'action_label' => 'Xem',
            ])->values(),
        ]);
    }

    public function acknowledge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:5'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ]);

        ImportLog::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('status', ['SUCCESS', 'FAILED'])
            ->whereNull('notification_read_at')
            ->whereIn('id', $validated['ids'])
            ->update(['notification_read_at' => now()]);

        return response()->json(['acknowledged' => true]);
    }
}
