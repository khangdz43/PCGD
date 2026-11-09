<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\Auth\AuthenticationService;
use App\Services\Households\HouseholdService;
use App\Services\Profile\AccountProfileService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(
        LoginRequest $request,
        AuthenticationService $authenticationService,
        AccountProfileService $profileService,
        HouseholdService $households,
    ): JsonResponse {
        $authenticationService->authenticate(
            $request->validated('username'),
            $request->validated('password'),
            $request->ip(),
            $request->boolean('remember'),
        );
        $request->session()->regenerate();
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return ApiResponse::success(
            ['user' => $this->userData($user, $profileService, $households)],
            'Đăng nhập thành công.',
        );
    }

    public function show(
        Request $request,
        AccountProfileService $profileService,
        HouseholdService $households,
    ): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return ApiResponse::success(
            ['user' => $this->userData($user, $profileService, $households)],
            'Lấy thông tin người dùng hiện tại và quyền.',
        );
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return ApiResponse::success(null, 'Đăng xuất thành công.');
    }

    /**
     * @return array<string, mixed>
     */
    private function userData(
        User $user,
        AccountProfileService $profileService,
        HouseholdService $households,
    ): array
    {
        $scope = $profileService->scopeSummary($user);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'scope_level' => $user->roleScopeLevel(),
            'scope_level_label' => $scope['scope_level_label'],
            'scope_area' => $scope['scope_area'],
            'role_name' => $user->roles()->pluck('name')->implode(', ') ?: 'Chưa được gán nhóm',
            'can_view_dashboard' => $user->canViewDashboard(),
            'school_year' => $households->currentSchoolYear(),
            'province_code' => $user->province_code,
            'commune_code' => $user->commune_code,
            'permissions' => $user->permissionNames(),
        ];
    }
}
