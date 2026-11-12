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
        $query = School::query()->where('province_code', $provinceCode)->where('is_active', true);
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
        $query->whereIn('level', $this->levelsForUser($user));

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

        return $query
            ->orderBy('name')
            ->paginate(min(max((int) ($filters['per_page'] ?? 10), 1), 100))
            ->withQueryString();
    }

    public function levelsForUser(User $user): array
    {
        return match ($user->roleScopeLevel()) {
            'global', 'province' => ['MN', 'TH', 'THCS', 'THPT'],
            'commune' => ['MN', 'TH', 'THCS'],
            default => [],
        };
    }

    public function provincesFor(User $user): Collection
    {
        $query = Province::query()->orderBy('name');
        if (! $user->hasRole(Role::SYSTEM_ADMIN)) {
            $query->whereIn('code', $user->operatingProvinceCodes());
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
        $data['is_active'] = filter_var($data['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);

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
        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

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
        DB::transaction(function () use ($school): void {
            $lockedSchool = School::query()
                ->whereKey($school->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedSchool->is_active) {
                throw ValidationException::withMessages([
                    'school' => 'Không thể xóa trường đang hoạt động. Hãy chuyển trường sang trạng thái không hoạt động trước.',
                ]);
            }

            if ($lockedSchool->educations()->exists()) {
                throw ValidationException::withMessages([
                    'school' => 'Không thể xóa trường học vì đang có hồ sơ nhân khẩu sử dụng trường này.',
                ]);
            }

            $lockedSchool->delete();
        });
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

        if (! in_array($provinceCode, $user->operatingProvinceCodes(), true)) {
            $query->whereRaw('1 = 0');
        }

        if ($user->roleScopeLevel() === 'commune') {
            $query->whereIn('commune_code', $user->operatingCommuneCodes($provinceCode));
        }
    }

    private function constrainToUserArea(Builder $query, User $user): void
    {
        if ($user->hasRole(Role::SYSTEM_ADMIN)) {
            return;
        }

        match ($user->roleScopeLevel()) {
            'province' => $query->whereIn('province_code', $user->operatingProvinceCodes()),
            'commune' => $query->whereIn('commune_code', $user->operatingCommuneCodes()),
            default => $query->whereRaw('1 = 0'),
        };
    }

    private function assertScope(User $user, array $scope): void
    {
        if ($user->hasRole(Role::SYSTEM_ADMIN)) {
            return;
        }

        $allowed = in_array($user->roleScopeLevel(), ['province', 'commune'], true)
            && isset($scope['province_code'])
            && $user->operatesInArea(
                (string) $scope['province_code'],
                $scope['commune_code'] ?? null,
            );

        abort_unless($allowed, 403);
    }
}
