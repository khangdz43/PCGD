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
        $filter = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')],
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
        $data = $this->users->indexData($actor, $filter, $provinces, $filter['per_page'] ?? 10);
        $accounts = $data['allUsers'];
        $data['allUsers'] = $accounts->items();

        $roleOptions = Role::query()
            ->whereIn('id', $this->users->availableRoles($actor, DelegatedAccess::manageableAccountLevels($actor))->modelKeys())
            ->orderBy('scope_level')
            ->orderBy('name')
            ->get(['id', 'name', 'scope_level']);

        return ApiResponse::success(
            [...$data, 'roleOptions' => $roleOptions],
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
        $accountLevels = DelegatedAccess::manageableAccountLevels($actor);

        return response()->json([
            'user' => new User(['is_active' => true]),
            'roles' => $this->users->rolesForForm($actor, $accountLevels),
            'account_levels' => $accountLevels,
            'account_scope_level' => 'province',
            'provinces' => $this->users->manageableProvinces($actor),
            'selected_locations' => $this->users->selectedLocations(),
        ]);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        abort_unless($this->users->canManageUser($request->user(), $user), 403);
        $user->load(['roles', 'province', 'commune', 'villages']);

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'is_active' => $user->is_active,
                'scope_level' => $user->scope_level,
                'province' => $user->province?->only(['code', 'name']),
                'commune' => $user->commune?->only(['code', 'name']),
                'villages' => $user->villages->map(fn ($village): array => [
                    'code' => $village->code,
                    'name' => $village->name,
                ])->values(),
                'roles' => $user->roles->map(fn (Role $role): array => [
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
        $data = $request->validate($this->rules($actor));
        $role = Role::findOrFail($data['role_id']);
        $scopeLevel = $data['scope_level'] ?? 'province';
        $this->users->validateRoleForScope($role, $scopeLevel, $actor);
        $scope = $request->validate($this->scopeRules($scopeLevel));
        $this->users->authorizeManagedScope($actor, $scopeLevel, $scope);
        $this->users->create($actor, $data, $scope, $scopeLevel, $role);

        return response()->json(['message' => 'Đã tạo tài khoản và gán quyền truy cập.'], 201);
    }

    public function edit(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        abort_unless($this->users->canManageUser($actor, $user), 403);
        $accountLevels = DelegatedAccess::manageableAccountLevels($actor);

        return response()->json([
            'user' => $user->load(['roles', 'villages']),
            'roles' => $this->users->rolesForForm($actor, $accountLevels),
            'account_levels' => $accountLevels,
            'account_scope_level' => $user->scope_level,
            'provinces' => $this->users->manageableProvinces($actor, $user),
            'selected_locations' => $this->users->selectedLocations($user),
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        abort_unless($this->users->canManageUser($actor, $user), 403);

        $data = $request->validate($this->rules($actor, $user));
        $role = Role::findOrFail($data['role_id']);
        $scopeLevel = $data['scope_level'] ?? 'province';
        $this->users->validateRoleForScope($role, $scopeLevel, $actor);
        $scope = $request->validate($this->scopeRules($scopeLevel));
        $this->users->authorizeManagedScope($actor, $scopeLevel, $scope);
        $this->users->update($actor, $user, $data, $scope, $scopeLevel, $role);

        return response()->json(['message' => 'Đã cập nhật tài khoản và quyền truy cập.']);
    }

    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        abort_unless($this->users->canChangeUserStatus($actor, $user), 403);

        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

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

        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required',
                'string',
                'max:255',
                Rule::unique('users', 'username')->ignore($user?->id),
            ],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::min(12)],
            'role_id' => [
                'required',
                'integer',
                Rule::exists('roles', 'id')->where('guard_name', 'web')->whereIn('id', $roleIds),
            ],
            'scope_level' => ['required', Rule::in($manageableLevels)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function scopeRules(string $scopeLevel): array
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

        if (in_array($scopeLevel, ['commune', 'village'], true)) {
            $rules['commune_code'] = [
                'required',
                'string',
                Rule::exists('communes', 'code')->where('province_code', request()->input('province_code')),
            ];
        }

        if ($scopeLevel === 'village') {
            $rules['village_codes'] = ['required', 'array', 'min:1'];
            $rules['village_codes.*'] = [
                'required',
                'string',
                'distinct',
                Rule::exists('villages', 'code')->where('commune_code', request()->input('commune_code')),
            ];
        }

        return $rules;
    }
}
