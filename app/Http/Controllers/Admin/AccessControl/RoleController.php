<?php

namespace App\Http\Controllers\Admin\AccessControl;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\AccessPermission;
use App\Support\DelegatedAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function index(Request $request): View
    {
        $actor = $request->user();
        $query = Role::withCount('users')
            ->with('permissions')
            ->where('guard_name', 'web');
        $manageableLevels = DelegatedAccess::manageableRoleLevels($actor);
        $query->where(function ($builder) use ($actor, $manageableLevels): void {
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

        $roles = $query
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

        $rolesByScope = $roles->groupBy('scope_level');

        return view('admin.access-control.roles.index', compact('roles', 'rolesByScope', 'actor'));
    }

    public function members(Request $request, Role $role): View
    {
        abort_unless($this->canManageRole($request->user(), $role), 403);

        $members = $role->users()
            ->with(['province:code,name', 'commune:code,name', 'villages:code,name'])
            ->orderBy('users.name')
            ->paginate(20);

        return view('admin.access-control.roles.members', compact('role', 'members'));
    }

    public function create(Request $request): View
    {
        $actor = $request->user();
        $scopeLevels = DelegatedAccess::manageableRoleLevels($actor);
        $scopeLevel = old('scope_level', $this->defaultScopeLevel($actor, $scopeLevels));

        return view('admin.access-control.roles.create', [
            'role' => new Role(['guard_name' => 'web']),
            'actor' => $actor,
            'scopeLevels' => $scopeLevels,
            'permissions' => $this->manageablePermissions($actor, $scopeLevels),
            'scopeLevel' => $scopeLevel,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $data = $request->validate($this->rules($actor));
        $permissionIds = $data['permissions'] ?? [];
        $scopeLevel = $data['scope_level'];
        $permissionIds = $this->expandPermissionGroups(
            $scopeLevel,
            $permissionIds,
            $data['permission_groups'] ?? []
        );
        unset($data['permissions']);
        unset($data['permission_groups']);
        unset($data['scope_level']);
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

        return to_route('admin.roles.index')->with('success', 'Đã tạo nhóm người dùng.');
    }

    public function edit(Request $request, Role $role): View
    {
        abort_unless($this->canManageRole($request->user(), $role), 403);
        $actor = $request->user();

        return view('admin.access-control.roles.edit', [
            'role' => $role->load('permissions')->loadCount('users'),
            'actor' => $actor,
            'scopeLevels' => $role->name === Role::SYSTEM_ADMIN
                ? []
                : DelegatedAccess::manageableRoleLevels($actor),
            'permissions' => $role->name === Role::SYSTEM_ADMIN
                ? Permission::where('guard_name', 'web')->orderBy('name')->get()
                : $this->manageablePermissions($actor, DelegatedAccess::manageableRoleLevels($actor)),
            'scopeLevel' => old('scope_level', $role->scope_level === 'global'
                ? $this->defaultScopeLevel($actor, DelegatedAccess::manageableRoleLevels($actor))
                : $role->scope_level),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($this->canManageRole($actor, $role), 403);

        $data = $request->validate($this->rules($actor, $role));
        $permissionIds = $data['permissions'] ?? [];
        $scopeLevel = $role->name === Role::SYSTEM_ADMIN
            ? 'global'
            : $data['scope_level'];
        $permissionIds = $this->expandPermissionGroups(
            $scopeLevel,
            $permissionIds,
            $data['permission_groups'] ?? []
        );
        unset($data['permissions']);
        unset($data['permission_groups']);
        unset($data['scope_level']);
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

        return to_route('admin.roles.index')->with('success', 'Đã cập nhật nhóm người dùng.');
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        abort_unless($this->canManageRole($request->user(), $role), 403);

        if ($role->name === Role::SYSTEM_ADMIN) {
            return back()->withErrors(['role' => 'Không thể xóa nhóm người dùng quản trị hệ thống.']);
        }

        if ($role->users()->where('users.is_active', true)->exists()) {
            return back()->withErrors([
                'role' => 'Không thể xóa nhóm khi nhóm này còn tài khoản đang hoạt động. Hãy thu hồi các tài khoản trước.',
            ]);
        }

        DB::transaction(function () use ($role): void {
            $role->users()->detach();
            $role->delete();
        });

        return to_route('admin.roles.index')->with('success', 'Đã xóa nhóm người dùng.');
    }

    private function rules(User $actor, ?Role $role = null): array
    {
        $permissionIds = $this->manageablePermissions(
            $actor,
            DelegatedAccess::manageableRoleLevels($actor)
        )->modelKeys();
        $ownerId = $role?->created_by
            ?? ($actor->hasRole(Role::SYSTEM_ADMIN) ? null : $actor->id);

        return [
            'name' => [
                'required',
                'string',
                'max:50',
                Rule::unique('roles', 'name')
                    ->where(fn ($query) => $query
                        ->where('guard_name', 'web')
                        ->where('created_by', $ownerId))
                    ->ignore($role?->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'scope_level' => $role?->name === Role::SYSTEM_ADMIN
                ? ['prohibited']
                : ['required', Rule::in(DelegatedAccess::manageableRoleLevels($actor))],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => [
                'integer',
                'distinct',
                Rule::exists('permissions', 'id')
                    ->where('guard_name', 'web')
                    ->whereIn('id', $permissionIds),
            ],
            'permission_groups' => ['sometimes', 'array'],
            'permission_groups.*' => [Rule::in(['households'])],
        ];
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

    private function manageablePermissions(User $actor, array $scopeLevels)
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
        $requiredNames = AccessPermission::withPrerequisites($permissionNames);
        $requiredNames = array_values(array_unique($requiredNames));
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

    private function defaultScopeLevel(User $actor, array $scopeLevels): string
    {
        $preferred = $actor->hasRole(Role::SYSTEM_ADMIN)
            ? 'province'
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

    private function canManageRole(User $actor, Role $role): bool
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
}
