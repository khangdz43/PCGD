<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\Auth\AuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SessionController extends Controller
{
    public function csrfCookie(Request $request): JsonResponse
    {
        return response()->json(['csrf_token' => $request->session()->token()]);
    }

    public function login(LoginRequest $request, AuthenticationService $authenticationService): JsonResponse
    {
        $authenticationService->authenticate(
            $request->validated('username'),
            $request->validated('password'),
            $request->ip(),
            $request->boolean('remember'),
        );
        $request->session()->regenerate();

        return response()->json(['user' => $this->userData($request->user(), false)]);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userData($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Đã đăng xuất.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function userData(User $user, bool $includePermissions = true): array
    {
        $data = [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'scope_level' => $includePermissions ? $user->roleScopeLevel() : $user->scope_level,
            'province_code' => $user->province_code,
            'commune_code' => $user->commune_code,
        ];

        if ($includePermissions) {
            $data['permissions'] = $user->permissionNames();
        }

        return $data;
    }
}
