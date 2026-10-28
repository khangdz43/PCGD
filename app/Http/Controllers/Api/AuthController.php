<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\Auth\AuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function login(LoginRequest $request, AuthenticationService $authenticationService): JsonResponse
    {
        $user = $authenticationService->authenticateApi(
            $request->validated('username'),
            $request->validated('password'),
            $request->ip(),
        );
        $token = $user->createToken(
            'api',
            ['*'],
            now()->addHours(8),
        );

        return response()->json([
            'data' => [
                'token_type' => 'Bearer',
                'access_token' => $token->plainTextToken,
                'expires_at' => $token->accessToken->expires_at?->toISOString(),
                'user' => $this->userData($user, false),
            ],
        ], 201);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->userData($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([], 204);
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
