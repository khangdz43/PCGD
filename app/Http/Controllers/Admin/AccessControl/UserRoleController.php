<?php

namespace App\Http\Controllers\Admin\AccessControl;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\Admin\AccessControl\UserAccessControlService;
use App\Support\ApiResponse;
use App\Support\DelegatedAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRoleController extends Controller
{
    public function __construct(private UserAccessControlService $users) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $provinces = $this->users->provincesForFilter($actor);
        $accountLevels = DelegatedAccess::manageableAccountLevels($actor);
        $filter = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')],
            'scope_level' => ['nullable', 'string', Rule::in($accountLevels)],
            'province_code' => ['nullable', 'string', Rule::in($provinces->pluck('code')->all())],
            'commune_code' => [
                'nullable',
                'string',
                'required_with:village_code',
                Rule::exists('communes', 'code')->where(
                    'province_code',
                    $request->input('province_code') ?? $actor->province_code ?? ''
                ),
            ],
            'village_code' => [
                'nullable',
                'string',
                Rule::exists('villages', 'code')->where('commune_code', $request->input('commune_code') ?? ''),
            ],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $filterRoles = $this->users->rolesForAccountFilter($actor, $filter);
        $data = $this->users->indexData(
            $actor,
            $filter,
            $provinces,
            $filter['per_page'] ?? 10,
            includeOrganizationData: false,
        );
        $accounts = $data['allUsers'];
        $users = $accounts->getCollection()->map(fn (User $user): array => [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'phone' => $user->phone,
            'date_of_birth' => $user->date_of_birth?->format('Y-m-d'),
            'email' => $user->email,
            'role_name' => $user->roles->pluck('name')->implode(', ') ?: 'Chưa được gán nhóm',
            'scope_level' => $user->roleScopeLevel(),
            'province_name' => $user->province?->name,
            'is_active' => $user->is_active,
            'can_view_details' => $this->users->canViewUser($actor, $user),
            'can_edit_configuration' => $this->users->canEditUserConfiguration($actor, $user),
            'can_change_account_status' => $user->can_change_account_status,
            'can_reset_account_password' => $user->can_reset_account_password,
        ])->all();

        return ApiResponse::success(
            [
                'allUsers' => $users,
                'roles' => $filterRoles->map(fn (Role $role): array => [
                    'id' => $role->id,
                    'name' => $role->name,
                ])->values(),
                'provinces' => $provinces->map(fn ($province): array => [
                    'code' => $province->code,
                    'name' => $province->name,
                ])->values(),
                'account_scope_levels' => $accountLevels,
                'actor_scope_level' => $actor->roleScopeLevel(),
                'can_edit_account_configurations' => $this->users->canEditUserConfigurations($actor),
                'selected_filters' => [
                    'province_code' => $filter['province_code'] ?? $actor->province_code,
                    'commune_code' => $filter['commune_code'] ?? (
                        $actor->roleScopeLevel() === 'commune' ? $actor->commune_code : null
                    ),
                    'village_code' => $filter['village_code'] ?? null,
                    'role_id' => $filter['role_id'] ?? null,
                    'scope_level' => $filter['scope_level'] ?? null,
                ],
            ],
            'Lấy danh sách tài khoản thành công.',
            meta: [
                'current_page' => $accounts->currentPage(),
                'last_page' => $accounts->lastPage(),
                'per_page' => $accounts->perPage(),
                'total' => $accounts->total(),
            ],
        );
    }

    public function create(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless(
            $actor->can('create-users')
                && ($actor->hasRole(Role::SYSTEM_ADMIN) || $actor->roleScopeLevel() !== 'global'),
            403
        );
        $accountLevels = DelegatedAccess::manageableAccountLevels($actor);
        $provinces = $this->users->manageableProvinces($actor);
        $selectedLocations = $this->users->selectedLocations($actor);
        $selectedLocations['village_codes'] = [];
        $selectedLocations['villages'] = [];

        return response()->json([
            'user' => new User(['is_active' => true]),
            'roles' => $this->users->rolesForForm($actor, $accountLevels)
                ->map(fn (Role $role): array => $role->only([
                    'id',
                    'name',
                    'scope_level',
                    'assignable_scope_levels',
                    'is_primary_admin',
                    'manages_all_villages',
                    'uses_village_scope',
                ])),
            'account_levels' => $accountLevels,
            'available_primary_admin_scopes' => [
                'province' => $provinces->isNotEmpty(),
                'commune' => $this->users->hasAvailablePrimaryAdminScope($actor, 'commune'),
            ],
            'provinces' => $provinces,
            'selected_locations' => $selectedLocations,
            'actor_scope_level' => $actor->roleScopeLevel(),
        ]);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        $actor->loadMissing(['roles.permissions', 'roles.creator']);
        $user->loadMissing(['roles.permissions', 'roles.creator', 'villages']);

        abort_unless($this->users->canViewUser($actor, $user), 403);
        $user->loadMissing(['province', 'commune']);

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'phone' => $user->phone,
                'citizen_id' => $user->citizen_id,
                'date_of_birth' => $user->date_of_birth?->format('Y-m-d'),
                'email' => $user->email,
                'is_active' => $user->is_active,
                'scope_level' => $user->scope_level,
                'province' => $user->province?->only(['code', 'name']),
                'commune' => $user->commune?->only(['code', 'name']),
                'villages' => $user->villages->map(fn ($village): array => [
                    'code' => $village->code,
                    'name' => $village->name,
                ])->values(),
                'roles' => $user->roles
                    ->where('created_by', $actor->id)
                    ->map(fn (Role $role): array => [
                        'id' => $role->id,
                        'name' => $role->name,
                        'scope_level' => $role->scope_level,
                    ])->values(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->can('create-users'), 403);
        $data = $request->validate($this->rules($actor));
        $role = Role::findOrFail($data['role_id']);
        $scopeLevel = $data['scope_level'] ?? 'province';
        $this->users->validateRoleForScope($role, $scopeLevel, $actor);
        $scope = $request->validate($this->scopeRules(
            $scopeLevel,
            $actor,
            $this->users->roleManagesAllVillages($role),
            $this->users->roleUsesVillageScope($role),
        ));
        $this->users->authorizeManagedScope($actor, $scopeLevel, $scope);
        $user = $this->users->create($actor, $data, $scope, $scopeLevel, $role);

        return response()->json([
            'message' => 'Đã tạo tài khoản và gán quyền truy cập.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'phone' => $user->phone,
                'role_name' => $user->roles->pluck('name')->implode(', '),
                'role_id' => $role->id,
                'scope_level' => $user->roleScopeLevel(),
                'province_code' => $user->province_code,
                'commune_code' => $user->commune_code,
                'village_codes' => $user->villages->pluck('code')->all(),
                'is_active' => $user->is_active,
                'can_view_details' => $this->users->canViewUser($actor, $user),
                'can_edit_configuration' => $this->users->canEditUserConfiguration($actor, $user),
                'can_change_account_status' => $this->users->canChangeUserStatus($actor, $user, true)
                    || $this->users->canChangeUserStatus($actor, $user, false),
                'can_reset_account_password' => $this->users->canResetUserPassword($actor, $user),
            ],
        ], 201);
    }

    public function edit(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        $actor->loadMissing(['roles.permissions', 'roles.creator']);
        $user->loadMissing(['roles.permissions', 'roles.creator', 'villages']);
        $accountLevels = DelegatedAccess::manageableAccountLevels($actor);
        $roles = $this->users->rolesForForm($actor, $accountLevels);
        abort_unless($this->users->canEditUserConfiguration($actor, $user, $roles), 403);
        $provinces = $this->users->manageableProvinces($actor, $user);
        $selectedLocations = $this->users->selectedLocations($user);
        $canAssignCommuneAdmin = $roles->contains(
            fn (Role $role): bool => $role->is_primary_admin
                && in_array('commune', $role->assignable_scope_levels, true),
        );
        $user->roles->each(function (Role $role): void {
            $role->unsetRelation('permissions');
            $role->unsetRelation('creator');
        });
        $user->setRelation(
            'roles',
            $user->roles->where('created_by', $actor->id)->values(),
        );

        return response()->json([
            'user' => $user,
            'roles' => $roles
                ->map(fn (Role $role): array => $role->only([
                    'id',
                    'name',
                    'scope_level',
                    'assignable_scope_levels',
                    'is_primary_admin',
                    'manages_all_villages',
                    'uses_village_scope',
                ])),
            'account_levels' => $accountLevels,
            'account_scope_level' => $user->scope_level,
            'available_primary_admin_scopes' => [
                'province' => $provinces->isNotEmpty(),
                'commune' => $canAssignCommuneAdmin
                    && $this->users->hasAvailablePrimaryAdminScope($actor, 'commune', $user),
            ],
            'provinces' => $provinces,
            'selected_locations' => $selectedLocations,
            'actor_scope_level' => $actor->roleScopeLevel(),
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        abort_unless($this->users->canEditUserConfiguration($actor, $user), 403);

        $data = $request->validate($this->rules($actor, $user));
        $role = Role::findOrFail($data['role_id']);
        $scopeLevel = $data['scope_level'] ?? 'province';
        $this->users->validateRoleForScope($role, $scopeLevel, $actor);
        $scope = $request->validate($this->scopeRules(
            $scopeLevel,
            $actor,
            $this->users->roleManagesAllVillages($role),
            $this->users->roleUsesVillageScope($role),
        ));
        $this->users->authorizeManagedScope($actor, $scopeLevel, $scope);
        $this->users->update($actor, $user, $data, $scope, $scopeLevel, $role);

        return response()->json(['message' => 'Đã cập nhật tài khoản và quyền truy cập.']);
    }

    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);
        abort_unless($this->users->canChangeUserStatus($actor, $user, (bool) $data['is_active']), 403);

        if ($actor->is($user) && ! $data['is_active']) {
            return response()->json([
                'message' => 'Bạn không thể thu hồi chính tài khoản đang đăng nhập.',
                'errors' => ['is_active' => ['Bạn không thể thu hồi chính tài khoản đang đăng nhập.']],
            ], 422);
        }

        $this->users->updateStatus($user, (bool) $data['is_active']);

        return response()->json([
            'message' => $user->is_active
                ? 'Đã kích hoạt lại tài khoản.'
                : 'Đã thu hồi quyền đăng nhập của tài khoản.',
        ]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        abort_unless($this->users->canResetUserPassword($actor, $user), 403);
        $this->users->resetPassword($user);

        return response()->json([
            'message' => 'Đã đặt lại mật khẩu về '.User::TEMPORARY_PASSWORD.'.',
            'temporary_password' => User::TEMPORARY_PASSWORD,
        ]);
    }

    private function rules(User $actor, ?User $user = null): array
    {
        $scopeLevel = request()->input('scope_level', 'province');
        $manageableLevels = DelegatedAccess::manageableAccountLevels($actor);
        $roleIds = in_array($scopeLevel, $manageableLevels, true)
            ? $this->users->availableRoles($actor, [$scopeLevel])->modelKeys()
            : [];
        $usernameRules = ['required', 'string'];
        if (! $user || request()->input('username') !== $user->username) {
            $usernameRules[] = 'min:7';
            $usernameRules[] = 'max:12';
        }
        $usernameRules[] = Rule::unique('users', 'username')->ignore($user?->id);

        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => $usernameRules,
            'phone' => [
                'nullable',
                'string',
                'max:20',
            ],
            'citizen_id' => [
                'required',
                'digits:12',
                Rule::unique('users', 'citizen_id')->ignore($user?->id),
            ],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::min(12)->max(18)],
            'role_id' => [
                'required',
                'integer',
                Rule::exists('roles', 'id')->where('guard_name', 'web')->whereIn('id', $roleIds),
            ],
            'scope_level' => ['required', Rule::in($manageableLevels)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function scopeRules(
        string $scopeLevel,
        User $actor,
        bool $managesAllVillages = false,
        bool $usesVillageScope = false,
    ): array
    {
        if ($scopeLevel === 'global') {
            return [
                'province_code' => ['prohibited'],
                'commune_code' => ['prohibited'],
                'village_codes' => ['prohibited'],
                'school_code' => ['prohibited'],
            ];
        }

        $rules = [
            'province_code' => ['required', 'string', Rule::exists('provinces', 'code')],
            'commune_code' => ['prohibited'],
            'village_codes' => ['prohibited'],
            'school_code' => ['prohibited'],
        ];

        if ($scopeLevel === 'commune') {
            $rules['commune_code'] = [
                'required',
                'string',
                Rule::exists('communes', 'code')->where('province_code', request()->input('province_code')),
            ];
        }

        if ($scopeLevel === 'commune' && $actor->roleScopeLevel() === 'commune') {
            if ($managesAllVillages || ! $usesVillageScope) {
                unset($rules['village_codes']);
            } else {
                $rules['village_codes'] = $actor->managedVillageCodes() === []
                    ? ['sometimes', 'array']
                    : ['required', 'array', 'min:1'];
                $rules['village_codes.*'] = [
                    'required',
                    'string',
                    'distinct',
                    Rule::exists('villages', 'code')->where('commune_code', request()->input('commune_code')),
                ];
            }
        }

        return $rules;
    }
}
