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
        $showOrganizationTree = ($isSystemAdmin && $actor->can(AccessPermission::VIEW_ORGANIZATION_ACCOUNTS))
            || (! $isSystemAdmin && $actor->canManageSubordinateUsers());
        $unitProvinces = collect();
        $unitProvinceCounts = collect();
        $unitRootType = 'province';
        $unitRootCount = 0;

        if ($showOrganizationTree) {
            if ($isSystemAdmin) {
                $unitProvinces = Province::query()->orderBy('name')->get(['code', 'name']);
                $locatedAccounts = User::query()
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
            $query->whereHas('roles', fn (Builder $roles) => $roles->where('roles.id', (int) $filter['role_id']));
        }

        $search = trim((string) ($filter['q'] ?? ''));
        $roleId = $filter['role_id'] ?? null;
        $isFiltering = filled($provinceCode) || filled($communeCode) || filled($villageCode) || filled($roleId) || $search !== '';
        $query->orderByRaw(
            'CASE
                WHEN EXISTS (
                    SELECT 1
                    FROM model_has_roles AS account_model_roles
                    INNER JOIN roles AS account_roles ON account_roles.id = account_model_roles.role_id
                    INNER JOIN role_has_permissions AS account_role_permissions ON account_role_permissions.role_id = account_roles.id
                    INNER JOIN permissions AS account_permissions ON account_permissions.id = account_role_permissions.permission_id
                    WHERE account_model_roles.model_id = users.id
                        AND account_model_roles.model_type = ?
                        AND account_roles.scope_level = ?
                        AND account_permissions.name IN (?, ?)
                ) THEN 0
                WHEN EXISTS (
                    SELECT 1
                    FROM model_has_roles AS account_model_roles
                    INNER JOIN roles AS account_roles ON account_roles.id = account_model_roles.role_id
                    INNER JOIN role_has_permissions AS account_role_permissions ON account_role_permissions.role_id = account_roles.id
                    INNER JOIN permissions AS account_permissions ON account_permissions.id = account_role_permissions.permission_id
                    WHERE account_model_roles.model_id = users.id
                        AND account_model_roles.model_type = ?
                        AND account_roles.scope_level = ?
                        AND account_permissions.name IN (?, ?)
                ) THEN 1
                ELSE 2
            END',
            [
                User::class,
                'province',
                AccessPermission::MANAGE_USER_ACCOUNTS,
                AccessPermission::MANAGE_ACCESS_CONTROL,
                User::class,
                'commune',
                AccessPermission::MANAGE_USER_ACCOUNTS,
                AccessPermission::MANAGE_ACCESS_CONTROL,
            ],
        )->orderBy('name');

        $allUsers = $query
            ->paginate(min(max($perPage, 1), 100))
            ->withQueryString();
        $usersForView = $allUsers->getCollection();
        $totalUserCount = $allUsers->total();
        $this->addStatusManagementFlags($actor, $usersForView);
        $this->addPasswordResetFlags($actor, $usersForView);
        $hierarchy = [];
        $noLocationUsers = collect();

        foreach ($showOrganizationTree ? collect() : $allUsers as $user) {
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

        uasort($hierarchy, fn ($a, $b) => strcmp($a['name'], $b['name']));
        foreach ($hierarchy as &$province) {
            uasort($province['communes'], fn ($a, $b) => strcmp($a['name'], $b['name']));
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
        $roles = $this->availableRoles($actor, $accountLevels);
        $roles->each(fn (Role $role) => $role->setAttribute(
            'assignable_scope_levels',
            $this->assignableScopeLevels($role, $accountLevels, $actor)
        ));

        return $roles;
    }

    public function availableRoles(User $actor, array $accountLevels): Collection
    {
        $query = Role::query()
            ->with('permissions')
            ->where('guard_name', 'web')
            ->where('name', '!=', Role::SYSTEM_ADMIN);

        if (! $actor->hasRole(Role::SYSTEM_ADMIN)) {
            $query->where('created_by', $actor->id);
        }

        return $query->orderBy('name')->get()
            ->filter(function (Role $role) use ($actor, $accountLevels): bool {
                foreach ($accountLevels as $accountLevel) {
                    if ($this->roleCanBeAssignedAtLevel($role, $accountLevel, $actor)) {
                        return true;
                    }
                }

                return false;
            })
            ->values();
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

        if (! $actor->hasRole(Role::SYSTEM_ADMIN)) {
            $assignableRoleIds = $this->availableRoles($actor, [$targetLevel])->modelKeys();
            if ($target->roles()->whereNotIn('roles.id', $assignableRoleIds)->exists()) {
                return false;
            }
        }

        return DelegatedAccess::userIsInActorArea($actor, $target, $targetLevel);
    }

    public function canResetUserPassword(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return false;
        }

        if (! $actor->hasRole(Role::SYSTEM_ADMIN) && ! $actor->canManageSubordinateUsers()) {
            return false;
        }

        return $this->canManageUser($actor, $target);
    }

    public function resetPassword(User $user): void
    {
        $user->password = User::TEMPORARY_PASSWORD;
        $user->save();
    }

    public function canChangeUserStatus(User $actor, User $target): bool
    {
        $targetLevel = $target->roleScopeLevel();
        if (! in_array($targetLevel, $this->statusTargetLevels($actor), true)) {
            return false;
        }

        if (! $actor->hasRole(Role::SYSTEM_ADMIN) && ! $actor->canManageSubordinateUsers()) {
            return false;
        }

        $assignableRoleIds = $actor->hasRole(Role::SYSTEM_ADMIN)
            ? null
            : $this->availableRoles($actor, [$targetLevel])->modelKeys();

        return $this->statusTargetIsManageable($actor, $target, $targetLevel, $assignableRoleIds);
    }

    private function addStatusManagementFlags(User $actor, SupportCollection $users): void
    {
        $targetLevels = $this->statusTargetLevels($actor);
        $canManageAccounts = $actor->hasRole(Role::SYSTEM_ADMIN) || $actor->canManageSubordinateUsers();

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
        $target->loadMissing(['roles', 'villages']);

        // Khóa / kích hoạt tài khoản là thao tác cấp trạng thái, không phải gán vai trò.
        // Vì vậy chỉ cần kiểm tra quyền quản lý theo địa bàn và cấp mục tiêu, không
        // cấm tài khoản cùng cấp vì vai trò của họ không thuộc danh sách role do actor tạo ra.
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
                && $villageCodes === [];
        }

        if ($actor->roleScopeLevel() === 'global') {
            return $targetLevel === 'province'
                && $target->province_code !== null
                && $target->commune_code === null
                && $villageCodes === [];
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
            'commune' => ($targetLevel === 'commune'
                    && $target->commune_code === $actor->commune_code
                    && $target->commune_code !== null
                    && $villageCodes === [])
                || ($targetLevel === 'village'
                    && $target->commune_code === $actor->commune_code
                    && $target->commune_code !== null
                    && $villageCodes !== []),
            default => false,
        };
    }

    private function statusTargetLevels(User $actor): array
    {
        if ($actor->hasRole(Role::SYSTEM_ADMIN)) {
            return ['global', 'province'];
        }

        return match ($actor->roleScopeLevel()) {
            'global' => ['province'],
            'province' => ['province', 'commune'],
            'commune' => ['commune', 'village'],
            default => [],
        };
    }

    public function validateRoleForScope(Role $role, string $scopeLevel, User $actor): void
    {
        if (! $this->roleCanBeAssignedAtLevel($role, $scopeLevel, $actor)) {
            throw ValidationException::withMessages([
                'role_id' => 'Nhóm này không áp dụng cho cấp hoạt động đã chọn.',
            ]);
        }
    }

    public function create(User $actor, array $data, array $scope, string $scopeLevel, Role $role): void
    {
        $this->assertTerritorialAdminIsUnique($actor, $scopeLevel, $scope);

        DB::transaction(function () use ($data, $scope, $scopeLevel, $role, $actor): void {
            $user = new User;
            $this->fillUser($user, $data, $scope, $scopeLevel);
            $user->created_by = $actor->id;
            $user->save();
            $user->roles()->sync([$role->id => ['assigned_by' => $actor->id]]);
            $user->villages()->sync($scope['village_codes'] ?? []);
        });
    }

    public function update(User $actor, User $user, array $data, array $scope, string $scopeLevel, Role $role): void
    {
        DB::transaction(function () use ($data, $scope, $scopeLevel, $role, $user, $actor): void {
            $this->fillUser($user, $data, $scope, $scopeLevel);
            $user->save();
            $user->roles()->sync([$role->id => ['assigned_by' => $actor->id]]);
            $user->villages()->sync($scope['village_codes'] ?? []);
        });
    }

    public function updateStatus(User $user, bool $isActive): void
    {
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

    private function roleCanBeAssignedAtLevel(Role $role, string $accountLevel, User $actor): bool
    {
        if ($role->scope_level !== $accountLevel) {
            return false;
        }

        $permissionNames = $role->permissions->pluck('name')->all();
        if (array_diff($permissionNames, AccessPermission::permissionsForScope($accountLevel)) !== []) {
            return false;
        }

        return $actor->hasRole(Role::SYSTEM_ADMIN) || $role->created_by === $actor->id;
    }

    private function assignableScopeLevels(Role $role, array $accountLevels, User $actor): array
    {
        return array_values(array_filter(
            $accountLevels,
            fn (string $accountLevel): bool => $this->roleCanBeAssignedAtLevel($role, $accountLevel, $actor)
        ));
    }

    private function manageableUsers(User $actor): Builder
    {
        $levels = DelegatedAccess::manageableAccountLevels($actor);
        if ($actor->hasRole(Role::SYSTEM_ADMIN)) {
            return User::query()
                ->whereDoesntHave('roles', fn (Builder $roles) => $roles->where('roles.name', Role::SYSTEM_ADMIN))
                ->where(function (Builder $query): void {
                    $query->whereIn('scope_level', ['global', 'province', 'commune', 'village'])
                        ->orWhere(function (Builder $legacy): void {
                            $legacy->whereNull('scope_level')
                                ->where(function (Builder $unassigned): void {
                                    $unassigned->whereDoesntHave('roles')
                                        ->orWhereHas('roles', fn (Builder $roles) => $roles
                                            ->whereIn('roles.scope_level', ['province', 'commune', 'village'])
                                            ->where('roles.name', '!=', Role::SYSTEM_ADMIN));
                                });
                        });
                });
        }

        $query = User::query()
            ->whereIn('scope_level', $levels)
            ->where('id', '!=', $actor->id);
        if ($actor->roleScopeLevel() === 'global') {
            $query->whereHas('roles', fn (Builder $roles) => $roles->where('roles.created_by', $actor->id));
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
        if (in_array($actor->roleScopeLevel(), ['commune', 'village'], true)) {
            $query->where('commune_code', $actor->commune_code);
        }

        if ($actor->roleScopeLevel() === 'village') {
            $villageCodes = $actor->managedVillageCodes();
            $query->where(function (Builder $builder) use ($villageCodes): void {
                $builder->whereIn('village_code', $villageCodes)
                    ->orWhereHas('villages', fn (Builder $villages) => $villages->whereIn('villages.code', $villageCodes));
            });
        }
    }

    private function assertTerritorialAdminIsUnique(User $actor, string $scopeLevel, array $scope): void
    {
        if (
            (! $actor->hasRole(Role::SYSTEM_ADMIN) && $actor->roleScopeLevel() !== 'global')
            || ! DelegatedAccess::hasActiveTerritorialAdmin($scopeLevel, $scope)
        ) {
            return;
        }

        $field = $scopeLevel === 'province' ? 'province_code' : 'commune_code';
        $message = $scopeLevel === 'province'
            ? 'Tỉnh này đã có tài khoản quản trị đang hoạt động. Mỗi tỉnh chỉ có một quản trị viên.'
            : 'Xã này đã có tài khoản quản trị đang hoạt động. Mỗi xã chỉ có một quản trị viên.';

        throw ValidationException::withMessages([$field => $message]);
    }

    private function fillUser(User $user, array $data, array $scope, string $scopeLevel): void
    {
        $user->name = $data['name'];
        $user->username = $data['username'];
        if (filled($data['password'] ?? null)) {
            $user->password = $data['password'];
        }
        $user->is_active = (bool) ($data['is_active'] ?? true);
        $user->province_code = $scope['province_code'] ?? null;
        $user->commune_code = $scope['commune_code'] ?? null;
        $user->scope_level = $scopeLevel;
    }
}
