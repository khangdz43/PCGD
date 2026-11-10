<?php

namespace App\Services\Admin\AccessControl;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\AccessPermission;
use App\Support\DelegatedAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoleManagementService
{
    public function rolesForIndex(User $actor, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $manageableLevels = DelegatedAccess::manageableRoleLevels($actor);
        $query = Role::withCount([
            'users as total_members_count',
            'users as users_count' => function ($users) use ($actor): void {
                $users->where('users.created_by', $actor->id);
                if (! $actor->hasRole(Role::SYSTEM_ADMIN)) {
                    $this->constrainMembersToActorArea($users, $actor);
                }
            },
        ])
            ->where('guard_name', 'web')
            ->where('name', '!=', Role::SYSTEM_ADMIN)
            ->whereIn('scope_level', $manageableLevels)
            ->where('created_by', $actor->id);

        if (filled($filters['q'] ?? null)) {
            $search = trim((string) $filters['q']);
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if (filled($filters['scope_level'] ?? null)) {
            $query->where('scope_level', $filters['scope_level']);
        }

        return $query
            ->orderByRaw(
                "CASE scope_level
                    WHEN 'global' THEN 0
                    WHEN 'province' THEN 1
                    WHEN 'commune' THEN 2
                    ELSE 4
                END"
            )
            ->orderBy('name')
            ->paginate(min(max($perPage, 1), 100))
            ->withQueryString();
    }

    public function canManageRole(User $actor, Role $role): bool
    {
        if ($role->guard_name !== 'web' || $role->is_system) {
            return false;
        }

        if ($role->name === Role::SYSTEM_ADMIN) {
            return $actor->hasRole(Role::SYSTEM_ADMIN);
        }

        if (! in_array($role->scope_level, DelegatedAccess::manageableRoleLevels($actor), true)
            || ! DelegatedAccess::roleBelongsToActorArea($actor, $role)) {
            return false;
        }

        return $role->created_by === $actor->id;
    }

    public function canViewRole(User $actor, Role $role): bool
    {
        if ($role->guard_name !== 'web'
            || ! in_array($role->scope_level, DelegatedAccess::manageableRoleLevels($actor), true)) {
            return false;
        }

        if ($role->name === Role::SYSTEM_ADMIN) {
            return $actor->hasRole(Role::SYSTEM_ADMIN);
        }

        if (! DelegatedAccess::roleBelongsToActorArea($actor, $role)) {
            return false;
        }

        return $role->created_by === $actor->id;
    }

    public function membersFor(User $actor, Role $role, int $perPage = 5): LengthAwarePaginator
    {
        $query = $role->users()
            ->where('users.created_by', $actor->id)
            ->getQuery();
        if (! $actor->hasRole(Role::SYSTEM_ADMIN)) {
            $this->constrainMembersToActorArea($query, $actor);
        }

        return $query->with(['province:code,name', 'commune:code,name', 'villages:code,name', 'operatingAreas'])
            ->orderBy('users.name')
            ->paginate(min(max($perPage, 1), 100));
    }

    private function constrainMembersToActorArea(Builder $query, User $actor): void
    {
        $scopeLevel = $actor->roleScopeLevel();
        if ($scopeLevel === 'global') {
            return;
        }

        $provinceCodes = $actor->operatingProvinceCodes();
        if ($provinceCodes === []) {
            $query->whereRaw('1 = 0');
            return;
        }

        $communeCodes = $actor->operatingCommuneCodes();
        $villageCodes = $actor->managedVillageCodes();
        $query->whereHas('operatingAreas', function (Builder $areas) use (
            $scopeLevel,
            $provinceCodes,
            $communeCodes,
            $villageCodes,
        ): void {
            $areas->whereIn('province_code', $provinceCodes);
            if ($scopeLevel === 'commune') {
                if ($communeCodes === []) {
                    $areas->whereRaw('1 = 0');
                    return;
                }
                $areas->whereIn('commune_code', $communeCodes);
                if ($villageCodes !== []) {
                    $areas->whereIn('village_code', $villageCodes);
                }
            }
        });
    }

    /**
     * @return Collection<int, Permission>
     */
    public function manageablePermissions(User $actor, array $scopeLevels): Collection
    {
        $permissionNames = collect($scopeLevels)
            ->flatMap(fn (string $scopeLevel): array => AccessPermission::permissionsForScope($scopeLevel))
            ->unique()
            ->all();

        if (! $actor->hasRole(Role::SYSTEM_ADMIN)) {
            $delegatablePermissions = AccessPermission::withPrerequisites($actor->permissionNames());
            $delegatedAdminPermissions = [
                AccessPermission::MANAGE_USER_ACCOUNTS,
                AccessPermission::VIEW_USERS,
                AccessPermission::CREATE_USERS,
                AccessPermission::UPDATE_USERS,
                AccessPermission::DELETE_USERS,
                AccessPermission::MANAGE_ACCESS_CONTROL,
                AccessPermission::VIEW_ROLES,
                AccessPermission::CREATE_ROLES,
                AccessPermission::UPDATE_ROLES,
                AccessPermission::DELETE_ROLES,
            ];
            $permissionNames = array_values(array_diff(
                $permissionNames,
                array_diff($delegatedAdminPermissions, $delegatablePermissions)
            ));
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
                default => $scopeLevels[0] ?? 'province',
            };

        return in_array($preferred, $scopeLevels, true)
            ? $preferred
            : $scopeLevels[0];
    }

    public function create(User $actor, array $data): Role
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
        $role = DB::transaction(function () use ($actor, $data, $permissionIds, $scopeLevel): Role {
            $role = Role::create([
                ...$data,
                'scope_level' => $scopeLevel,
                'guard_name' => config('auth.permission_guard', 'web'),
                'created_by' => $actor->id,
            ]);
            $role->syncPermissions($permissionIds);

            return $role;
        });

        return $role->refresh();
    }

    public function update(User $actor, Role $role, array $data): void
    {
        if ($role->is_system) {
            throw new AuthorizationException(
                'Nhóm người dùng do hệ thống quản lý, không thể chỉnh sửa.'
            );
        }

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
            ? Permission::where('guard_name', 'web')
                ->whereIn('id', $permissionIds)
                ->whereNotIn('name', AccessPermission::mergePermissions())
                ->pluck('id')
                ->all()
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
            $role->syncPermissions($permissionIds);
        });
    }

    public function delete(Role $role): ?string
    {
        if ($role->is_system) {
            return 'Nhóm người dùng do hệ thống quản lý, không thể xóa.';
        }

        if ($role->name === Role::SYSTEM_ADMIN) {
            return 'Không thể xóa nhóm người dùng quản trị hệ thống.';
        }

        if ($role->users()->exists()) {
            return 'Không thể xóa nhóm vẫn còn thành viên. Hãy gỡ toàn bộ thành viên trước khi xóa.';
        }

        DB::transaction(function () use ($role): void {
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
        $isCommuneAdministrator = $scopeLevel === 'commune'
            && in_array(AccessPermission::MANAGE_VILLAGES, $permissionNames, true)
            && in_array(AccessPermission::MANAGE_USER_ACCOUNTS, $permissionNames, true);
        if ($isCommuneAdministrator) {
            $definitions = AccessPermission::definitions();
            $schoolPermission = Permission::firstOrCreate(
                ['name' => AccessPermission::MANAGE_SCHOOLS, 'guard_name' => 'web'],
                ['description' => $definitions[AccessPermission::MANAGE_SCHOOLS]],
            );
            $permissionNames[] = AccessPermission::MANAGE_SCHOOLS;
            $permissionIds[] = $schoolPermission->id;
        }

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
                ? $actor->roles()
                    ->where('roles.scope_level', $scopeLevel)
                    ->get()
                    ->flatMap(fn (Role $role): \Illuminate\Support\Collection => $role->permissions->pluck('name'))
                    ->unique()
                    ->intersect(AccessPermission::permissionsForScope($scopeLevel))
                    ->values()
                    ->all()
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
