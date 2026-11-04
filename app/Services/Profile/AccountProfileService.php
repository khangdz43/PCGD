<?php

namespace App\Services\Profile;

use App\Models\User;

class AccountProfileService
{
    public function viewData(User $user): array
    {
        return [
            'user' => [
                'name' => $user->name,
                'username' => $user->username,
                'citizen_id' => $user->citizen_id,
                'date_of_birth' => $user->date_of_birth?->format('Y-m-d'),
                'email' => $user->email,
                'phone' => $user->phone,
            ],
        ];
    }

    /**
     * @return array{scope_level_label: string, scope_area: string, scope_areas: \Illuminate\Support\Collection<int, string>}
     */
    public function scopeSummary(User $user): array
    {
        $user->load([
            'roles' => fn ($query) => $query->where('roles.guard_name', config('auth.permission_guard', 'web')),
        ]);
        $scopeLevel = $user->roleScopeLevel();
        $scopeLevelLabel = match ($scopeLevel) {
            'global' => 'Toàn hệ thống',
            'province' => 'Cấp tỉnh',
            'commune' => 'Cấp xã',
            default => 'Chưa xác định',
        };
        if ($scopeLevel === 'province') {
            $user->loadMissing('province');
        } elseif ($scopeLevel === 'commune') {
            $user->loadMissing(['commune.province', 'villages.commune.province']);
        }

        $scopeAreas = match ($scopeLevel) {
            'global' => collect(['Toàn hệ thống']),
            'province' => collect([
                $user->province?->name
                    ?: ($user->province_code ? 'Mã tỉnh / thành phố '.$user->province_code : 'Chưa được gán địa bàn'),
            ]),
            'commune' => $user->villages->isNotEmpty()
                ? $user->villages
                ->map(fn ($village): string => trim(implode(' / ', array_filter([
                    $village->commune?->province?->name,
                    $village->commune?->name,
                    $village->name,
                ]))))
                ->filter()
                ->values()
                : collect([
                    trim(implode(' / ', array_filter([
                        $user->commune?->province?->name,
                        $user->commune?->name
                            ? $user->commune->name
                            : ($user->commune_code ? 'Mã xã '.$user->commune_code : null),
                    ]))) ?: 'Chưa được gán địa bàn',
                ]),
            default => collect(),
        };

        if ($scopeAreas->isEmpty()) {
            $scopeAreas = collect(['Chưa được gán địa bàn']);
        }

        return [
            'scope_level_label' => $scopeLevelLabel,
            'scope_area' => $scopeAreas->implode(', '),
            'scope_areas' => $scopeAreas,
        ];
    }

    public function updatePassword(User $user, string $password): void
    {
        $user->password = $password;
        $user->save();
    }

    public function updateProfile(User $user, array $data): array
    {
        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'citizen_id' => $data['citizen_id'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
        ]);
        $user->save();

        return $this->viewData($user);
    }
}
