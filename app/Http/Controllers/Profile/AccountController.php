<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AccessPermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function show(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $user->load([
            'roles' => fn ($query) => $query->where('roles.guard_name', config('auth.defaults.guard', 'web')),
            'province',
            'commune.province',
            'villages.commune.province',
        ]);

        $scopeLevel = $user->roleScopeLevel();
        $scopeLevelLabel = match ($scopeLevel) {
            'global' => 'Toàn hệ thống',
            'province' => 'Cấp tỉnh',
            'commune' => 'Cấp xã',
            'village' => 'Cấp thôn / xóm',
            default => 'Chưa xác định',
        };
        $scopeAreas = match ($scopeLevel) {
            'global' => collect(['Toàn hệ thống']),
            'province' => collect([
                $user->province?->name
                    ? $user->province->name
                    : ($user->province_code ? 'Mã tỉnh / thành phố '.$user->province_code : 'Chưa được gán địa bàn'),
            ]),
            'commune' => collect([
                trim(implode(' / ', array_filter([
                    $user->province?->name,
                    $user->commune?->name
                        ? $user->commune->name
                        : ($user->commune_code ? 'Mã xã '.$user->commune_code : null),
                ]))) ?: 'Chưa được gán địa bàn',
            ]),
            'village' => $user->villages
                ->map(fn ($village): string => trim(implode(' / ', array_filter([
                    $village->commune?->province?->name,
                    $village->commune?->name,
                    $village->name,
                ]))))
                ->filter()
                ->values(),
            default => collect(),
        };

        if ($scopeAreas->isEmpty()) {
            $scopeAreas = collect(['Chưa được gán địa bàn']);
        }

        $permissionDefinitions = AccessPermission::definitions();
        $permissions = collect($user->permissionNames())
            ->map(fn (string $name): array => [
                'name' => $name,
                'label' => $permissionDefinitions[$name] ?? $name,
            ])
            ->values();

        return view('account.show', compact(
            'user',
            'scopeLevelLabel',
            'scopeAreas',
            'permissions',
        ));
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ], [
            'current_password.current_password' => 'Mật khẩu hiện tại không chính xác.',
            'password.confirmed' => 'Xác nhận mật khẩu mới không khớp.',
            'password.min' => 'Mật khẩu mới phải có ít nhất 12 ký tự.',
        ]);

        $request->user()->update(['password' => $validated['password']]);

        return to_route('account.show')->with('success', 'Đổi mật khẩu thành công.');
    }
}
