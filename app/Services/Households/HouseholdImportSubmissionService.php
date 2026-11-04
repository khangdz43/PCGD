<?php

namespace App\Services\Households;

use App\Jobs\ProcessHouseholdImport;
use App\Models\Commune;
use App\Models\ImportLog;
use App\Models\User;
use App\Support\DataAccessScope;
use App\Services\Geography\VillageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HouseholdImportSubmissionService
{
    public function __construct(private readonly VillageService $villages) {}

    public function submit(User $user, array $data): ImportLog
    {
        DataAccessScope::assertHouseholdLocation($user, $data);

        /** @var UploadedFile $file */
        $file = $data['file'];
        $importId = (string) Str::uuid();
        $importLog = DB::transaction(function () use ($data, $file, $importId, $user): ImportLog {
            Commune::query()
                ->where('code', $data['commune_code'])
                ->lockForUpdate()
                ->firstOrFail();
            $this->villages->assertAcceptsHouseholds($data['village_code'], true);
            $path = $file->store('household-imports/'.$importId, 'local');
            $importLog = ImportLog::create([
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
            ]);

            ProcessHouseholdImport::dispatch($importLog->id)
                ->onConnection('database')
                ->onQueue('default')
                ->afterCommit();

            return $importLog;
        });

        return $importLog;
    }
}
