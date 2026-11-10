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
    public function provincesForHome(User $user, bool $includeAccountCounts = false): Collection
    {
        $query = Province::query()->select('code', 'name');
        if (! $user->hasRole(Role::SYSTEM_ADMIN) && $user->roleScopeLevel() !== 'global') {
            $query->whereIn('code', $user->operatingProvinceCodes());
        }

        $provinces = $query->orderBy('name')->get();
        if ($includeAccountCounts && $this->canViewAccountCounts($user)) {
            $counts = DB::table('user_operating_areas')
                ->join('users', 'users.id', '=', 'user_operating_areas.user_id')
                ->join('roles', 'roles.id', '=', 'users.role_id')
                ->whereIn('user_operating_areas.province_code', $provinces->pluck('code'))
                ->where('roles.name', '!=', Role::SYSTEM_ADMIN)
                ->select('user_operating_areas.province_code')
                ->selectRaw('COUNT(DISTINCT users.id) AS account_count')
                ->groupBy('user_operating_areas.province_code')
                ->pluck('account_count', 'province_code');

            $provinces->each(fn ($province) => $province->account_count = $counts[$province->code] ?? 0);
        }

        return $provinces;
    }

    public function communesForProvince(
        string $provinceCode,
        User $user,
        ?string $availableFor,
        ?int $exceptUserId,
        bool $includeAccountCounts
    ): Collection {
        $province = Province::query()->where('code', $provinceCode)->firstOrFail();
        $communes = $province->communes();

        if (! $user->hasRole(Role::SYSTEM_ADMIN) && $user->roleScopeLevel() !== 'global') {
            if (! in_array($provinceCode, $user->operatingProvinceCodes(), true)) {
                $communes->whereRaw('1 = 0');
            }

            if ($user->roleScopeLevel() === 'commune') {
                $communes->whereIn('code', $user->operatingCommuneCodes($provinceCode));
            } elseif ($user->roleScopeLevel() === 'province' && ! $user->coversProvince($provinceCode)) {
                $communes->whereRaw('1 = 0');
            }
        }

        if ($availableFor === 'commune-admin') {
            $occupied = DelegatedAccess::occupiedTerritorialCodes(
                'commune',
                $exceptUserId,
                $provinceCode,
            );
            if ($occupied !== []) {
                $communes->whereNotIn('code', $occupied);
            }
        }

        $communeList = $communes->select('code', 'name')->get();
        if ($includeAccountCounts && $this->canViewAccountCounts($user)) {
            $countsQuery = DB::table('user_operating_areas')
                ->join('users', 'users.id', '=', 'user_operating_areas.user_id')
                ->join('roles', 'roles.id', '=', 'users.role_id')
                ->whereIn('user_operating_areas.commune_code', $communeList->pluck('code'));
            if ($user->hasRole(Role::SYSTEM_ADMIN)) {
                $countsQuery->where('roles.name', '!=', Role::SYSTEM_ADMIN);
            } else {
                $countsQuery->where('users.id', '!=', $user->id)
                    ->whereIn('roles.scope_level', DelegatedAccess::manageableAccountLevels($user));
            }
            $counts = $countsQuery
                ->select('user_operating_areas.commune_code')
                ->selectRaw('COUNT(DISTINCT users.id) AS account_count')
                ->groupBy('user_operating_areas.commune_code')
                ->pluck('account_count', 'commune_code');

            $communeList->each(fn ($commune) => $commune->account_count = $counts[$commune->code] ?? 0);
        }

        return $communeList;
    }

    public function villagesForCommune(string $communeCode, User $user, bool $includeAccountCounts): Collection
    {
        $commune = Commune::query()->where('code', $communeCode)->firstOrFail();
        $villages = $commune->villages()
            ->whereNull('merged_into_code')
            ->whereNull('intake_paused_at');

        if (! $user->hasRole(Role::SYSTEM_ADMIN) && $user->roleScopeLevel() !== 'global') {
            if (! $user->operatesInArea($commune->province_code, $communeCode)) {
                $villages->whereRaw('1 = 0');
            }

            if ($user->roleScopeLevel() === 'commune' && $user->managedVillageCodes() !== []) {
                $villages->whereIn('code', $user->managedVillageCodes());
            }
        }

        $villageList = $villages->select('code', 'name')->get();
        if ($includeAccountCounts && $this->canViewAccountCounts($user) && $villageList->isNotEmpty()) {
            $villageCodes = $villageList->pluck('code');
            $countsQuery = DB::table('user_operating_areas')
                ->join('users', 'users.id', '=', 'user_operating_areas.user_id')
                ->join('roles', 'roles.id', '=', 'users.role_id')
                ->whereIn('user_operating_areas.village_code', $villageCodes);
            if ($user->hasRole(Role::SYSTEM_ADMIN)) {
                $countsQuery->where('roles.name', '!=', Role::SYSTEM_ADMIN);
            } else {
                $countsQuery->where('users.id', '!=', $user->id)
                    ->where('user_operating_areas.commune_code', $user->commune_code)
                    ->where('roles.scope_level', 'commune');
            }
            $counts = $countsQuery
                ->select('user_operating_areas.village_code')
                ->selectRaw('COUNT(DISTINCT users.id) AS account_count')
                ->groupBy('user_operating_areas.village_code')
                ->pluck('account_count', 'village_code');

            $broadAreaCount = DB::table('user_operating_areas')
                ->join('users', 'users.id', '=', 'user_operating_areas.user_id')
                ->join('roles', 'roles.id', '=', 'users.role_id')
                ->where('user_operating_areas.commune_code', $communeCode)
                ->whereNull('user_operating_areas.village_code');
            if ($user->hasRole(Role::SYSTEM_ADMIN)) {
                $broadAreaCount->where('roles.name', '!=', Role::SYSTEM_ADMIN);
            } else {
                $broadAreaCount->where('users.id', '!=', $user->id)
                    ->where('roles.scope_level', 'commune');
            }
            $broadAreaCount = $broadAreaCount->distinct('users.id')->count('users.id');

            $villageList->each(fn ($village) => $village->account_count =
                ($counts[$village->code] ?? 0) + $broadAreaCount);
        }

        return $villageList;
    }

    private function canViewAccountCounts(User $user): bool
    {
        return ($user->hasRole(Role::SYSTEM_ADMIN) && $user->can(AccessPermission::VIEW_ORGANIZATION_ACCOUNTS))
            || $user->canManageSubordinateUsers();
    }
}
