<?php

namespace App\Http\Controllers\Admin\AccessControl;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\Admin\AccessControl\UserAccessControlService;
use App\Support\DelegatedAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserRoleController extends Controller
{
    public function __construct(private UserAccessControlService $users) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $provinces = $this->users->provincesForFilter($actor);
        $filter = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
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
        ]);

        return view('admin.access-control.users.index', [
            ...$this->users->indexData($actor, $filter, $provinces),
            'actor' => $actor,
        ]);
    }

    public function create(Request $request): View
    {
        $actor = $request->user();
        $accountLevels = DelegatedAccess::manageableAccountLevels($actor);

        return view('admin.access-control.users.create', [
            'user' => new User(['is_active' => true]),
            'actor' => $actor,
            'roles' => $this->users->rolesForForm($actor, $accountLevels),
            'accountLevels' => $accountLevels,
            'accountScopeLevel' => old('scope_level', 'province'),
            'provinces' => $this->users->manageableProvinces($actor),
            'selectedLocations' => $this->users->selectedLocations(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $data = $request->validate($this->rules($actor));
        $role = Role::findOrFail($data['role_id']);
        $scopeLevel = $data['scope_level'] ?? 'province';
        $this->users->validateRoleForScope($role, $scopeLevel, $actor);
        $scope = $request->validate($this->scopeRules($scopeLevel));
        $this->users->authorizeManagedScope($actor, $scopeLevel, $scope);
        $this->users->create($actor, $data, $scope, $scopeLevel, $role);

        return to_route('admin.users.index')->with('success', 'Đã tạo tài khoản và gán quyền truy cập.');
    }

    public function edit(Request $request, User $user): View
    {
        $actor = $request->user();
        abort_unless($this->users->canManageUser($actor, $user), 403);
        $accountLevels = DelegatedAccess::manageableAccountLevels($actor);

        return view('admin.access-control.users.edit', [
            'user' => $user->load(['roles', 'villages']),
            'actor' => $actor,
            'roles' => $this->users->rolesForForm($actor, $accountLevels),
            'accountLevels' => $accountLevels,
            'accountScopeLevel' => old('scope_level', $user->scope_level),
            'provinces' => $this->users->manageableProvinces($actor, $user),
            'selectedLocations' => $this->users->selectedLocations($user),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
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

        return to_route('admin.users.index')->with('success', 'Đã cập nhật tài khoản và quyền truy cập.');
    }

    public function updateStatus(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($this->users->canChangeUserStatus($actor, $user), 403);

        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        if ($actor->is($user) && ! $data['is_active']) {
            return to_route('admin.users.index')->withErrors([
                'is_active' => 'Bạn không thể thu hồi chính tài khoản đang đăng nhập.',
            ]);
        }

        $this->users->updateStatus($user, (bool) $data['is_active']);

        return to_route('admin.users.index')->with(
            'success',
            $user->is_active ? 'Đã kích hoạt lại tài khoản.' : 'Đã thu hồi quyền đăng nhập của tài khoản.'
        );
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($this->users->canResetUserPassword($actor, $user), 403);

        $this->users->resetPassword($user);

        return to_route('admin.users.index')->with(
            'success',
            'Đã đặt lại mật khẩu về '.User::TEMPORARY_PASSWORD.'.'
        );
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
