<?php

namespace App\Services\Geography;

use App\Models\Commune;
use App\Models\Province;
use App\Models\Role;
use App\Models\User;
use App\Support\AccessPermission;
use App\Support\DelegatedAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GeographyLookupService
{
    public function provincesForHome(User $user): Collection
    {
        $query = Province::select('code', 'name');
        if (! $user->hasRole(Role::SYSTEM_ADMIN)) {
            $query->where('code', $user->province_code);
        }

        return $query->get();
    }

    public function communesForProvince(
        string $provinceCode,
        User $user,
        ?string $availableFor,
        ?int $exceptUserId,
        bool $includeAccountCounts
    ): Collection {
        $province = Province::where('code', $provinceCode)->firstOrFail();
        $communes = $province->communes();

        if (! $user->hasRole(Role::SYSTEM_ADMIN)) {
            if ($user->province_code !== $provinceCode) {
                $communes->whereRaw('1 = 0');
            }

            if (in_array($user->roleScopeLevel(), ['commune', 'village'], true)) {
                $communes->where('code', $user->commune_code);
            }
        }

        if ($availableFor === 'commune-admin') {
            $occupied = DelegatedAccess::occupiedTerritorialCodes('commune', $exceptUserId);
            if ($occupied !== []) {
                $communes->whereNotIn('code', $occupied);
            }
        }

        $communeList = $communes->select('code', 'name')->get();
        if ($includeAccountCounts
            && $user->hasRole(Role::SYSTEM_ADMIN)
            && $user->can(AccessPermission::VIEW_ORGANIZATION_ACCOUNTS)) {
            $counts = DB::table('users')
                ->whereIn('commune_code', $communeList->pluck('code'))
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('model_has_roles')
                        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                        ->whereColumn('model_has_roles.model_id', 'users.id')
                        ->where('model_has_roles.model_type', User::class)
                        ->where('roles.name', Role::SYSTEM_ADMIN);
                })
                ->select('commune_code')
                ->selectRaw('COUNT(*) AS account_count')
                ->groupBy('commune_code')
                ->pluck('account_count', 'commune_code');

            $communeList->each(fn ($commune) => $commune->account_count = $counts[$commune->code] ?? 0);
        }

        return $communeList;
    }

    public function villagesForCommune(string $communeCode, User $user, bool $includeAccountCounts): Collection
    {
        $commune = Commune::where('code', $communeCode)->firstOrFail();
        $villages = $commune->villages();

        if (! $user->hasRole(Role::SYSTEM_ADMIN)) {
            if ($user->province_code !== $commune->province_code) {
                $villages->whereRaw('1 = 0');
            }

            if (in_array($user->roleScopeLevel(), ['commune', 'village'], true)
                && $user->commune_code !== $communeCode) {
                $villages->whereRaw('1 = 0');
            }

            if ($user->roleScopeLevel() === 'village') {
                $villages->whereIn('code', $user->managedVillageCodes());
            }
        }

        $villageList = $villages->select('code', 'name')->get();
        if ($includeAccountCounts
            && $user->hasRole(Role::SYSTEM_ADMIN)
            && $user->can(AccessPermission::VIEW_ORGANIZATION_ACCOUNTS)) {
            $counts = DB::table('user_villages')
                ->join('users', 'users.id', '=', 'user_villages.user_id')
                ->whereIn('user_villages.village_code', $villageList->pluck('code'))
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('model_has_roles')
                        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                        ->whereColumn('model_has_roles.model_id', 'users.id')
                        ->where('model_has_roles.model_type', User::class)
                        ->where('roles.name', Role::SYSTEM_ADMIN);
                })
                ->select('user_villages.village_code')
                ->selectRaw('COUNT(*) AS account_count')
                ->groupBy('user_villages.village_code')
                ->pluck('account_count', 'village_code');

            $villageList->each(fn ($village) => $village->account_count = $counts[$village->code] ?? 0);
        }

        return $villageList;
    }
}
