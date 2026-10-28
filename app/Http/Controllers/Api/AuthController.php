<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\Auth\AuthenticationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(
        LoginRequest $request,
        AuthenticationService $authenticationService,
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
            ['user' => $this->userData($user)],
            'Đăng nhập thành công.',
        );
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return ApiResponse::success(
            ['user' => $this->userData($user)],
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
    private function userData(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'scope_level' => $user->roleScopeLevel(),
            'province_code' => $user->province_code,
            'commune_code' => $user->commune_code,
            'permissions' => $user->permissionNames(),
        ];
    }
}
