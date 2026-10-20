<?php

namespace App\Services\Admin\AccessControl;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\AccessPermission;
use App\Support\DelegatedAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoleManagementService
{
    public function rolesForIndex(User $actor): Collection
    {
        $manageableLevels = DelegatedAccess::manageableRoleLevels($actor);
        // Tính số tài khoản được gán cho từng nhóm.
        $query = Role::withCount([
            'users as users_count' => function ($users) use ($actor): void {
                if (! $actor->hasRole(Role::SYSTEM_ADMIN)) {
                    $this->constrainMembersToActorArea($users, $actor);
                }
            },
        ])
            ->with('permissions')
            ->where('guard_name', 'web')
            ->where(function ($builder) use ($actor, $manageableLevels): void {
                if ($actor->hasRole(Role::SYSTEM_ADMIN)) {
                    $builder->where(function ($centralRoles) use ($manageableLevels): void {
                        $centralRoles->where(function ($createdByCentral) use ($manageableLevels): void {
                            $createdByCentral->whereNull('created_by')
                                ->whereIn('scope_level', $manageableLevels);
                        })->orWhere('name', Role::SYSTEM_ADMIN);
                    });
                } else {
                    $builder->whereIn('scope_level', $manageableLevels)
                        ->where('created_by', $actor->id);
                }
            });

        return $query
            ->orderByRaw(
                "CASE scope_level
                    WHEN 'global' THEN 0
                    WHEN 'province' THEN 1
                    WHEN 'commune' THEN 2
                    WHEN 'village' THEN 3
                    ELSE 4
                END"
            )
            ->orderBy('name')
            ->get();
    }

    public function canManageRole(User $actor, Role $role): bool
    {
        if ($role->guard_name !== 'web') {
            return false;
        }

        if ($actor->hasRole(Role::SYSTEM_ADMIN)) {
            return $role->name === Role::SYSTEM_ADMIN
                || ($role->created_by === null
                    && in_array($role->scope_level, DelegatedAccess::manageableRoleLevels($actor), true));
        }

        return $role->created_by === $actor->id
            && DelegatedAccess::roleBelongsToActorArea($actor, $role);
    }

    public function membersFor(User $actor, Role $role): LengthAwarePaginator
    {
        $query = $role->users()->getQuery();
        if (! $actor->hasRole(Role::SYSTEM_ADMIN)) {
            $this->constrainMembersToActorArea($query, $actor);
        }

        return $query->with(['province:code,name', 'commune:code,name', 'villages:code,name'])
            ->orderBy('users.name')
            ->paginate(5);
    }

    private function constrainMembersToActorArea(Builder $query, User $actor): void
    {
        $scopeLevel = $actor->roleScopeLevel();
        if ($scopeLevel === 'global') {
            return;
        }

        if ($actor->province_code === null) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where('users.province_code', $actor->province_code);

        if (in_array($scopeLevel, ['commune', 'village'], true)) {
            if ($actor->commune_code === null) {
                $query->whereRaw('1 = 0');
                return;
            }

            $query->where('users.commune_code', $actor->commune_code);
        }

        if ($scopeLevel === 'village') {
            $villageCodes = $actor->managedVillageCodes();
            if ($villageCodes === []) {
                $query->whereRaw('1 = 0');
                return;
            }

            $query->where(function (Builder $users) use ($villageCodes): void {
                $users->whereIn('users.village_code', $villageCodes)
                    ->orWhereHas('villages', fn (Builder $villages) => $villages
                        ->whereIn('villages.code', $villageCodes));
            });
        }
    }

    public function manageablePermissions(User $actor, array $scopeLevels): Collection
    {
        $permissionNames = collect($scopeLevels)
            ->flatMap(fn (string $scopeLevel): array => AccessPermission::permissionsForScope($scopeLevel))
            ->unique()
            ->all();

        if (! $actor->hasRole(Role::SYSTEM_ADMIN)) {
            if (! $actor->canManageSubordinateUsers()) {
                $permissionNames = array_values(array_diff($permissionNames, [
                    AccessPermission::MANAGE_USER_ACCOUNTS,
                ]));
            }
            if (! $actor->can('manage-access-control')) {
                $permissionNames = array_values(array_diff($permissionNames, [
                    AccessPermission::MANAGE_ACCESS_CONTROL,
                ]));
            }
        }

        return Permission::where('guard_name', 'web')
            ->whereIn('name', $permissionNames)
            ->orderBy('name')
            ->get();
    }

    public function defaultScopeLevel(User $actor, array $scopeLevels): string
    {
        $preferred = $actor->hasRole(Role::SYSTEM_ADMIN)
            ? 'global'
            : match ($actor->roleScopeLevel()) {
                'province' => 'province',
                'commune' => 'commune',
                'village' => 'village',
                default => $scopeLevels[0] ?? 'province',
            };

        return in_array($preferred, $scopeLevels, true)
            ? $preferred
            : $scopeLevels[0];
    }

    public function create(User $actor, array $data): void
    {
        $permissionIds = $data['permissions'] ?? [];
        $scopeLevel = $data['scope_level'];
        $permissionIds = $this->expandPermissionGroups(
            $scopeLevel,
            $permissionIds,
            $data['permission_groups'] ?? []
        );
        unset($data['permissions'], $data['permission_groups'], $data['scope_level']);
        $permissionIds = $this->permissionIdsForScope($actor, $scopeLevel, $permissionIds);
        $area = DelegatedAccess::roleAreaFor($actor);

        DB::transaction(function () use ($actor, $data, $permissionIds, $area, $scopeLevel): void {
            $role = Role::create([
                ...$data,
                'scope_level' => $scopeLevel,
                ...$area,
                'created_by' => $actor->hasRole(Role::SYSTEM_ADMIN) ? null : $actor->id,
            ]);
            $role->permissions()->sync($permissionIds);
        });
    }

    public function update(User $actor, Role $role, array $data): void
    {
        $permissionIds = $data['permissions'] ?? [];
        $scopeLevel = $role->name === Role::SYSTEM_ADMIN
            ? 'global'
            : $data['scope_level'];
        $permissionIds = $this->expandPermissionGroups(
            $scopeLevel,
            $permissionIds,
            $data['permission_groups'] ?? []
        );
        unset($data['permissions'], $data['permission_groups'], $data['scope_level']);
        $permissionIds = $role->name === Role::SYSTEM_ADMIN
            ? Permission::where('guard_name', 'web')->whereIn('id', $permissionIds)->pluck('id')->all()
            : $this->permissionIdsForScope($actor, $scopeLevel, $permissionIds);
        if ($role->name === Role::SYSTEM_ADMIN) {
            $data['name'] = Role::SYSTEM_ADMIN;
        }

        if ($role->name !== Role::SYSTEM_ADMIN
            && $role->users()->exists()
            && $role->scope_level !== $scopeLevel) {
            throw ValidationException::withMessages([
                'scope_level' => 'Không thể đổi cấp của nhóm đang được tài khoản sử dụng.',
            ]);
        }

        DB::transaction(function () use ($role, $data, $permissionIds, $scopeLevel): void {
            if ($role->name !== Role::SYSTEM_ADMIN) {
                $data['scope_level'] = $scopeLevel;
            }
            $role->update($data);
            $role->permissions()->sync($permissionIds);
        });
    }

    public function delete(Role $role): ?string
    {
        if ($role->name === Role::SYSTEM_ADMIN) {
            return 'Không thể xóa nhóm người dùng quản trị hệ thống.';
        }

        if ($role->users()->where('users.is_active', true)->exists()) {
            return 'Không thể xóa nhóm khi nhóm này còn tài khoản đang hoạt động. Hãy thu hồi các tài khoản trước.';
        }

        DB::transaction(function () use ($role): void {
            $role->users()->detach();
            $role->delete();
        });

        return null;
    }

    private function expandPermissionGroups(string $scopeLevel, array $permissionIds, array $groups): array
    {
        if (! in_array('households', $groups, true)) {
            return $permissionIds;
        }

        if (! in_array($scopeLevel, ['global', 'commune'], true)) {
            throw ValidationException::withMessages([
                'permission_groups' => 'Nhóm quản trị phiếu và nhân khẩu chỉ áp dụng cho cấp xã.',
            ]);
        }

        $householdPermissions = $scopeLevel === 'global'
            ? AccessPermission::householdPermissions()
            : array_intersect(
                AccessPermission::householdPermissions(),
                AccessPermission::permissionsForScope($scopeLevel)
            );
        $definitions = AccessPermission::definitions();
        foreach ($householdPermissions as $name) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['description' => $definitions[$name]]
            );
        }

        $groupPermissionIds = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $householdPermissions)
            ->pluck('id')
            ->all();

        return array_values(array_unique([...$permissionIds, ...$groupPermissionIds]));
    }

    private function permissionIdsForScope(User $actor, string $scopeLevel, array $permissionIds): array
    {
        $permissionNames = Permission::whereIn('id', $permissionIds)->pluck('name')->all();
        $allowedNames = AccessPermission::permissionsForScope($scopeLevel);
        if (
            ! $actor->hasRole(Role::SYSTEM_ADMIN)
            && $scopeLevel === $actor->roleScopeLevel()
        ) {
            $allowedNames = array_intersect($allowedNames, $actor->permissionNames());
        }

        $manageablePermissions = $this->manageablePermissions($actor, [$scopeLevel]);
        $requiredNames = array_values(array_unique(AccessPermission::withPrerequisites($permissionNames)));
        $missingNames = array_diff($requiredNames, $manageablePermissions->pluck('name')->all());
        $sameLevelPermissions = ! $actor->hasRole(Role::SYSTEM_ADMIN)
            && $scopeLevel === $actor->roleScopeLevel()
                ? array_intersect($actor->permissionNames(), AccessPermission::permissionsForScope($scopeLevel))
                : [];
        $duplicatesAllSameLevelPermissions = $sameLevelPermissions !== []
            && array_diff($sameLevelPermissions, $requiredNames) === [];

        if (
            $missingNames !== []
            || array_diff($requiredNames, $allowedNames) !== []
            || $duplicatesAllSameLevelPermissions
        ) {
            throw ValidationException::withMessages([
                'permissions' => $duplicatesAllSameLevelPermissions
                    ? 'Nhóm cùng cấp phải có ít quyền hơn tài khoản tạo nhóm; không thể sao chép toàn bộ quyền.'
                    : 'Nhóm có quyền không phù hợp với cấp hoạt động hoặc vượt quá quyền của tài khoản tạo nhóm.',
            ]);
        }

        return $manageablePermissions
            ->whereIn('name', $requiredNames)
            ->modelKeys();
    }
}
