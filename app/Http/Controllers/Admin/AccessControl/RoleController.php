<?php

namespace App\Http\Controllers\Admin\AccessControl;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Admin\AccessControl\RoleManagementService;
use App\Support\DelegatedAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function __construct(private RoleManagementService $roles) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $roles = $this->roles->rolesForIndex($actor);
        $rolesByScope = $roles->groupBy('scope_level');

        return view('admin.access-control.roles.index', compact('roles', 'rolesByScope', 'actor'));
    }

    public function members(Request $request, Role $role): View
    {
        abort_unless($this->roles->canManageRole($request->user(), $role), 403);
        $members = $this->roles->membersFor($role);

        return view('admin.access-control.roles.members', compact('role', 'members'));
    }

    public function create(Request $request): View
    {
        $actor = $request->user();
        $scopeLevels = DelegatedAccess::manageableRoleLevels($actor);
        $scopeLevel = old('scope_level', $this->roles->defaultScopeLevel($actor, $scopeLevels));

        return view('admin.access-control.roles.create', [
            'role' => new Role(['guard_name' => 'web']),
            'actor' => $actor,
            'scopeLevels' => $scopeLevels,
            'permissions' => $this->roles->manageablePermissions($actor, $scopeLevels),
            'scopeLevel' => $scopeLevel,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $this->roles->create($actor, $request->validate($this->rules($actor)));

        return to_route('admin.roles.index')->with('success', 'Đã tạo nhóm người dùng.');
    }

    public function edit(Request $request, Role $role): View
    {
        $actor = $request->user();
        abort_unless($this->roles->canManageRole($actor, $role), 403);
        $scopeLevels = DelegatedAccess::manageableRoleLevels($actor);

        return view('admin.access-control.roles.edit', [
            'role' => $role->load('permissions')->loadCount('users'),
            'actor' => $actor,
            'scopeLevels' => $role->name === Role::SYSTEM_ADMIN ? [] : $scopeLevels,
            'permissions' => $role->name === Role::SYSTEM_ADMIN
                ? Permission::where('guard_name', 'web')->orderBy('name')->get()
                : $this->roles->manageablePermissions($actor, $scopeLevels),
            'scopeLevel' => old('scope_level', $role->scope_level === 'global'
                ? $this->roles->defaultScopeLevel($actor, $scopeLevels)
                : $role->scope_level),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($this->roles->canManageRole($actor, $role), 403);
        $this->roles->update($actor, $role, $request->validate($this->rules($actor, $role)));

        return to_route('admin.roles.index')->with('success', 'Đã cập nhật nhóm người dùng.');
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        abort_unless($this->roles->canManageRole($request->user(), $role), 403);
        $error = $this->roles->delete($role);
        if ($error !== null) {
            return back()->withErrors(['role' => $error]);
        }

        return to_route('admin.roles.index')->with('success', 'Đã xóa nhóm người dùng.');
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
