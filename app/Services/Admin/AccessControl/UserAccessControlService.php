<?php

namespace App\Services\Admin\AccessControl;

use App\Models\Commune;
use App\Models\Province;
use App\Models\Role;
use App\Models\User;
use App\Models\Village;
use App\Support\AccessPermission;
use App\Support\DelegatedAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserAccessControlService
{
    public function provincesForFilter(User $actor): Collection
    {
        $query = Province::query()->orderBy('name');
        if (! in_array($actor->roleScopeLevel(), ['global'], true)
            && ! $actor->hasRole(Role::SYSTEM_ADMIN)) {
            $query->where('code', $actor->province_code);
        }

        return $query->get(['code', 'name']);
    }

    public function indexData(User $actor, array $filter, Collection $provinces, int $perPage = 10): array
    {
        $isSystemAdmin = $actor->hasRole(Role::SYSTEM_ADMIN);
        $showOrganizationTree = $includeOrganizationData && (
            ($isSystemAdmin && $actor->can(AccessPermission::VIEW_ORGANIZATION_ACCOUNTS))
            || (! $isSystemAdmin && $actor->canManageSubordinateUsers())
        );
        $unitProvinces = collect();
        $unitProvinceCounts = collect();
        $unitRootType = 'province';
        $unitRootCount = 0;

        if ($includeOrganizationData && $showOrganizationTree) {
            if ($isSystemAdmin) {
                $unitProvinces = Province::query()->orderBy('name')->get(['code', 'name']);
                $locatedAccounts = User::query()
                    ->where('users.created_by', $actor->id)
                    ->whereDoesntHave('roles', fn (Builder $roles) => $roles->where('roles.name', Role::SYSTEM_ADMIN));
                $unitProvinceCounts = (clone $locatedAccounts)
                    ->select('province_code')
                    ->selectRaw('COUNT(*) AS account_count')
                    ->whereNotNull('province_code')
                    ->groupBy('province_code')
                    ->pluck('account_count', 'province_code');
            } elseif ($actor->roleScopeLevel() === 'global') {
                $unitProvinces = Province::query()->orderBy('name')->get(['code', 'name']);
                $unitProvinceCounts = (clone $this->manageableUsers($actor))
                    ->select('province_code')
                    ->selectRaw('COUNT(*) AS account_count')
                    ->whereNotNull('province_code')
                    ->groupBy('province_code')
                    ->pluck('account_count', 'province_code');
            } elseif ($actor->roleScopeLevel() === 'province') {
                $unitProvinces = Province::query()
                    ->where('code', $actor->province_code)
                    ->get(['code', 'name']);
            } elseif ($actor->roleScopeLevel() === 'commune') {
                $unitRootType = 'commune';
                $unitProvinces = Commune::query()
                    ->where('code', $actor->commune_code)
                    ->get(['code', 'name', 'province_code']);
            }

            if (! $isSystemAdmin) {
                $unitRootCount = $this->manageableUsers($actor)->count();
            }
        }
        $provinceCode = $filter['province_code'] ?? $actor->province_code;
        $communeCode = $filter['commune_code'] ?? (
            $actor->roleScopeLevel() === 'commune' ? $actor->commune_code : null
        );
        $villageCode = $filter['village_code'] ?? null;
        $query = $this->manageableUsers($actor)
            ->with(['roles.permissions', 'villages', 'commune', 'province']);

        if (! $isSystemAdmin && $actor->roleScopeLevel() === 'province') {
            $query->where(function (Builder $accounts) use ($actor): void {
                $accounts->where('users.scope_level', 'commune')
                    ->orWhere(function (Builder $provinceAccounts) use ($actor): void {
                        $provinceAccounts->where('users.scope_level', 'province')
                            ->where('users.created_by', $actor->id);
                    });
            });
        }

        if (filled($provinceCode)) {
            $query->where('province_code', $provinceCode);
        }
        if (filled($communeCode)) {
            $query->where('commune_code', $communeCode);
        }
        if (filled($villageCode)) {
            $query->whereHas('villages', fn (Builder $villages) => $villages->where('villages.code', $villageCode));
        }

        if (filled($filter['q'] ?? null)) {
            $search = trim((string) $filter['q']);
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }

        if (filled($filter['role_id'] ?? null)) {
            $query->whereHas('roles', fn (Builder $roles) => $roles
                ->where('roles.id', (int) $filter['role_id'])
                ->where('roles.created_by', $actor->id));
        }

        $search = trim((string) ($filter['q'] ?? ''));
        $roleId = $filter['role_id'] ?? null;
        $isFiltering = filled($provinceCode) || filled($communeCode) || filled($villageCode) || filled($roleId) || $search !== '';
        $managementPriority = DB::table('model_has_roles as account_model_roles')
            ->join('roles as account_roles', 'account_roles.id', '=', 'account_model_roles.role_id')
            ->join('role_has_permissions as account_role_permissions', 'account_role_permissions.role_id', '=', 'account_roles.id')
            ->join('permissions as account_permissions', 'account_permissions.id', '=', 'account_role_permissions.permission_id')
            ->where('account_model_roles.model_type', User::class)
            ->whereIn('account_roles.scope_level', ['province', 'commune'])
            ->whereIn('account_permissions.name', [
                AccessPermission::MANAGE_USER_ACCOUNTS,
                AccessPermission::MANAGE_ACCESS_CONTROL,
            ])
            ->select('account_model_roles.model_id')
            ->selectRaw(
                'MIN(CASE WHEN account_roles.scope_level = ? THEN 0 ELSE 1 END) AS priority',
                ['province'],
            )
            ->groupBy('account_model_roles.model_id');

        $query->leftJoinSub(
            $managementPriority,
            'user_management_priority',
            fn ($join) => $join->on('users.id', '=', 'user_management_priority.model_id'),
        )->select('users.*')
            ->orderByRaw('COALESCE(user_management_priority.priority, 2)')
            ->orderBy('users.name');

        $allUsers = $query
            ->paginate(min(max($perPage, 1), 100))
            ->withQueryString();
        $usersForView = $allUsers->getCollection();
        $totalUserCount = $allUsers->total();
        $this->addStatusManagementFlags($actor, $usersForView);
        $this->addPasswordResetFlags($actor, $usersForView);
        $hierarchy = [];
        $noLocationUsers = collect();

        $usersForHierarchy = $includeOrganizationData && ! $showOrganizationTree
            ? $allUsers
            : collect();
        foreach ($usersForHierarchy as $user) {
            $provinceName = $user->province?->name;
            $provinceCode = $user->province_code;
            $communeName = $user->commune?->name;
            $communeCode = $user->commune_code;
            $villages = $user->villages;

            if (! $provinceName) {
                $noLocationUsers->push($user);

                continue;
            }

            if (! isset($hierarchy[$provinceCode])) {
                $hierarchy[$provinceCode] = [
                    'name' => $provinceName,
                    'code' => $provinceCode,
                    'communes' => [],
                    'direct_users' => collect(),
                ];
            }

            if ($communeName && $communeCode) {
                if (! isset($hierarchy[$provinceCode]['communes'][$communeCode])) {
                    $hierarchy[$provinceCode]['communes'][$communeCode] = [
                        'name' => $communeName,
                        'code' => $communeCode,
                        'villages' => [],
                        'direct_users' => collect(),
                    ];
                }

                if ($villages->isNotEmpty()) {
                    foreach ($villages as $village) {
                        $vCode = $village->code;
                        if (! isset($hierarchy[$provinceCode]['communes'][$communeCode]['villages'][$vCode])) {
                            $hierarchy[$provinceCode]['communes'][$communeCode]['villages'][$vCode] = [
                                'name' => $village->name,
                                'code' => $vCode,
                                'users' => collect(),
                            ];
                        }
                        $hierarchy[$provinceCode]['communes'][$communeCode]['villages'][$vCode]['users']->push($user);
                    }
                } else {
                    $hierarchy[$provinceCode]['communes'][$communeCode]['direct_users']->push($user);
                }
            } else {
                $hierarchy[$provinceCode]['direct_users']->push($user);
            }
        }

        if ($includeOrganizationData) {
            uasort($hierarchy, fn ($a, $b) => strcmp($a['name'], $b['name']));
            foreach ($hierarchy as &$province) {
                uasort($province['communes'], fn ($a, $b) => strcmp($a['name'], $b['name']));
            }
        }

        return compact(
            'hierarchy',
            'noLocationUsers',
            'allUsers',
            'totalUserCount',
            'provinces',
            'provinceCode',
            'communeCode',
            'villageCode',
            'isFiltering',
            'showOrganizationTree',
            'unitProvinces',
            'unitProvinceCounts',
            'unitRootType',
            'unitRootCount',
            'roleId',
        );
    }

    public function rolesForForm(User $actor, array $accountLevels): Collection
    {
        $actorPermissionNames = $actor->hasRole(Role::SYSTEM_ADMIN)
            ? []
            : $actor->permissionNames();
        $roles = $this->availableRoles($actor, $accountLevels, $actorPermissionNames);
        $roles->each(fn (Role $role) => $role
            ->setAttribute(
                'assignable_scope_levels',
                $this->assignableScopeLevels($role, $accountLevels, $actor, $actorPermissionNames)
            )
            ->setAttribute('is_primary_admin', $this->isPrimaryAdminRole($role))
            ->setAttribute('manages_all_villages', $this->roleManagesAllVillages($role))
            ->setAttribute('uses_village_scope', $this->roleUsesVillageScope($role)));

        return $roles;
    }

    public function isPrimaryAdminRole(Role $role): bool
    {
        return match ($role->scope_level) {
            'province' => $this->roleHasPermission($role, AccessPermission::MANAGE_SCHOOLS)
                && $this->roleHasPermission($role, AccessPermission::MANAGE_USER_ACCOUNTS),
            'commune' => $this->roleHasPermission($role, AccessPermission::MANAGE_VILLAGES)
                && $this->roleHasPermission($role, AccessPermission::MANAGE_USER_ACCOUNTS),
            default => false,
        };
    }

    public function roleManagesAllVillages(Role $role): bool
    {
        return $this->roleHasPermission($role, AccessPermission::MANAGE_VILLAGES);
    }

    public function roleUsesVillageScope(Role $role): bool
    {
        if ($this->roleManagesAllVillages($role)) {
            return false;
        }

        foreach (AccessPermission::villageScopedPermissions() as $permission) {
            if ($this->roleHasPermission($role, $permission)) {
                return true;
            }
        }

        return false;
    }

    private function roleHasPermission(Role $role, string $permission): bool
    {
        $permissions = $role->relationLoaded('permissions')
            ? $role->permissions->pluck('name')->all()
            : $role->permissions()->pluck('name')->all();

        return array_intersect(AccessPermission::permissionSources($permission), $permissions) !== [];
    }

    public function hasAvailablePrimaryAdminScope(User $actor, string $scopeLevel, ?User $user = null): bool
    {
        if ($scopeLevel === 'province') {
            return $this->manageableProvinces($actor, $user)->isNotEmpty();
        }

        if ($scopeLevel !== 'commune') {
            return false;
        }

        $query = Commune::query();
        if (! $actor->hasRole(Role::SYSTEM_ADMIN) && $actor->roleScopeLevel() !== 'global') {
            if ($actor->roleScopeLevel() === 'province') {
                $query->where('province_code', $actor->province_code);
            } elseif ($actor->roleScopeLevel() === 'commune') {
                $query->where('code', $actor->commune_code);
            } else {
                return false;
            }
        }

        $occupied = DelegatedAccess::occupiedTerritorialCodes('commune', $user?->id);
        if ($occupied !== []) {
            $query->whereNotIn('code', $occupied);
        }

        return $query->exists();
    }

    public function availableRoles(
        User $actor,
        array $accountLevels,
        ?array $actorPermissionNames = null
    ): Collection
    {
        $query = Role::query()
            ->with('permissions')
            ->where('guard_name', 'web')
            ->where('created_by', $actor->id)
            ->where('name', '!=', Role::SYSTEM_ADMIN);

        $actorPermissionNames ??= $actor->hasRole(Role::SYSTEM_ADMIN)
            ? []
            : $actor->permissionNames();

        return $query->orderBy('name')->get()
            ->filter(function (Role $role) use ($actor, $accountLevels, $actorPermissionNames): bool {
                foreach ($accountLevels as $accountLevel) {
                    if ($this->roleCanBeAssignedAtLevel(
                        $role,
                        $accountLevel,
                        $actor,
                        $actorPermissionNames
                    )) {
                        return true;
                    }
                }

                return false;
            })
            ->values();
    }

    public function rolesForAccountFilter(User $actor, array $filters = []): Collection
    {
        $provinceCode = $filters['province_code'] ?? $actor->province_code;
        $communeCode = $filters['commune_code'] ?? (
            $actor->roleScopeLevel() === 'commune' ? $actor->commune_code : null
        );
        $villageCode = $filters['village_code'] ?? null;
        $accounts = $this->manageableUsers($actor);

        if (filled($provinceCode)) {
            $accounts->where('users.province_code', $provinceCode);
        }
        if (filled($communeCode)) {
            $accounts->where('users.commune_code', $communeCode);
        }
        if (filled($villageCode)) {
            $accounts->whereHas('villages', fn (Builder $villages) => $villages
                ->where('villages.code', $villageCode));
        }

        $accountIds = $accounts->select('users.id');

        return Role::query()
            ->where('guard_name', 'web')
            ->where('created_by', $actor->id)
            ->whereHas('users', fn (Builder $users) => $users->whereIn('users.id', $accountIds))
            ->orderBy('name')
            ->get(['roles.id', 'roles.name']);
    }

    public function manageableProvinces(User $actor, ?User $user = null): Collection
    {
        $query = Province::query()->orderBy('name');
        if ($actor->roleScopeLevel() === 'global' || $actor->hasRole(Role::SYSTEM_ADMIN)) {
            $occupied = DelegatedAccess::occupiedTerritorialCodes('province', $user?->id);
            if ($occupied !== []) {
                $query->whereNotIn('code', $occupied);
            }
        } else {
            $query->where('code', $actor->province_code);
        }

        return $query->get(['code', 'name']);
    }

    public function selectedLocations(?User $user = null): array
    {
        $provinceCode = old('province_code', $user?->province_code);
        $communeCode = old('commune_code', $user?->commune_code);
        $villageCodes = old('village_codes', $user?->managedVillageCodes() ?? []);
        $villageCodes = array_values(array_unique(array_map('strval', (array) $villageCodes)));

        return [
            'province' => $provinceCode ? Province::where('code', $provinceCode)->value('name') : null,
            'commune' => $communeCode ? Commune::where('code', $communeCode)->value('name') : null,
            'villages' => $villageCodes === [] ? [] : Village::whereIn('code', $villageCodes)->pluck('name', 'code')->all(),
            'province_code' => $provinceCode,
            'commune_code' => $communeCode,
            'village_codes' => $villageCodes,
        ];
    }

    public function canManageUser(User $actor, User $target): bool
    {
        if ($target->created_by !== $actor->id) {
            return false;
        }

        $targetLevel = $target->roleScopeLevel();
        if ($targetLevel === null) {
            return $actor->hasRole(Role::SYSTEM_ADMIN);
        }
        if ($targetLevel === 'global') {
            return $actor->hasRole(Role::SYSTEM_ADMIN) && $target->hasRole(Role::SYSTEM_ADMIN) === false;
        }

        if (! in_array($targetLevel, DelegatedAccess::manageableAccountLevels($actor), true)) {
            return false;
        }

        if ($actor->hasRole(Role::SYSTEM_ADMIN) && $targetLevel === 'province'
            && ! $this->hasPrimaryAdminRole($target, 'province')) {
            return false;
        }

        if (! $actor->hasRole(Role::SYSTEM_ADMIN)) {
            $assignableRoleIds = $this->availableRoles($actor, [$targetLevel])->modelKeys();
            if ($target->roles()->whereNotIn('roles.id', $assignableRoleIds)->exists()) {
                return false;
            }
        }

        return DelegatedAccess::userIsInActorArea($actor, $target, $targetLevel);
    }

    public function canViewUser(User $actor, User $target): bool
    {
        if ($target->created_by !== $actor->id) {
            return false;
        }

        if ($actor->hasRole(Role::SYSTEM_ADMIN)) {
            return true;
        }
        if (! $actor->can('view-users')) {
            return false;
        }

        $targetLevel = $target->roleScopeLevel();
        if ($targetLevel === null || ! in_array($targetLevel, DelegatedAccess::manageableAccountLevels($actor), true)) {
            return $actor->is($target);
        }

        return DelegatedAccess::userIsInActorArea($actor, $target, $targetLevel);
    }

    public function canEditUserConfiguration(User $actor, User $target): bool
    {
        if (
            ! $actor->hasRole(Role::SYSTEM_ADMIN)
            && ($actor->roleScopeLevel() === 'global' || ! $actor->can('update-users'))
        ) {
            return false;
        }

        return $this->canManageUser($actor, $target);
    }

    public function canEditUserConfigurations(User $actor): bool
    {
        return $actor->hasRole(Role::SYSTEM_ADMIN)
            || ($actor->roleScopeLevel() !== 'global' && $actor->can('update-users'));
    }

    public function canResetUserPassword(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return false;
        }

        if (! $actor->hasRole(Role::SYSTEM_ADMIN) && ! $actor->can('update-users')) {
            return false;
        }

        return $this->canManageUser($actor, $target);
    }

    public function resetPassword(User $user): void
    {
        $user->password = User::TEMPORARY_PASSWORD;
        $user->save();
    }

    public function canChangeUserStatus(User $actor, User $target, bool $isActive): bool
    {
        $targetLevel = $target->roleScopeLevel();
        if (! in_array($targetLevel, $this->statusTargetLevels($actor), true)) {
            return false;
        }

        $ability = $isActive ? 'update-users' : 'delete-users';
        if (! $actor->hasRole(Role::SYSTEM_ADMIN) && ! $actor->can($ability)) {
            return false;
        }

        return $this->statusTargetIsManageable($actor, $target, $targetLevel);
    }

    private function addStatusManagementFlags(User $actor, SupportCollection $users): void
    {
        $targetLevels = $this->statusTargetLevels($actor);
        $canChangeStatus = $actor->hasRole(Role::SYSTEM_ADMIN)
            || $actor->can('update-users')
            || $actor->can('delete-users');
        foreach ($users as $user) {
            $targetLevel = $user->roleScopeLevel();
            $assignableRoleIds = in_array($targetLevel, $targetLevels, true)
                && $canManageAccounts
                && ! $actor->hasRole(Role::SYSTEM_ADMIN)
                ? $this->availableRoles($actor, [$targetLevel])->modelKeys()
                : null;
            $canChangeStatus = in_array($targetLevel, $targetLevels, true)
                && $canManageAccounts
                && $this->statusTargetIsManageable($actor, $user, $targetLevel, $assignableRoleIds);
            $user->setAttribute('can_change_account_status', $canChangeStatus);
        }
    }

    private function addPasswordResetFlags(User $actor, SupportCollection $users): void
    {
        foreach ($users as $user) {
            $user->setAttribute('can_reset_account_password', $this->canResetUserPassword($actor, $user));
        }
    }

    private function statusTargetIsManageable(
        User $actor,
        User $target,
        string $targetLevel,
        ?array $assignableRoleIds
    ): bool {
        if ($target->created_by !== $actor->id) {
            return false;
        }

        $target->loadMissing(['roles', 'villages']);

        $villageCodes = $target->villages->pluck('code')->all();
        if ($actor->hasRole(Role::SYSTEM_ADMIN)) {
            if ($targetLevel === 'global') {
                return ! $target->hasRole(Role::SYSTEM_ADMIN)
                    && $target->province_code === null
                    && $target->commune_code === null
                    && $villageCodes === [];
            }

            return $target->province_code !== null
                && $targetLevel === 'province'
                && $target->commune_code === null
                && $villageCodes === []
                && $this->hasPrimaryAdminRole($target, 'province');
        }

        if ($actor->roleScopeLevel() === 'global') {
            return $targetLevel === 'province'
                && $target->province_code !== null
                && $target->commune_code === null
                && $villageCodes === []
                && $this->hasPrimaryAdminRole($target, 'province');
        }

        if ($target->province_code !== $actor->province_code || $actor->province_code === null) {
            return false;
        }

        return match ($actor->roleScopeLevel()) {
            'province' => ($targetLevel === 'province'
                    && $target->commune_code === null
                    && $villageCodes === [])
                || ($targetLevel === 'commune'
                    && $target->commune_code !== null
                    && $villageCodes === []),
            'commune' => $targetLevel === 'commune'
                && $target->commune_code === $actor->commune_code
                && $target->commune_code !== null
                && ($actor->managedVillageCodes() === []
                    || ($villageCodes !== []
                        && array_diff($villageCodes, $actor->managedVillageCodes()) === [])),
            default => false,
        };
    }

    private function hasPrimaryAdminRole(User $user, string $scopeLevel): bool
    {
        $user->loadMissing('roles.permissions');

        return $user->roles->contains(fn (Role $role): bool => $role->scope_level === $scopeLevel
            && $this->isPrimaryAdminRole($role));
    }

    private function statusTargetLevels(User $actor): array
    {
        if ($actor->hasRole(Role::SYSTEM_ADMIN)) {
            return ['global', 'province'];
        }

        return match ($actor->roleScopeLevel()) {
            'global' => ['province'],
            'province' => ['province', 'commune'],
            'commune' => ['commune'],
            default => [],
        };
    }

    public function validateRoleForScope(Role $role, string $scopeLevel, User $actor): void
    {
        $actorPermissionNames = $actor->hasRole(Role::SYSTEM_ADMIN)
            ? []
            : $actor->permissionNames();

        if (! $this->roleCanBeAssignedAtLevel($role, $scopeLevel, $actor, $actorPermissionNames)) {
            throw ValidationException::withMessages([
                'role_id' => 'Nhóm này không áp dụng cho cấp hoạt động đã chọn.',
            ]);
        }
    }

    public function create(User $actor, array $data, array $scope, string $scopeLevel, Role $role): User
    {
        $this->assertTerritorialAdminIsUnique($role, $scope, null);

        return DB::transaction(function () use ($data, $scope, $scopeLevel, $role, $actor): User {
            $user = new User;
            $this->fillUser($user, $data, $scope, $scopeLevel);
            $user->created_by = $actor->id;
            $user->save();
            $user->roles()->sync([$role->id => ['assigned_by' => $actor->id]]);
            $user->villages()->sync($scope['village_codes'] ?? []);

            return $user->load(['roles', 'villages']);
        });
    }

    public function update(User $actor, User $user, array $data, array $scope, string $scopeLevel, Role $role): void
    {
        $this->assertTerritorialAdminIsUnique($role, $scope, $user->id);

        DB::transaction(function () use ($data, $scope, $scopeLevel, $role, $user, $actor): void {
            $this->fillUser($user, $data, $scope, $scopeLevel);
            $user->save();
            $user->roles()->sync([$role->id => ['assigned_by' => $actor->id]]);
            $user->villages()->sync($scope['village_codes'] ?? []);
        });
    }

    public function updateStatus(User $user, bool $isActive): void
    {
        if ($isActive && ! $user->is_active) {
            $user->loadMissing('roles.permissions');
            foreach ($user->roles as $role) {
                if ($this->isPrimaryAdminRole($role)) {
                    $this->assertTerritorialAdminIsUnique($role, [
                        'province_code' => $user->province_code,
                        'commune_code' => $user->commune_code,
                    ], $user->id);
                }
            }
        }

        $user->is_active = $isActive;
        $user->save();
    }

    public function authorizeManagedScope(User $actor, string $scopeLevel, array $scope): void
    {
        abort_unless(
            DelegatedAccess::scopeIsAllowed($actor, $scopeLevel, $scope, $scope['village_codes'] ?? []),
            403
        );
    }

    private function roleCanBeAssignedAtLevel(
        Role $role,
        string $accountLevel,
        User $actor,
        array $actorPermissionNames
    ): bool
    {
        if ($role->scope_level !== $accountLevel || $role->created_by !== $actor->id) {
            return false;
        }

        if (! $actor->hasRole(Role::SYSTEM_ADMIN)
            && ! DelegatedAccess::roleBelongsToActorArea($actor, $role)) {
            return false;
        }

        $permissionNames = $role->permissions->pluck('name')->all();
        if (array_diff($permissionNames, AccessPermission::permissionsForScope($accountLevel)) !== []) {
            return false;
        }
        if (
            ! $actor->hasRole(Role::SYSTEM_ADMIN)
            && $accountLevel === $actor->roleScopeLevel()
            && array_diff($permissionNames, $actorPermissionNames) !== []
        ) {
            return false;
        }

        if (
            $accountLevel === 'province'
            && ($actor->hasRole(Role::SYSTEM_ADMIN) || $actor->roleScopeLevel() === 'global')
            && ! $this->isPrimaryAdminRole($role)
        ) {
            return false;
        }

        return true;
    }

    private function assignableScopeLevels(
        Role $role,
        array $accountLevels,
        User $actor,
        array $actorPermissionNames
    ): array
    {
        return array_values(array_filter(
            $accountLevels,
            fn (string $accountLevel): bool => $this->roleCanBeAssignedAtLevel(
                $role,
                $accountLevel,
                $actor,
                $actorPermissionNames
            )
        ));
    }

    private function manageableUsers(User $actor): Builder
    {
        $levels = DelegatedAccess::manageableAccountLevels($actor);
        if ($actor->hasRole(Role::SYSTEM_ADMIN)) {
            return User::query()
                ->where('users.created_by', $actor->id)
                ->where('users.id', '!=', $actor->id)
                ->whereDoesntHave('roles', fn (Builder $roles) => $roles->where('roles.name', Role::SYSTEM_ADMIN))
                ->where(function (Builder $query): void {
                    $query->where(function (Builder $centralAccounts): void {
                        $centralAccounts->where('users.scope_level', 'global')
                            ->whereHas('roles', fn (Builder $roles) => $roles
                                ->where('roles.scope_level', 'global')
                                ->where('roles.name', '!=', Role::SYSTEM_ADMIN));
                    })->orWhere(function (Builder $provinceAdmins): void {
                        $provinceAdmins->where('users.scope_level', 'province')
                            ->whereHas('roles', function (Builder $roles): void {
                                $roles->where('roles.scope_level', 'province')
                                    ->where('roles.guard_name', 'web')
                                    ->whereHas('permissions', fn (Builder $permissions) => $permissions
                                        ->whereIn(
                                            'permissions.name',
                                            AccessPermission::permissionSources(AccessPermission::MANAGE_SCHOOLS)
                                        ))
                                    ->whereHas('permissions', fn (Builder $permissions) => $permissions
                                        ->whereIn(
                                            'permissions.name',
                                            AccessPermission::permissionSources(AccessPermission::MANAGE_USER_ACCOUNTS)
                                        ));
                            });
                    })->orWhere(function (Builder $unassignedAccounts): void {
                        $unassignedAccounts
                            ->whereNull('users.scope_level')
                            ->whereDoesntHave('roles');
                    });
                });
        }

        $query = User::query()
            ->whereIn('scope_level', $levels)
            ->where('id', '!=', $actor->id)
            ->where('users.created_by', $actor->id);
        if ($actor->roleScopeLevel() === 'province') {
            $query->where(function (Builder $accounts) use ($actor): void {
                $accounts->where(function (Builder $communeAdmins): void {
                    $communeAdmins->where('users.scope_level', 'commune')
                        ->whereHas('roles', function (Builder $roles): void {
                            $roles->where('roles.scope_level', 'commune')
                                ->where('roles.guard_name', 'web')
                                ->whereHas('permissions', fn (Builder $permissions) => $permissions
                                    ->whereIn(
                                        'permissions.name',
                                        AccessPermission::permissionSources(AccessPermission::MANAGE_VILLAGES)
                                    ))
                                ->whereHas('permissions', fn (Builder $permissions) => $permissions
                                    ->whereIn(
                                        'permissions.name',
                                        AccessPermission::permissionSources(AccessPermission::MANAGE_USER_ACCOUNTS)
                                    ));
                        });
                })->orWhere(function (Builder $provinceAccounts) use ($actor): void {
                    $provinceAccounts->where('users.scope_level', 'province')
                        ->where('users.created_by', $actor->id);
                });
            });
        } elseif ($actor->roleScopeLevel() === 'global') {
            $query->where(function (Builder $accounts) use ($actor): void {
                $accounts->where(function (Builder $centralAccounts) use ($actor): void {
                    $centralAccounts->where('users.scope_level', 'global')
                        ->whereHas('roles', fn (Builder $roles) => $roles
                            ->where('roles.created_by', $actor->id)
                            ->where('roles.scope_level', 'global'));
                })->orWhere(function (Builder $provinceAdmins) use ($actor): void {
                    $provinceAdmins->where('users.scope_level', 'province')
                        ->whereHas('roles', function (Builder $roles) use ($actor): void {
                            $roles->where('roles.created_by', $actor->id)
                                ->where('roles.scope_level', 'province')
                                ->where('roles.guard_name', 'web')
                                ->whereHas('permissions', fn (Builder $permissions) => $permissions
                                    ->whereIn(
                                        'permissions.name',
                                        AccessPermission::permissionSources(AccessPermission::MANAGE_SCHOOLS)
                                    ))
                                ->whereHas('permissions', fn (Builder $permissions) => $permissions
                                    ->whereIn(
                                        'permissions.name',
                                        AccessPermission::permissionSources(AccessPermission::MANAGE_USER_ACCOUNTS)
                                    ));
                        });
                });
            });
        }
        if ($actor->roleScopeLevel() === 'commune' && $actor->managedVillageCodes() !== []) {
            $villageCodes = $actor->managedVillageCodes();
            $query->whereHas('villages', fn (Builder $villages) => $villages
                ->whereIn('villages.code', $villageCodes));
        }
        $this->constrainToActorArea($query, $actor);

        return $query;
    }

    private function constrainToActorArea(Builder $query, User $actor): void
    {
        if ($actor->hasRole(Role::SYSTEM_ADMIN)) {
            return;
        }

        if ($actor->roleScopeLevel() === 'global') {
            return;
        }

        $query->where('province_code', $actor->province_code);
        if ($actor->roleScopeLevel() === 'commune') {
            $query->where('commune_code', $actor->commune_code);
        }

        if ($actor->roleScopeLevel() === 'commune' && $actor->managedVillageCodes() !== []) {
            $villageCodes = $actor->managedVillageCodes();
            $query->whereHas('villages', fn (Builder $villages) => $villages
                ->whereIn('villages.code', $villageCodes));
        }
    }

    private function assertTerritorialAdminIsUnique(Role $role, array $scope, ?int $ignoreUserId): void
    {
        if (
            ! $this->isPrimaryAdminRole($role)
            || ! DelegatedAccess::hasActiveTerritorialAdmin($role->scope_level, $scope, $ignoreUserId)
        ) {
            return;
        }

        $field = $role->scope_level === 'province' ? 'province_code' : 'commune_code';
        $message = $role->scope_level === 'province'
            ? 'Tỉnh này đã có tài khoản quản trị đang hoạt động. Mỗi tỉnh chỉ có một quản trị viên.'
            : 'Xã này đã có tài khoản quản trị đang hoạt động. Mỗi xã chỉ có một quản trị viên.';

        throw ValidationException::withMessages([$field => $message]);
    }

    private function fillUser(User $user, array $data, array $scope, string $scopeLevel): void
    {
        $user->name = $data['name'];
        $user->username = $data['username'];
        if (array_key_exists('phone', $data)) {
            $user->phone = $data['phone'];
        }
        foreach (['citizen_id', 'date_of_birth', 'email'] as $field) {
            if (array_key_exists($field, $data)) {
                $user->{$field} = $data[$field] ?: null;
            }
        }
        if (filled($data['password'] ?? null)) {
            $user->password = $data['password'];
        }
        $user->is_active = (bool) ($data['is_active'] ?? true);
        $user->province_code = $scope['province_code'] ?? null;
        $user->commune_code = $scope['commune_code'] ?? null;
        $user->scope_level = $scopeLevel;
    }
}
