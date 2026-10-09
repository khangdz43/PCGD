<?php

namespace App\Support;

use App\Models\Household;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class DataAccessScope
{
    public static function households(Builder $query, User $user): Builder
    {
        if ($user->hasRole(Role::SYSTEM_ADMIN)) {
            return $query;
        }

        return match ($user->roleScopeLevel()) {
            'province' => $user->province_code
                ? $query->where('province_code', $user->province_code)
                : $query->whereRaw('1 = 0'),
            'commune' => $user->commune_code
                ? $query->where('commune_code', $user->commune_code)
                : $query->whereRaw('1 = 0'),
            'village' => $user->managedVillageCodes() !== []
                ? $query->whereIn('village_code', $user->managedVillageCodes())
                : $query->whereRaw('1 = 0'),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public static function canAccessHousehold(User $user, Household $household): bool
    {
        if ($user->hasRole(Role::SYSTEM_ADMIN)) {
            return true;
        }

        $inLocation = match ($user->roleScopeLevel()) {
            'province' => $user->province_code !== null && $household->province_code === $user->province_code,
            'commune' => $user->commune_code !== null && $household->commune_code === $user->commune_code,
            'village' => in_array($household->village_code, $user->managedVillageCodes(), true),
            default => false,
        };

        return $inLocation;
    }

    public static function assertHouseholdLocation(User $user, array $data): void
    {
        if ($user->hasRole(Role::SYSTEM_ADMIN)) {
            return;
        }

        $allowed = match ($user->roleScopeLevel()) {
            'province' => $user->province_code !== null && $data['province_code'] === $user->province_code,
            'commune' => $user->commune_code !== null
                && $data['province_code'] === $user->province_code
                && $data['commune_code'] === $user->commune_code,
            'village' => $user->managedVillageCodes() !== []
                && $data['province_code'] === $user->province_code
                && $data['commune_code'] === $user->commune_code
                && in_array($data['village_code'] ?? null, $user->managedVillageCodes(), true),
            default => false,
        };

        abort_unless($allowed, 403);
    }
}
