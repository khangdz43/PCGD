<?php

namespace App\Services\Education;

use App\Models\Province;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SchoolService
{
    public function schoolsForProvince(string $provinceCode, ?string $communeCode, User $user): Collection
    {
        $query = School::query()->where('province_code', $provinceCode);
        if (filled($communeCode)) {
            $query->where('commune_code', $communeCode);
        }

        $this->constrainLookupToUser($query, $user, $provinceCode);

        return $query->orderBy('name')->get(['code', 'name'])->map(fn (School $school): array => [
            'code' => $school->code,
            'name' => $school->display_name,
        ]);
    }

    public function listing(User $user, array $filters): LengthAwarePaginator
    {
        $query = School::with('province', 'commune');
        $this->constrainToUserArea($query, $user);

        if (filled($filters['q'] ?? null)) {
            $search = trim((string) $filters['q']);
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        foreach (['level', 'province_code', 'commune_code'] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where($field, $filters[$field]);
            }
        }

        return $query->orderBy('name')->paginate(10)->withQueryString();
    }

    public function provincesFor(User $user): Collection
    {
        $query = Province::query()->orderBy('name');
        if (! $user->hasRole(Role::SYSTEM_ADMIN)) {
            $query->where('code', $user->province_code);
        }

        return $query->get(['code', 'name']);
    }

    public function findAccessible(User $user, int $id): School
    {
        $school = School::findOrFail($id);
        $this->assertScope($user, [
            'province_code' => $school->province_code,
            'commune_code' => $school->commune_code,
        ]);

        return $school;
    }

    public function create(User $user, array $data): School
    {
        $this->assertScope($user, $data);

        return School::create($data);
    }

    public function update(User $user, School $school, array $data): void
    {
        $this->assertScope($user, $data);
        $school->update($data);
    }

    public function delete(School $school): void
    {
        if ($school->educations()->exists()) {
            throw ValidationException::withMessages([
                'school' => 'Không thể xóa trường học vì đang có hồ sơ nhân khẩu sử dụng trường này.',
            ]);
        }

        $school->delete();
    }

    private function constrainLookupToUser(Builder $query, User $user, string $provinceCode): void
    {
        if ($user->hasRole(Role::SYSTEM_ADMIN)) {
            return;
        }

        if ($user->province_code !== $provinceCode) {
            $query->whereRaw('1 = 0');
        }

        if (in_array($user->roleScopeLevel(), ['commune', 'village'], true)) {
            $query->where('commune_code', $user->commune_code);
        }
    }

    private function constrainToUserArea(Builder $query, User $user): void
    {
        if ($user->hasRole(Role::SYSTEM_ADMIN)) {
            return;
        }

        match ($user->roleScopeLevel()) {
            'province' => $query->where('province_code', $user->province_code),
            'commune' => $query->where('commune_code', $user->commune_code),
            default => $query->whereRaw('1 = 0'),
        };
    }

    private function assertScope(User $user, array $scope): void
    {
        if ($user->hasRole(Role::SYSTEM_ADMIN)) {
            return;
        }

        $allowed = match ($user->roleScopeLevel()) {
            'province' => $user->province_code !== null && $scope['province_code'] === $user->province_code,
            'commune' => $user->province_code !== null
                && $user->commune_code !== null
                && $scope['province_code'] === $user->province_code
                && $scope['commune_code'] === $user->commune_code,
            default => false,
        };

        abort_unless($allowed, 403);
    }
}
