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
            ->whereIn('status', ['SUCCESS', 'PARTIAL', 'FAILED'])
            ->whereNull('notification_read_at')
            ->oldest('created_at')
            ->limit(5)
            ->get();

        return response()->json([
            'notifications' => $imports->map(fn(ImportLog $importLog): array => [
                'id' => $importLog->id,
                'status' => $importLog->status,
                'message' => match ($importLog->status) {
                    'SUCCESS' => "File \"{$importLog->file_name}\" đã nhập thành công: {$importLog->success_rows} dòng.",
                    'PARTIAL' => "File \"{$importLog->file_name}\" đã xử lý xong: {$importLog->success_rows} dòng thành công, {$importLog->error_rows} dòng lỗi.",
                    default => $importLog->error_rows > 0
                        ? "File \"{$importLog->file_name}\" không có dòng nào được nhập: {$importLog->error_rows} dòng lỗi."
                        : "Không thể xử lý file \"{$importLog->file_name}\". Hãy kiểm tra định dạng tệp.",
                },
                'action_url' => route('api.households.import-history', [], false),
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
            ->whereIn('status', ['SUCCESS', 'PARTIAL', 'FAILED'])
            ->whereNull('notification_read_at')
            ->whereIn('id', $validated['ids'])
            ->update(['notification_read_at' => now()]);

        return response()->json(['acknowledged' => true]);
    }
}
