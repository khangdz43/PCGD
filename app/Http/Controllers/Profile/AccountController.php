<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateAccountPasswordRequest;
use App\Services\Profile\AccountProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function show(Request $request, AccountProfileService $profileService): JsonResponse
    {
        return response()->json($profileService->viewData($request->user()));
    }

    public function updatePassword(
        UpdateAccountPasswordRequest $request,
        AccountProfileService $profileService,
    ): JsonResponse {
        $profileService->updatePassword($request->user(), $request->validated('password'));

        return response()->json(['message' => 'Đổi mật khẩu thành công.']);
    }
}
