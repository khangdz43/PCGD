<?php

namespace App\Services\Education;

use App\Models\Province;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

        return DB::transaction(function () use ($data): School {
            Province::query()
                ->when(
                    filled($data['province_code'] ?? null),
                    fn (Builder $query) => $query->where('code', $data['province_code'])
                )
                ->lockForUpdate()
                ->first();
            $this->assertUniqueNameAndLevel($data);
            unset($data['code']);
            $data['code'] = $this->generateCode($data);

            return School::create($data);
        });
    }

    public function update(User $user, School $school, array $data): void
    {
        $this->assertScope($user, $data);

        DB::transaction(function () use ($data, $school): void {
            Province::query()
                ->where('code', $data['province_code'])
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertUniqueNameAndLevel($data, $school->id);
            $school->update($data);
        });
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

    private function assertUniqueNameAndLevel(array $data, ?int $exceptId = null): void
    {
        $query = School::query()
            ->where('province_code', $data['province_code'])
            ->where('name', $data['name']);

        if (filled($data['commune_code'] ?? null)) {
            $query->where('commune_code', $data['commune_code']);
        } else {
            $query->whereNull('commune_code');
        }

        if (filled($data['level'] ?? null)) {
            $query->where('level', $data['level']);
        } else {
            $query->whereNull('level');
        }

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => 'Tên trường cùng cấp học đã tồn tại trong đơn vị này.',
            ]);
        }
    }

    private function generateCode(array $data): string
    {
        $abbreviation = collect(preg_split('/\s+/u', trim(Str::ascii($data['name']))) ?: [])
            ->filter(fn (string $word): bool => $word !== '' && strtolower($word) !== 'truong')
            ->map(fn (string $word): string => strtoupper(substr($word, 0, 1)))
            ->implode('') ?: 'TR';
        $prefix = implode('_', array_filter([
            $data['province_code'] ?? null,
            $data['commune_code'] ?? null,
        ], fn (?string $code): bool => filled($code)));
        $baseCode = "{$prefix}{$abbreviation}";

        for ($suffix = 1; ; $suffix++) {
            $suffixText = $suffix === 1 ? '' : (string) $suffix;
            $code = substr($baseCode, 0, 50 - strlen($suffixText)) . $suffixText;
            if (! School::query()->where('code', $code)->exists()) {
                return $code;
            }
        }
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
