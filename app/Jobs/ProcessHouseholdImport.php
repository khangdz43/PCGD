<?php

namespace App\Jobs;

use App\Http\Controllers\Households\HouseholdImportExportController;
use App\Models\ImportLog;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

class ProcessHouseholdImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $importLogId) {}

    public function handle(HouseholdImportExportController $controller): void
    {
        $importLog = ImportLog::findOrFail($this->importLogId);
        $path = Storage::disk('local')->path($importLog->file_path);

        try {
            $user = User::findOrFail($importLog->user_id);
            $file = new UploadedFile(
                $path,
                $importLog->file_name,
                mime_content_type($path) ?: 'application/octet-stream',
                null,
                true,
            );
            $request = Request::create('/households/import', 'POST', $importLog->context, [], [
                'file' => $file,
            ]);
            $request->setUserResolver(static fn (): User => $user);

            $controller->processQueuedImport($request, $importLog);
        } catch (Throwable $exception) {
            $importLog->update([
                'status' => 'FAILED',
                'error_details' => [['message' => $exception->getMessage()]],
            ]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $importLog = ImportLog::find($this->importLogId);
        if ($importLog !== null && $importLog->status !== 'FAILED') {
            $importLog->update([
                'status' => 'FAILED',
                'error_details' => [['message' => $exception?->getMessage() ?? 'Không thể xử lý file nhập.']],
            ]);
        }

    }
}
