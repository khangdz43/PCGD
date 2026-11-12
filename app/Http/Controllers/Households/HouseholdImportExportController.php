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
        $communeCode = $user->roleScopeLevel() === 'commune'
            ? $user->commune_code
            : null;
        $managedVillageCodes = $user->roleScopeLevel() === 'commune'
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
                $villagesQuery = $initialCommune->villages()
                    ->whereNull('merged_into_code')
                    ->whereNull('intake_paused_at')
                    ->orderBy('name');
                if ($managedVillageCodes !== []) {
                    $villagesQuery->whereIn('code', $managedVillageCodes);
                }
                $initialVillages = $villagesQuery->get(['code', 'name']);
            }
        }

        return response()->json([
            'provinces' => $provinces,
            'province_code' => $provinceCode,
            'commune_code' => $communeCode,
            'village_code' => $villageCode,
            'village_required' => $villageRequired,
            'initial_commune' => $initialCommune,
            'initial_villages' => $initialVillages,
        ]);
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
        $importLogs = $submissionService->submitMany($request->user(), $request->validated());
        $imports = $importLogs->map(fn(ImportLog $importLog): array => [
            'id' => $importLog->id,
            'file_name' => $importLog->file_name,
        ])->values();
        $firstImport = $importLogs->first();
        $count = $importLogs->count();
        $request->session()->flash(
            'info',
            $count === 1
                ? 'File đã được tải lên thành công và đang được hệ thống xử lý. Bạn có thể tiếp tục công việc khác; hệ thống sẽ thông báo khi hoàn tất.'
                : "Đã tải lên {$count} tệp thành công. Hệ thống sẽ xử lý lần lượt và thông báo kết quả từng tệp."
        );

        $response = [
            'import_ids' => $importLogs->pluck('id')->all(),
            'imports' => $imports,
            'history_url' => route('api.households.import-history', ['import_id' => $firstImport->id], false),
        ];
        if ($count === 1) {
            $response['import_id'] = $firstImport->id;
            $response['status_url'] = route('api.households.import.status', $firstImport, false);
        }

        return response()->json($response, 202);
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
