<?php

namespace App\Http\Controllers\Households;

use App\Http\Controllers\Controller;
use App\Models\Commune;
use App\Models\Province;
use App\Models\Role;
use App\Models\User;
use App\Models\Village;
use App\Services\Households\HouseholdTrashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HouseholdTrashController extends Controller
{
    public function __construct(private readonly HouseholdTrashService $trash) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $filters = $request->validate([
            'type' => ['nullable', 'in:all,household,person'],
            'q' => ['nullable', 'string', 'max:100'],
            'deleted_from' => ['nullable', 'date'],
            'deleted_to' => ['nullable', 'date', 'after_or_equal:deleted_from'],
            'province_code' => ['nullable', 'string', 'max:20'],
            'commune_code' => ['nullable', 'string', 'max:20'],
            'village_code' => ['nullable', 'string', 'max:20'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $data = $this->trash->paginate($user, $filters);

        $selectedProvinceCode = $filters['province_code']
            ?? ($user->hasRole(Role::SYSTEM_ADMIN) || $user->roleScopeLevel() === 'global'
                ? null
                : $user->province_code);
        $provinceQuery = Province::query()->orderBy('name');
        if (! $user->hasRole(Role::SYSTEM_ADMIN) && $user->province_code !== null) {
            $provinceQuery->where('code', $user->province_code);
        }
        $provinces = $provinceQuery->get(['code', 'name']);
        $communeQuery = Commune::query()->orderBy('name');
        if (filled($selectedProvinceCode)) {
            $communeQuery->where('province_code', $selectedProvinceCode);
        }
        if (! $user->hasRole(Role::SYSTEM_ADMIN) && $user->roleScopeLevel() !== 'global') {
            $communeQuery->where('province_code', $user->province_code);
            if (in_array($user->roleScopeLevel(), ['commune', 'village'], true)) {
                $communeQuery->where('code', $user->commune_code);
            }
        }
        $communes = $communeQuery->get(['code', 'name', 'province_code']);
        $selectedCommuneCode = $filters['commune_code']
            ?? (in_array($user->roleScopeLevel(), ['commune', 'village'], true)
                ? $user->commune_code
                : null);
        $selectedCommune = $communes->firstWhere('code', $selectedCommuneCode);
        $villageQuery = Village::query()->orderBy('name');
        if ($selectedCommune) {
            $villageQuery->where('commune_code', $selectedCommune->code);
        } else {
            $villageQuery->whereRaw('1 = 0');
        }
        if ($user->roleScopeLevel() === 'village') {
            $villageCodes = $user->managedVillageCodes();
            $villageQuery->whereIn('code', $villageCodes);
        }
        $villages = $villageQuery->get(['code', 'name']);

        return response()->json([
            ...$data,
            'provinces' => $provinces,
            'communes' => $communes,
            'selected_province_code' => $selectedProvinceCode,
            'selected_commune_code' => $selectedCommune?->code,
            'initial_commune' => $selectedCommune,
            'villages' => $villages,
            'filters' => $filters,
        ]);
    }

    public function showHousehold(Request $request, int $id): JsonResponse
    {
        $household = $this->trash->householdForDetails($request->user(), $id);

        return response()->json(['data' => $household]);
    }

    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'operation' => ['required', 'in:restore,force_delete'],
            'household_ids' => ['nullable', 'array'],
            'household_ids.*' => ['integer', 'distinct'],
            'person_ids' => ['nullable', 'array'],
            'person_ids.*' => ['integer', 'distinct'],
        ]);
        $householdIds = $data['household_ids'] ?? [];
        $personIds = $data['person_ids'] ?? [];
        if ($householdIds === [] && $personIds === []) {
            return response()->json(['message' => 'Hãy chọn ít nhất một mục trong thùng rác.'], 422);
        }

        $count = $data['operation'] === 'restore'
            ? $this->trash->restoreMany($request->user(), $householdIds, $personIds)
            : $this->trash->permanentlyDeleteMany($request->user(), $householdIds, $personIds);

        $message = $data['operation'] === 'restore'
            ? "Đã khôi phục {$count} mục đã chọn."
            : "Đã xóa vĩnh viễn {$count} mục đã chọn.";

        return response()->json(['message' => $message, 'affected_count' => $count]);
    }

    public function restoreHousehold(Request $request, int $id): JsonResponse
    {
        $this->trash->restoreHousehold($request->user(), $id);

        return response()->json(['message' => 'Đã khôi phục phiếu điều tra và các dữ liệu trong hộ.']);
    }

    public function permanentlyDeleteHousehold(Request $request, int $id): JsonResponse
    {
        $this->trash->permanentlyDeleteHousehold($request->user(), $id);

        return response()->json(['message' => 'Đã xóa vĩnh viễn phiếu điều tra và toàn bộ dữ liệu trong hộ.']);
    }

    public function restorePerson(Request $request, int $id): JsonResponse
    {
        $this->trash->restorePerson($request->user(), $id);

        return response()->json(['message' => 'Đã khôi phục thành viên.']);
    }

    public function permanentlyDeletePerson(Request $request, int $id): JsonResponse
    {
        $this->trash->permanentlyDeletePerson($request->user(), $id);

        return response()->json(['message' => 'Đã xóa vĩnh viễn thành viên và dữ liệu liên quan.']);
    }
}
