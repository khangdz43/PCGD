<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateAccountPasswordRequest;
use App\Services\Profile\AccountProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function show(Request $request, AccountProfileService $profileService): View
    {
        return view('account.show', $profileService->viewData($request->user()));
    }

    public function updatePassword(
        UpdateAccountPasswordRequest $request,
        AccountProfileService $profileService,
    ): RedirectResponse {
        $profileService->updatePassword($request->user(), $request->validated('password'));

        return to_route('account.show')->with('success', 'Đổi mật khẩu thành công.');
    }
}
