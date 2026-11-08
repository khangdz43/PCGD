<?php

namespace App\Services\Households;

use App\Jobs\ProcessHouseholdImport;
use App\Models\Commune;
use App\Models\ImportLog;
use App\Models\User;
use App\Support\DataAccessScope;
use App\Services\Geography\VillageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HouseholdImportSubmissionService
{
    public function __construct(private readonly VillageService $villages) {}

    public function submit(User $user, array $data): ImportLog
    {
        return $this->submitMany($user, $data)->first();
    }

    public function submitMany(User $user, array $data): Collection
    {
        DataAccessScope::assertHouseholdLocation($user, $data);

        /** @var list<UploadedFile> $files */
        $files = $data['files'] ?? [$data['file']];

        return DB::transaction(function () use ($data, $files, $user): Collection {
            Commune::query()
                ->where('code', $data['commune_code'])
                ->lockForUpdate()
                ->firstOrFail();
            $this->villages->assertAcceptsHouseholds($data['village_code'], true);
            $importLogs = collect();
            foreach ($files as $file) {
                $importId = (string) Str::uuid();
                $path = $file->store('household-imports/' . $importId, 'local');
                $importLogs->push(ImportLog::create([
                    'file_name' => $file->getClientOriginalName(),
                    'user_id' => $user->id,
                    'file_path' => $path,
                    'context' => [
                        'school_year' => $data['school_year'],
                        'province_code' => $data['province_code'],
                        'commune_code' => $data['commune_code'],
                        'village_code' => $data['village_code'],
                    ],
                    'status' => 'PENDING',
                ]));
            }

            foreach ($importLogs as $importLog) {
                ProcessHouseholdImport::dispatch($importLog->id)
                    ->onConnection('database')
                    ->onQueue('default')
                    ->afterCommit();
            }

            return $importLogs;
        });
    }
}
