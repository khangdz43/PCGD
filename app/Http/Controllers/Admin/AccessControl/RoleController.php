<?php

namespace App\Http\Controllers\Admin\AccessControl;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Admin\AccessControl\RoleManagementService;
use App\Support\DelegatedAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function __construct(private RoleManagementService $roles) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $roles = $this->roles->rolesForIndex($actor);
        $rolesByScope = $roles->groupBy('scope_level');

        return response()->json([
            'data' => $roles->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'scope_level' => $role->scope_level,
                'users_count' => $role->users_count,
                'permissions' => $role->permissions->pluck('name')->values(),
                'can_manage' => $this->roles->canManageRole($actor, $role),
            ])->values(),
        ]);
    }

    public function members(Request $request, Role $role): JsonResponse
    {
        $actor = $request->user();
        abort_unless($this->roles->canManageRole($actor, $role), 403);
        $members = $this->roles->membersFor($actor, $role);

        $members->through(fn (User $member): array => [
            'id' => $member->id,
            'name' => $member->name,
            'username' => $member->username,
            'scope_level' => $member->scope_level,
            'province' => $member->province?->name,
            'commune' => $member->commune?->name,
            'villages' => $member->villages->pluck('name')->values(),
            'is_active' => $member->is_active,
        ]);

        return response()->json([
            'role' => ['id' => $role->id, 'name' => $role->name],
            ...$members->toArray(),
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        $actor = $request->user();
        $scopeLevels = DelegatedAccess::manageableRoleLevels($actor);
        $scopeLevel = old('scope_level', $this->roles->defaultScopeLevel($actor, $scopeLevels));

        $data = [
            'role' => new Role(['guard_name' => 'web']),
            'scopeLevels' => $scopeLevels,
            'permissions' => $this->roles->manageablePermissions($actor, $scopeLevels),
            'scopeLevel' => $scopeLevel,
        ];

        return response()->json([
            'scope_levels' => $data['scopeLevels'],
            'default_scope_level' => $data['scopeLevel'],
            'permissions' => $data['permissions']->map(fn (Permission $permission): array => [
                'id' => $permission->id,
                'name' => $permission->name,
                'label' => $permission->description ?: $permission->name,
            ])->values(),
        ]);
    }

    public function show(Request $request, Role $role): JsonResponse
    {
        $actor = $request->user();
        abort_unless($this->roles->canManageRole($actor, $role), 403);
        $role->load('permissions')->loadCount('users');

        return response()->json([
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'scope_level' => $role->scope_level,
                'users_count' => $role->users_count,
                'permissions' => $role->permissions->pluck('name')->values(),
                'can_manage' => $this->roles->canManageRole($actor, $role),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        $this->roles->create($actor, $request->validate($this->rules($actor)));

        return response()->json(['message' => 'Đã tạo nhóm người dùng.'], 201);
    }

    public function edit(Request $request, Role $role): JsonResponse
    {
        $actor = $request->user();
        abort_unless($this->roles->canManageRole($actor, $role), 403);
        $scopeLevels = DelegatedAccess::manageableRoleLevels($actor);

        $role->load('permissions')->loadCount('users');
        $permissions = $role->name === Role::SYSTEM_ADMIN
            ? Permission::where('guard_name', 'web')->orderBy('name')->get()
            : $this->roles->manageablePermissions($actor, $scopeLevels);
        $scopeLevel = old('scope_level', $role->scope_level === 'global'
            ? $this->roles->defaultScopeLevel($actor, $scopeLevels)
            : $role->scope_level);

        return response()->json([
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'scope_level' => $role->scope_level,
                'permissions' => $role->permissions->pluck('id')->values(),
            ],
            'scope_levels' => $role->name === Role::SYSTEM_ADMIN ? [] : $scopeLevels,
            'permissions' => $permissions->map(fn (Permission $permission): array => [
                'id' => $permission->id,
                'name' => $permission->name,
                'label' => $permission->description ?: $permission->name,
            ])->values(),
            'selected_scope_level' => $scopeLevel,
        ]);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $actor = $request->user();
        abort_unless($this->roles->canManageRole($actor, $role), 403);
        $this->roles->update($actor, $role, $request->validate($this->rules($actor, $role)));

        return response()->json(['message' => 'Đã cập nhật nhóm người dùng.']);
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        abort_unless($this->roles->canManageRole($request->user(), $role), 403);
        $error = $this->roles->delete($role);
        if ($error !== null) {
            return response()->json(['message' => $error, 'errors' => ['role' => [$error]]], 422);
        }

        return response()->json(['message' => 'Đã xóa nhóm người dùng.']);
    }

    private function rules(User $actor, ?Role $role = null): array
    {
        $manageableLevels = DelegatedAccess::manageableRoleLevels($actor);
        $permissionIds = $this->roles->manageablePermissions($actor, $manageableLevels)->modelKeys();
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
                : ['required', Rule::in($manageableLevels)],
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
}
