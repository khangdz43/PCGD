<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
// rule
final class DelegatedAccess
{
    // ông cao quản lí ông dưới nó 1 cấp
    private const CHILD_LEVELS = [
        'global' => ['province'],
        'province' => ['commune'],
        'commune' => ['village'],
        'village' => [],
    ];
    // check quyền quản lí tk cấp nào của user hiện tịa
    public static function manageableAccountLevels(User $actor): array
    {
        // ko đúng ném 403
        abort_unless(
            $actor->hasRole(Role::SYSTEM_ADMIN) || $actor->canManageSubordinateUsers(),
            403
        );
        
        if ($actor->hasRole(Role::SYSTEM_ADMIN)) {
            return self::CHILD_LEVELS['global'];
        }

        return self::managedLevels($actor);
    }
    // tạo nhóm người dùng role
    public static function manageableRoleLevels(User $actor): array
    {
        if ($actor->hasRole(Role::SYSTEM_ADMIN)) {
            return ['province'];
        }
        return self::managedLevels($actor);
    }
    // check có admin của tỉnh xã đó chưa vì 1 tỉnh xả có 1 thôi
    public static function hasActiveTerritorialAdmin(
        string $scopeLevel,
        array $scope,
        ?int $ignoreUserId = null
    ): bool {
        if (! in_array($scopeLevel, ['province', 'commune'], true)) {
            return false;
        }

        $query = User::query()->where('is_active', true);

        if ($ignoreUserId !== null) {
            $query->where('id', '!=', $ignoreUserId);
        }

        if ($scopeLevel === 'province') {
            $provinceCode = $scope['province_code'] ?? null;
            if (! filled($provinceCode)) {
                return false;
            }

            return $query
                ->where('province_code', $provinceCode)
                ->where(function ($builder) use ($scopeLevel): void {
                    self::constrainToTerritorialLevel($builder, $scopeLevel);
                })
                ->exists();
        }

        $communeCode = $scope['commune_code'] ?? null;
        if (! filled($communeCode)) {
            return false;
        }

        return $query
            ->where('commune_code', $communeCode)
            ->where(function ($builder) use ($scopeLevel): void {
                self::constrainToTerritorialLevel($builder, $scopeLevel);
            })
            ->exists();
    }
    // mã địa bàn đã có chủ tịch xã tỉnh
    public static function occupiedTerritorialCodes(string $scopeLevel, ?int $ignoreUserId = null): array
    {
        if (! in_array($scopeLevel, ['province', 'commune'], true)) {
            return [];
        }

        $column = $scopeLevel === 'province' ? 'province_code' : 'commune_code';
        $query = User::query()
            ->where('is_active', true)
            ->whereNotNull($column)
            ->where(function ($builder) use ($scopeLevel): void {
                self::constrainToTerritorialLevel($builder, $scopeLevel);
            });

        if ($ignoreUserId !== null) {
            $query->where('id', '!=', $ignoreUserId);
        }

        return $query->pluck($column)->unique()->values()->all();
    }
    // check user có được phép phân vùng lãnh thổ k
    public static function scopeIsAllowed(
        User $actor,
        string $targetLevel,
        array $scope,
        array $villageCodes = []
    ): bool {
        if (! in_array($targetLevel, self::manageableAccountLevels($actor), true)) {
            return false;
        }

        if ($actor->hasRole(Role::SYSTEM_ADMIN)) {
            return filled($scope['province_code'] ?? null)
                && match ($targetLevel) {
                    'province' => ($scope['commune_code'] ?? null) === null && $villageCodes === [],
                    'commune' => filled($scope['commune_code'] ?? null) && $villageCodes === [],
                    'village' => filled($scope['commune_code'] ?? null) && $villageCodes !== [],
                    default => false,
                };
        }

        if ($actor->province_code === null || $scope['province_code'] !== $actor->province_code) {
            return false;
        }

        if ($actor->roleScopeLevel() === 'province') {
            return match ($targetLevel) {
                'province' => ($scope['commune_code'] ?? null) === null && $villageCodes === [],
                'commune' => filled($scope['commune_code'] ?? null) && $villageCodes === [],
                default => false,
            };
        }

        if ($actor->commune_code === null || ($scope['commune_code'] ?? null) !== $actor->commune_code) {
            return false;
        }

        if ($targetLevel === 'commune') {
            return $villageCodes === [];
        }

        if ($targetLevel !== 'village' || $villageCodes === []) {
            return false;
        }

        if ($actor->roleScopeLevel() === 'village') {
            return array_diff($villageCodes, $actor->managedVillageCodes()) === [];
        }

        return true;
    }
    // check role cụ thể có thuộc về scope mà user hiện tại qunar lí ko
    public static function roleBelongsToActorArea(User $actor, Role $role): bool
    {
        if ($actor->hasRole(Role::SYSTEM_ADMIN)) {
            return true;
        }

        if (! in_array($role->scope_level, self::manageableRoleLevels($actor), true)) {
            return false;
        }

        if (
            $role->province_code !== null
            && $role->province_code !== $actor->province_code
        ) {
            return false;
        }

        return $role->commune_code === null
            || $role->commune_code === $actor->commune_code;
    }
    //return vùng ảnh hưởng khi user tạo 1 role
    public static function roleAreaFor(User $actor): array
    {
        if ($actor->hasRole(Role::SYSTEM_ADMIN)) {
            return ['province_code' => null, 'commune_code' => null];
        }

        return [
            'province_code' => $actor->province_code,
            'commune_code' => in_array($actor->roleScopeLevel(), ['commune', 'village'], true)
                ? $actor->commune_code
                : null,
        ];
    }
// check user này có trong vùng quản li của mình 
    public static function userIsInActorArea(User $actor, User $target, string $targetLevel): bool
    {
        if ($actor->hasRole(Role::SYSTEM_ADMIN)) {
            return $target->province_code !== null
                && match ($targetLevel) {
                    'province' => $target->commune_code === null && $target->managedVillageCodes() === [],
                    'commune' => $target->commune_code !== null && $target->managedVillageCodes() === [],
                    'village' => $target->commune_code !== null && $target->managedVillageCodes() !== [],
                    default => false,
                };
        }

        if ($actor->province_code === null || $target->province_code !== $actor->province_code) {
            return false;
        }

        if ($actor->roleScopeLevel() === 'province') {
            return match ($targetLevel) {
                'province' => $target->commune_code === null && $target->managedVillageCodes() === [],
                'commune' => $target->commune_code !== null && $target->managedVillageCodes() === [],
                default => false,
            };
        }

        if ($actor->commune_code === null || $target->commune_code !== $actor->commune_code) {
            return false;
        }

        if ($targetLevel === 'commune') {
            return $target->managedVillageCodes() === [];
        }

        return $targetLevel === 'village'
            && $target->managedVillageCodes() !== []
            && ($actor->roleScopeLevel() !== 'village'
                || array_diff($target->managedVillageCodes(), $actor->managedVillageCodes()) === []);
    }
    // return cấp cs thể quản lí của user htai
    private static function managedLevels(User $actor): array
    {
        $level = $actor->roleScopeLevel();
        // level ko được null và tồn tại trong mảng
        abort_unless($level !== null && array_key_exists($level, self::CHILD_LEVELS), 403);
        // cấp có quyền qli ngang hàng 
        $sameLevel = in_array($level, ['province', 'commune'], true) ? [$level] : [];
        // trải phangr như flatmap trong java
        return [...$sameLevel, ...self::CHILD_LEVELS[$level]];
    }

    //  check level scope , nếu null thì phải có role nó hợp với cái level nào , đề phòng data lỗi gì 
    private static function constrainToTerritorialLevel(Builder $builder, string $scopeLevel): void
    {
        $builder->where('scope_level', $scopeLevel)
            ->orWhere(function ($legacy) use ($scopeLevel): void {
                $legacy->whereNull('scope_level')
                    ->whereHas('roles', fn ($roles) => $roles->where('roles.scope_level', $scopeLevel));
            });
    }
}
