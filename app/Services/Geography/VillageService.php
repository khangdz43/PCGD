<?php

namespace App\Services\Geography;

use App\Models\Commune;
use App\Models\Household;
use App\Models\Province;
use App\Models\Role;
use App\Models\User;
use App\Models\Village;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VillageService
{
    public function listing(User $user, array $filters): LengthAwarePaginator
    {
        $query = Village::with('commune.province');
        $this->constrainToUserArea($query, $user);

        if (filled($filters['q'] ?? null)) {
            $search = trim((string) $filters['q']);
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        if (filled($filters['province_code'] ?? null)) {
            $query->whereHas('commune', fn (Builder $builder) => $builder->where('province_code', $filters['province_code']));
        }

        if (filled($filters['commune_code'] ?? null)) {
            $query->where('commune_code', $filters['commune_code']);
        }

        return $query
            ->orderBy('name')
            ->paginate(min(max((int) ($filters['per_page'] ?? 10), 1), 100))
            ->withQueryString();
    }

    public function availableForMerge(User $user): Collection
    {
        $query = Village::with('commune.province')->withCount('households');
        $this->constrainToUserArea($query, $user);

        return $query
            ->orderBy('commune_code')
            ->orderBy('name')
            ->get();
    }

    public function provincesFor(User $user): Collection
    {
        $query = Province::query()->orderBy('name');
        if (! $user->hasRole(Role::SYSTEM_ADMIN)) {
            $query->where('code', $user->province_code);
        }

        return $query->get(['code', 'name']);
    }

    public function findWithCommune(string $code): Village
    {
        return Village::with('commune')->where('code', $code)->firstOrFail();
    }

    public function create(User $user, array $data): Village
    {
        $this->assertCanUseCommune($user, $data['commune_code'], null);
        $data['name'] = trim($data['name']);

        return DB::transaction(function () use ($data): Village {
            $commune = Commune::query()
                ->where('code', $data['commune_code'])
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertUniqueName($data['name'], $commune->code);
            unset($data['province_code'], $data['code']);
            $data['code'] = $this->generateCode($commune, $data['name']);

            return Village::create($data);
        });
    }

    public function update(User $user, Village $village, array $data): void
    {
        $this->assertCanUseCommune($user, $village->commune_code, $village);
        $this->assertCanUseCommune($user, $data['commune_code'], $village);
        $data['name'] = trim($data['name']);

        DB::transaction(function () use ($data, $village): void {
            $commune = Commune::query()
                ->where('code', $data['commune_code'])
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertUniqueName($data['name'], $commune->code, $village->code);
            unset($data['province_code']);
            $village->update($data);
        });
    }

    public function delete(User $user, Village $village): void
    {
        $this->assertCanUseCommune($user, $village->commune_code, $village);
        if ($village->households()->exists()) {
            throw ValidationException::withMessages([
                'village' => 'Không thể xóa thôn/xóm vì đang có hộ điều tra thuộc địa bàn này.',
            ]);
        }

        $village->delete();
    }

    public function merge(User $user, array $sourceCodes, string $targetCode): int
    {
        abort_unless($user->roleScopeLevel() === 'commune' && filled($user->commune_code), 403);

        $sourceCodes = array_values(array_unique($sourceCodes));
        if ($sourceCodes === [] || in_array($targetCode, $sourceCodes, true)) {
            throw ValidationException::withMessages([
                'source_codes' => 'Chọn ít nhất một thôn nguồn khác thôn đích.',
            ]);
        }

        return DB::transaction(function () use ($user, $sourceCodes, $targetCode): int {
            $villages = Village::query()
                ->where('commune_code', $user->commune_code)
                ->whereIn('code', [...$sourceCodes, $targetCode])
                ->orderBy('code')
                ->lockForUpdate()
                ->get();

            $target = $villages->firstWhere('code', $targetCode);
            $sources = $villages->whereIn('code', $sourceCodes);

            if ($target === null || $sources->count() !== count($sourceCodes)) {
                throw ValidationException::withMessages([
                    'source_codes' => 'Một hoặc nhiều thôn đã thay đổi hoặc không còn thuộc địa bàn quản lý.',
                ]);
            }

            $sourceCodes = $sources->modelKeys();
            $households = Household::withTrashed()->whereIn('village_code', $sourceCodes);
            $householdCount = $households->count();
            $households->update(['village_code' => $target->code]);

            $assignedUserIds = DB::table('user_villages')
                ->whereIn('village_code', $sourceCodes)
                ->distinct()
                ->pluck('user_id');

            if ($assignedUserIds->isNotEmpty()) {
                DB::table('user_villages')->insertOrIgnore(
                    $assignedUserIds
                        ->map(fn ($userId): array => [
                            'user_id' => $userId,
                            'village_code' => $target->code,
                        ])
                        ->all()
                );
            }

            DB::table('user_villages')->whereIn('village_code', $sourceCodes)->delete();
            Village::query()->whereIn('code', $sourceCodes)->delete();

            return $householdCount;
        });
    }

    public function assertCanUseCommune(User $user, string $communeCode, ?Village $existingVillage): void
    {
        if ($user->hasRole(Role::SYSTEM_ADMIN)) {
            return;
        }

        $commune = Commune::where('code', $communeCode)->firstOrFail();
        $allowed = match ($user->roleScopeLevel()) {
            'province' => $commune->province_code === $user->province_code,
            'commune' => $communeCode === $user->commune_code,
            'village' => $existingVillage !== null
                && in_array($existingVillage->code, $user->managedVillageCodes(), true)
                && $communeCode === $existingVillage->commune_code
                && $communeCode === $user->commune_code,
            default => false,
        };

        abort_unless($allowed, 403);
    }

    private function constrainToUserArea(Builder $query, User $user): void
    {
        if ($user->hasRole(Role::SYSTEM_ADMIN)) {
            return;
        }

        match ($user->roleScopeLevel()) {
            'province' => $query->whereHas('commune', fn (Builder $commune) => $commune->where('province_code', $user->province_code)),
            'commune' => $query->where('commune_code', $user->commune_code),
            'village' => $query->whereIn('code', $user->managedVillageCodes()),
            default => $query->whereRaw('1 = 0'),
        };
    }

    private function assertUniqueName(string $name, string $communeCode, ?string $exceptCode = null): void
    {
        $query = Village::query()
            ->where('commune_code', $communeCode)
            ->where('name', $name);

        if ($exceptCode !== null) {
            $query->where('code', '!=', $exceptCode);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => 'Tên thôn/xóm đã tồn tại trong xã này.',
            ]);
        }
    }

    private function generateCode(Commune $commune, string $name): string
    {
        $locationPrefix = "{$commune->code}_";
        if (strlen($locationPrefix) >= 20) {
            $locationPrefix = substr($commune->code, 0, 6)
                . strtoupper(substr(hash('sha256', $commune->code), 0, 8))
                . '_';
        }

        $villageSlug = strtoupper(Str::slug($name, '_')) ?: 'THON';

        for ($suffix = 1; ; $suffix++) {
            $suffixText = $suffix === 1 ? '' : "_{$suffix}";
            $prefixLength = min(strlen($locationPrefix), 19 - strlen($suffixText));
            $candidatePrefix = substr($locationPrefix, 0, max(1, $prefixLength));
            $availableLength = max(0, 20 - strlen($candidatePrefix) - strlen($suffixText));
            $code = $candidatePrefix
                . substr($villageSlug, 0, $availableLength)
                . $suffixText;

            if (! Village::query()->whereKey($code)->exists()) {
                return $code;
            }
        }
    }
}
