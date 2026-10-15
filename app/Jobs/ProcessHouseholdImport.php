<?php

namespace App\Jobs;

use App\Models\ImportLog;
use App\Models\User;
use App\Services\Households\HouseholdImportProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

class ProcessHouseholdImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 2;

    public function __construct(public int $importLogId) {}

    public function handle(HouseholdImportProcessingService $processingService): void
    {
        $importLog = ImportLog::findOrFail($this->importLogId);
        if (in_array($importLog->status, ['SUCCESS', 'FAILED', 'ROLLED_BACK'], true)) {
            return;
        }

        if ($importLog->status !== 'PROCESSING') {
            $importLog->update(['status' => 'PROCESSING']);
        }

        $path = Storage::disk('local')->path($importLog->file_path);

        $user = User::findOrFail($importLog->user_id);
        $file = new UploadedFile(
            $path,
            $importLog->file_name,
            mime_content_type($path) ?: 'application/octet-stream',
            null,
            true,
        );

        $processingService->process($importLog, $user, $file);
    }

    public function failed(?Throwable $exception): void
    {
        $importLog = ImportLog::find($this->importLogId);
        if ($importLog !== null) {
            $importLog->update(['status' => 'FAILED']);
        }

        Log::error('Không thể xử lý file nhập hộ gia đình.', [
            'import_log_id' => $this->importLogId,
            'exception' => $exception,
        ]);
    }
}
