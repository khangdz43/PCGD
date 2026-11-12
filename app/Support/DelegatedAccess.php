<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class DelegatedAccess
{
    private const CHILD_LEVELS = [
        'global' => ['province'],
        'province' => ['commune'],
        'commune' => [],
    ];

    public static function manageableAccountLevels(User $actor): array
    {
        if ($actor->hasRole(Role::SYSTEM_ADMIN)) {
            return ['global', ...self::CHILD_LEVELS['global']];
        }

        abort_unless($actor->isAccountAdministrator(), 403);

        return self::managedLevels($actor);
    }

    public static function manageableRoleLevels(User $actor): array
    {
        if ($actor->hasRole(Role::SYSTEM_ADMIN)) {
            return ['global', 'province'];
        }

        abort_unless($actor->isAccountAdministrator(), 403);

        return self::managedLevels($actor);
    }

    public static function hasActiveTerritorialAdmin(
        string $scopeLevel,
        array $scope,
        ?int $ignoreUserId = null
    ): bool {
        if (! in_array($scopeLevel, ['province', 'commune'], true)) {
            return false;
        }

        $query = User::query()->where('is_active', true);
        self::constrainToPrimaryAdminRole($query, $scopeLevel);

        if ($ignoreUserId !== null) {
            $query->where('users.id', '!=', $ignoreUserId);
        }

        if ($scopeLevel === 'province') {
            $provinceCode = $scope['province_code'] ?? null;
            if (! filled($provinceCode)) {
                return false;
            }

            return $query->whereHas('operatingAreas', fn (Builder $areas) => $areas
                ->where('province_code', $provinceCode)
                ->whereNull('commune_code')
                ->whereNull('village_code'))->exists();
        }

        $communeCode = $scope['commune_code'] ?? null;
        if (! filled($communeCode)) {
            return false;
        }

        return $query->whereHas('operatingAreas', function (Builder $areas) use ($scope, $communeCode): void {
            $areas->where('commune_code', $communeCode);
            if (filled($scope['province_code'] ?? null)) {
                $areas->where('province_code', $scope['province_code']);
            }
        })->exists();
    }

    public static function occupiedTerritorialCodes(
        string $scopeLevel,
        ?int $ignoreUserId = null,
        ?string $provinceCode = null,
    ): array {
        if (! in_array($scopeLevel, ['province', 'commune'], true)) {
            return [];
        }

        $column = $scopeLevel === 'province'
            ? 'user_operating_areas.province_code'
            : 'user_operating_areas.commune_code';
        $adminPermissions = $scopeLevel === 'province'
            ? AccessPermission::permissionSources(AccessPermission::MANAGE_SCHOOLS)
            : AccessPermission::permissionSources(AccessPermission::MANAGE_VILLAGES);
        $accountPermissions = AccessPermission::permissionSources(AccessPermission::MANAGE_USER_ACCOUNTS);

        $query = DB::table('user_operating_areas')
            ->join('users', 'users.id', '=', 'user_operating_areas.user_id')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('users.is_active', true)
            ->where('roles.scope_level', $scopeLevel)
            ->where('roles.guard_name', 'web')
            ->whereNotNull($column)
            ->where(function ($permissions) use ($adminPermissions): void {
                foreach ($adminPermissions as $index => $permission) {
                    $method = $index === 0 ? 'whereJsonContains' : 'orWhereJsonContains';
                    $permissions->{$method}('roles.permission_codes', $permission);
                }
            })
            ->where(function ($permissions) use ($accountPermissions): void {
                foreach ($accountPermissions as $index => $permission) {
                    $method = $index === 0 ? 'whereJsonContains' : 'orWhereJsonContains';
                    $permissions->{$method}('roles.permission_codes', $permission);
                }
            });

        if ($scopeLevel === 'commune' && $provinceCode !== null) {
            $query->where('user_operating_areas.province_code', $provinceCode);
        }
        if ($ignoreUserId !== null) {
            $query->where('users.id', '!=', $ignoreUserId);
        }

        return $query->distinct()->pluck($column)->all();
    }

    public static function scopeIsAllowed(
        User $actor,
        string $targetLevel,
        array $scope,
        array $villageCodes = []
    ): bool {
        if (! in_array($targetLevel, self::manageableAccountLevels($actor), true)) {
            return false;
        }

        $provinceCode = $scope['province_code'] ?? null;
        $communeCode = $scope['commune_code'] ?? null;
        if ($actor->hasRole(Role::SYSTEM_ADMIN)) {
            return match ($targetLevel) {
                'global' => $provinceCode === null && $communeCode === null && $villageCodes === [],
                'province' => filled($provinceCode) && $communeCode === null && $villageCodes === [],
                default => false,
            };
        }

        if ($actor->roleScopeLevel() === 'global') {
            return match ($targetLevel) {
                'global' => $provinceCode === null && $communeCode === null && $villageCodes === [],
                'province' => filled($provinceCode) && $communeCode === null && $villageCodes === [],
                default => false,
            };
        }

        if (! filled($provinceCode)
            || ! in_array($provinceCode, $actor->operatingProvinceCodes(), true)) {
            return false;
        }

        if ($actor->roleScopeLevel() === 'province') {
            return match ($targetLevel) {
                'province' => $communeCode === null && $villageCodes === []
                    && $actor->coversProvince($provinceCode),
                'commune' => filled($communeCode) && $villageCodes === []
                    && $actor->coversProvince($provinceCode),
                default => false,
            };
        }

        if ($actor->roleScopeLevel() !== 'commune'
            || ! filled($communeCode)
            || ! $actor->operatesInArea($provinceCode, $communeCode)) {
            return false;
        }

        $actorVillages = $actor->managedVillageCodes();

        return $targetLevel === 'commune'
            && ($actorVillages === []
                || ($villageCodes !== [] && array_diff($villageCodes, $actorVillages) === []));
    }

    public static function roleBelongsToActorArea(
        User $actor,
        Role $role,
        ?array $manageableLevels = null,
    ): bool {
        return $actor->hasRole(Role::SYSTEM_ADMIN)
            || (
                in_array($role->scope_level, $manageableLevels ?? self::manageableRoleLevels($actor), true)
                && (
                    $role->created_by === $actor->id
                    || ($role->isSystemAccountAdministrator() && $actor->isAccountAdministrator())
                )
            );
    }

    public static function userIsInActorArea(
        User $actor,
        User $target,
        string $targetLevel,
        ?array $actorVillageCodes = null,
    ): bool {
        $target->loadMissing('operatingAreas');
        $areas = $target->operatingAreas;

        if ($targetLevel === 'global') {
            return $areas->isEmpty()
                && ! $target->hasRole(Role::SYSTEM_ADMIN)
                && ($actor->hasRole(Role::SYSTEM_ADMIN) || $actor->roleScopeLevel() === 'global');
        }

        if ($areas->isEmpty()) {
            return false;
        }

        if ($targetLevel === 'province') {
            if ($areas->contains(fn ($area): bool => $area->commune_code !== null || $area->village_code !== null)) {
                return false;
            }

            if ($actor->hasRole(Role::SYSTEM_ADMIN) || $actor->roleScopeLevel() === 'global') {
                return true;
            }

            return $areas->every(fn ($area): bool => $actor->coversProvince($area->province_code));
        }

        if ($targetLevel !== 'commune'
            || $areas->contains(fn ($area): bool => $area->commune_code === null)) {
            return false;
        }

        if ($actor->hasRole(Role::SYSTEM_ADMIN) || $actor->roleScopeLevel() === 'global') {
            return true;
        }
        if ($actor->roleScopeLevel() === 'province') {
            return $areas->every(fn ($area): bool => $actor->coversProvince($area->province_code));
        }
        if ($actor->roleScopeLevel() !== 'commune') {
            return false;
        }

        $actorVillageCodes ??= $actor->managedVillageCodes();
        $targetVillageCodes = $areas->pluck('village_code')->filter()->unique()->values()->all();
        if ($actorVillageCodes !== [] && (
            $areas->contains(fn ($area): bool => $area->village_code === null)
            || $targetVillageCodes === []
            || array_diff($targetVillageCodes, $actorVillageCodes) !== []
        )) {
            return false;
        }

        return $areas->every(fn ($area): bool => $actor->operatesInArea(
            $area->province_code,
            $area->commune_code,
            $area->village_code,
        ));
    }

    private static function managedLevels(User $actor): array
    {
        $level = $actor->roleScopeLevel();
        abort_unless($level !== null && array_key_exists($level, self::CHILD_LEVELS), 403);

        $sameLevel = in_array($level, ['global', 'province', 'commune'], true) ? [$level] : [];

        return [...$sameLevel, ...self::CHILD_LEVELS[$level]];
    }

    private static function constrainToPrimaryAdminRole(Builder $query, string $scopeLevel): void
    {
        $adminPermission = match ($scopeLevel) {
            'province' => AccessPermission::permissionSources(AccessPermission::MANAGE_SCHOOLS),
            'commune' => AccessPermission::permissionSources(AccessPermission::MANAGE_VILLAGES),
            default => null,
        };
        if ($adminPermission === null) {
            return;
        }

        $query->whereHas('role', function (Builder $roles) use ($scopeLevel, $adminPermission): void {
            $roles->where('roles.scope_level', $scopeLevel)
                ->where('roles.guard_name', 'web');
            self::constrainRolePermissions($roles, $adminPermission);
            self::constrainRolePermissions(
                $roles,
                AccessPermission::permissionSources(AccessPermission::MANAGE_USER_ACCOUNTS)
            );
        });
    }

    private static function constrainRolePermissions(Builder $roles, array $permissionNames): void
    {
        $roles->where(function (Builder $query) use ($permissionNames): void {
            foreach ($permissionNames as $index => $permissionName) {
                $method = $index === 0 ? 'whereJsonContains' : 'orWhereJsonContains';
                $query->{$method}('permission_codes', $permissionName);
            }
        });
    }
}
