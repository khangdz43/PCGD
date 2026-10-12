<?php

namespace App\Http\Controllers\Households;

use App\Http\Controllers\Controller;
use App\Http\Requests\Households\ExportHouseholdsRequest;
use App\Http\Requests\Households\StoreHouseholdImportRequest;
use App\Models\Commune;
use App\Models\ImportLog;
use App\Models\Province;
use App\Models\Role;
use App\Services\Households\HouseholdExportService;
use App\Services\Households\HouseholdImportProcessingService;
use App\Services\Households\HouseholdImportSubmissionService;
use Illuminate\Http\Request;

class HouseholdImportExportController extends Controller
{
    public function create(Request $request)
    {
        $user = $request->user();
        $provinceQuery = Province::query()->orderBy('name');
        if (! $user->hasRole(Role::SYSTEM_ADMIN)) {
            $provinceQuery->where('code', $user->province_code);
        }
        $provinces = $provinceQuery->get(['code', 'name']);
        $provinceCode = $user->hasRole(Role::SYSTEM_ADMIN) ? null : $user->province_code;
        $communeCode = in_array($user->roleScopeLevel(), ['commune', 'village'], true)
            ? $user->commune_code
            : null;
        $managedVillageCodes = $user->roleScopeLevel() === 'village'
            ? $user->managedVillageCodes()
            : [];
        $villageCode = count($managedVillageCodes) === 1
            ? $managedVillageCodes[0]
            : null;
        $villageRequired = true;
        $initialCommune = null;
        $initialVillages = collect();

        if ($communeCode !== null) {
            $initialCommune = Commune::query()
                ->where('code', $communeCode)
                ->where('province_code', $provinceCode)
                ->first(['code', 'name']);

            if ($initialCommune !== null) {
                $villagesQuery = $initialCommune->villages()->orderBy('name');
                if ($user->roleScopeLevel() === 'village') {
                    $villagesQuery->whereIn('code', $managedVillageCodes);
                }
                $initialVillages = $villagesQuery->get(['code', 'name']);
            }
        }

        return view('households.import', compact(
            'provinces',
            'provinceCode',
            'communeCode',
            'villageCode',
            'villageRequired',
            'initialCommune',
            'initialVillages'
        ));
    }

    public function template()
    {
        $path = base_path('file-mau.XLS');
        abort_unless(is_file($path), 404, 'Không tìm thấy file mẫu Excel.');

        return response()->download($path, 'file-mau.XLS');
    }

    public function import(
        StoreHouseholdImportRequest $request,
        HouseholdImportSubmissionService $submissionService,
    ) {
        $importLog = $submissionService->submit($request->user(), $request->validated());

        return response()->json([
            'import_id' => $importLog->id,
            'status_url' => route('households.import.status', $importLog, false),
        ], 202);
    }

    public function processQueuedImport(
        Request $request,
        ImportLog $importLog,
        HouseholdImportProcessingService $processingService,
    ) {
        return $processingService->process($importLog, $request->user(), $request->file('file'));
    }

    public function export(ExportHouseholdsRequest $request, HouseholdExportService $exportService)
    {
        return $exportService->export(
            $request->user(),
            $request->validated(),
            $request->only(['q', 'province_code', 'commune_code', 'village_code']),
        );
    }
}
