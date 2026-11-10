<?php

namespace App\Support;

use App\Models\Household;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

final class DataAccessScope
{
    public static function households(Builder $query, User $user): Builder
    {
        if ($user->hasRole(Role::SYSTEM_ADMIN)) {
            return $query;
        }

        if ($user->roleScopeLevel() === 'global') {
            return $query;
        }
        if (! in_array($user->roleScopeLevel(), ['province', 'commune'], true)) {
            return $query->whereRaw('1 = 0');
        }

        if (! Schema::hasTable('user_operating_areas')) {
            return match ($user->roleScopeLevel()) {
                'province' => $user->province_code
                    ? $query->where('province_code', $user->province_code)
                    : $query->whereRaw('1 = 0'),
                'commune' => $user->commune_code
                    ? $query->where('commune_code', $user->commune_code)
                        ->when($user->managedVillageCodes() !== [], fn (Builder $builder) => $builder
                            ->whereIn('village_code', $user->managedVillageCodes()))
                    : $query->whereRaw('1 = 0'),
            };
        }

        $table = $query->getModel()->getTable();

        return $query->whereExists(function ($areas) use ($table, $user): void {
            $areas->selectRaw('1')
                ->from('user_operating_areas')
                ->where('user_operating_areas.user_id', $user->id)
                ->whereColumn('user_operating_areas.province_code', "{$table}.province_code")
                ->where(function ($communes) use ($table): void {
                    $communes->whereNull('user_operating_areas.commune_code')
                        ->orWhereColumn('user_operating_areas.commune_code', "{$table}.commune_code");
                })
                ->where(function ($villages) use ($table): void {
                    $villages->whereNull('user_operating_areas.village_code')
                        ->orWhereColumn('user_operating_areas.village_code', "{$table}.village_code");
                });
        });
    }

    public static function canAccessHousehold(User $user, Household $household): bool
    {
        if ($user->hasRole(Role::SYSTEM_ADMIN)) {
            return true;
        }

        return $user->roleScopeLevel() === 'global'
            || (in_array($user->roleScopeLevel(), ['province', 'commune'], true)
                && $user->operatesInArea(
                    (string) $household->province_code,
                    $household->commune_code,
                    $household->village_code,
                ));
    }

    public static function assertHouseholdLocation(User $user, array $data): void
    {
        if ($user->hasRole(Role::SYSTEM_ADMIN)) {
            return;
        }

        $allowed = $user->roleScopeLevel() === 'global'
            || (in_array($user->roleScopeLevel(), ['province', 'commune'], true)
                && isset($data['province_code'])
                && $user->operatesInArea(
                    (string) $data['province_code'],
                    $data['commune_code'] ?? null,
                    $data['village_code'] ?? null,
                ));

        abort_unless($allowed, 403);
    }
}
