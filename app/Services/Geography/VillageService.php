<?php

namespace App\Services\Geography;

use App\Models\Commune;
use App\Models\Household;
use App\Models\ImportLog;
use App\Models\Province;
use App\Models\Role;
use App\Models\User;
use App\Models\Village;
use App\Models\VillageMergeHistory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            ->whereNull('merged_into_code')
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
            $data['code'] = $this->generateCode($commune);

            return Village::create($data);
        });
    }

    public function update(User $user, Village $village, array $data): void
    {
        $this->assertNotMerged($village);
        $this->assertCanUseCommune($user, $village->commune_code, $village);
        $this->assertCanUseCommune($user, $data['commune_code'], $village);
        $data['name'] = trim($data['name']);
        $operationalStatus = $data['operational_status'] ?? $village->operational_status;
        unset($data['operational_status']);

        DB::transaction(function () use ($data, $village, $user, $operationalStatus): void {
            $lockedVillage = Village::query()
                ->where('code', $village->code)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertNotMerged($lockedVillage);
            $this->assertCanUseCommune($user, $lockedVillage->commune_code, $lockedVillage);

            if ($operationalStatus === 'paused' && $lockedVillage->intake_paused_at === null) {
                $this->assertNoActiveImports([$lockedVillage->code], 'operational_status');
                $lockedVillage->intake_paused_at = now();
                $lockedVillage->intake_paused_by = $user->id;
            } elseif ($operationalStatus === 'active') {
                $lockedVillage->intake_paused_at = null;
                $lockedVillage->intake_paused_by = null;
            }

            $commune = Commune::query()
                ->where('code', $data['commune_code'])
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertCanUseCommune($user, $commune->code, $lockedVillage);
            $this->assertUniqueName($data['name'], $commune->code, $lockedVillage->code);
            unset($data['province_code']);
            $lockedVillage->fill($data);
            $lockedVillage->save();
        });
    }

    public function delete(User $user, Village $village): void
    {
        $this->assertCanUseCommune($user, $village->commune_code, $village);

        DB::transaction(function () use ($user, $village): void {
            $lockedVillage = Village::query()
                ->where('code', $village->code)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertCanUseCommune($user, $lockedVillage->commune_code, $lockedVillage);

            if ($lockedVillage->operational_status === 'active') {
                throw ValidationException::withMessages([
                    'village' => 'Không thể xóa thôn/xóm đang hoạt động. Hãy chuyển sang trạng thái không hoạt động trước.',
                ]);
            }

            if ($lockedVillage->households()->exists()) {
                throw ValidationException::withMessages([
                    'village' => 'Không thể xóa thôn/xóm vì đang có hộ điều tra thuộc địa bàn này.',
                ]);
            }

            $mergedSources = Village::query()
                ->where('merged_into_code', $lockedVillage->code)
                ->lockForUpdate()
                ->get();
            if ($mergedSources->isNotEmpty()) {
                $sourceCodes = $mergedSources->pluck('code')->all();
                if (Household::withTrashed()->whereIn('village_code', $sourceCodes)->exists()) {
                    throw ValidationException::withMessages([
                        'village' => 'Không thể xóa thôn đích vì thôn nguồn đã gộp vẫn còn phiếu điều tra.',
                    ]);
                }

                $this->assertNoActiveImports($sourceCodes, 'village');
                Village::query()->whereIn('code', $sourceCodes)->delete();
            }

            $lockedVillage->delete();
        });
    }

    public function merge(User $user, array $sourceCodes, string $targetCode): int
    {
        $this->assertCanMerge($user);

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

            $this->assertMergeVillageScope($user, $villages);
            if ($target->merged_into_code !== null || $target->intake_paused_at !== null) {
                throw ValidationException::withMessages([
                    'target_code' => 'Thôn đích phải đang hoạt động và nhận phiếu điều tra.',
                ]);
            }
            if ($sources->contains(fn (Village $source): bool => $source->merged_into_code !== null)) {
                throw ValidationException::withMessages([
                    'source_codes' => 'Không thể chọn thôn nguồn đã được gộp trước đó.',
                ]);
            }
            if ($sources->contains(fn (Village $source): bool => $source->intake_paused_at === null)) {
                throw ValidationException::withMessages([
                    'source_codes' => 'Hãy tạm dừng nhận phiếu tại tất cả thôn nguồn trước khi gộp.',
                ]);
            }

            $sourceCodes = $sources->pluck('code')->all();
            $this->assertNoActiveImports($sourceCodes);
            $households = Household::withTrashed()->whereIn('village_code', $sourceCodes);
            $householdCount = $households->count();
            $households->update(['village_code' => $target->code]);

            VillageMergeHistory::create([
                'commune_code' => $target->commune_code,
                'source_codes' => $sourceCodes,
                'source_names' => $sources->pluck('name')->all(),
                'target_code' => $target->code,
                'target_name' => $target->name,
                'merged_by' => $user->id,
            ]);

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
            Village::query()
                ->whereIn('code', $sourceCodes)
                ->delete();

            return $householdCount;
        });
    }

    public function pauseIntake(User $user, array $sourceCodes): int
    {
        $this->assertCanMerge($user);
        $sourceCodes = array_values(array_unique($sourceCodes));
        if ($sourceCodes === []) {
            throw ValidationException::withMessages([
                'source_codes' => 'Chọn ít nhất một thôn nguồn cần tạm dừng.',
            ]);
        }

        return DB::transaction(function () use ($user, $sourceCodes): int {
            $sources = Village::query()
                ->where('commune_code', $user->commune_code)
                ->whereIn('code', $sourceCodes)
                ->orderBy('code')
                ->lockForUpdate()
                ->get();
            if ($sources->count() !== count($sourceCodes)) {
                throw ValidationException::withMessages([
                    'source_codes' => 'Một hoặc nhiều thôn không còn thuộc địa bàn quản lý.',
                ]);
            }
            $this->assertMergeVillageScope($user, $sources);
            if ($sources->contains(fn (Village $source): bool => $source->merged_into_code !== null)) {
                throw ValidationException::withMessages([
                    'source_codes' => 'Thôn đã được gộp không thể thay đổi trạng thái nhận phiếu.',
                ]);
            }

            $this->assertNoActiveImports($sourceCodes);

            $unpaused = $sources->filter(fn (Village $source): bool => $source->intake_paused_at === null);
            foreach ($unpaused as $source) {
                $source->update([
                    'intake_paused_at' => now(),
                    'intake_paused_by' => $user->id,
                ]);
            }

            return $unpaused->count();
        });
    }

    public function resumeIntake(User $user, array $sourceCodes): int
    {
        $this->assertCanMerge($user);
        $sourceCodes = array_values(array_unique($sourceCodes));
        if ($sourceCodes === []) {
            throw ValidationException::withMessages([
                'source_codes' => 'Chọn ít nhất một thôn cần mở lại nhận phiếu.',
            ]);
        }

        return DB::transaction(function () use ($user, $sourceCodes): int {
            $sources = Village::query()
                ->where('commune_code', $user->commune_code)
                ->whereIn('code', $sourceCodes)
                ->orderBy('code')
                ->lockForUpdate()
                ->get();
            if ($sources->count() !== count($sourceCodes)) {
                throw ValidationException::withMessages([
                    'source_codes' => 'Một hoặc nhiều thôn không còn thuộc địa bàn quản lý.',
                ]);
            }
            $this->assertMergeVillageScope($user, $sources);
            if ($sources->contains(fn (Village $source): bool => $source->merged_into_code !== null)) {
                throw ValidationException::withMessages([
                    'source_codes' => 'Thôn đã gộp không thể mở lại nhận phiếu.',
                ]);
            }

            $paused = $sources->filter(fn (Village $source): bool => $source->intake_paused_at !== null);
            foreach ($paused as $source) {
                $source->update([
                    'intake_paused_at' => null,
                    'intake_paused_by' => null,
                ]);
            }

            return $paused->count();
        });
    }

    public function assertAcceptsHouseholds(string $villageCode, bool $lock = false): Village
    {
        $query = Village::query()->where('code', $villageCode);
        if ($lock) {
            $query->lockForUpdate();
        }
        $village = $query->firstOrFail();
        if ($village->merged_into_code !== null || $village->intake_paused_at !== null) {
            throw ValidationException::withMessages([
                'village_code' => 'Thôn này đang tạm dừng nhận phiếu hoặc đã được gộp.',
            ]);
        }

        return $village;
    }

    private function assertCanMerge(User $user): void
    {
        abort_unless($user->roleScopeLevel() === 'commune' && filled($user->commune_code), 403);
    }

    private function assertMergeVillageScope(User $user, Collection $villages): void
    {
        $managedCodes = $user->managedVillageCodes();
        if ($managedCodes !== [] && $villages->contains(
            fn (Village $village): bool => ! in_array($village->code, $managedCodes, true)
        )) {
            abort(403);
        }
    }

    private function assertNoActiveImports(array $villageCodes, string $field = 'source_codes'): void
    {
        $pendingImports = ImportLog::query()
            ->whereIn('status', ['PENDING', 'PROCESSING'])
            ->whereIn('context->village_code', $villageCodes)
            ->count();

        if ($pendingImports > 0) {
            throw ValidationException::withMessages([
                $field => 'Còn file nhập phiếu đang chờ hoặc đang xử lý tại thôn đã chọn. Hãy đợi nhập xong rồi thử lại.',
            ]);
        }
    }

    private function assertNotMerged(Village $village): void
    {
        if ($village->merged_into_code !== null) {
            throw ValidationException::withMessages([
                'village' => 'Thôn đã được gộp và hiện chỉ còn dùng để tra cứu lịch sử.',
            ]);
        }
    }

    public function assertCanUseCommune(User $user, string $communeCode, ?Village $existingVillage): void
    {
        if ($user->hasRole(Role::SYSTEM_ADMIN)) {
            return;
        }

        $commune = Commune::where('code', $communeCode)->firstOrFail();
        $allowed = match ($user->roleScopeLevel()) {
            'province' => $commune->province_code === $user->province_code,
            'commune' => $communeCode === $user->commune_code
                && ($user->managedVillageCodes() === []
                    || ($existingVillage !== null
                        && in_array($existingVillage->code, $user->managedVillageCodes(), true))),
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
            'commune' => $query->where('commune_code', $user->commune_code)
                ->when($user->managedVillageCodes() !== [], fn (Builder $villages) => $villages
                    ->whereIn('code', $user->managedVillageCodes())),
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

    private function generateCode(Commune $commune): string
    {
        $prefix = "{$commune->code}_";
        if (strlen($prefix) >= 20) {
            throw ValidationException::withMessages([
                'commune_code' => 'Mã xã quá dài để tự tạo mã thôn/xóm.',
            ]);
        }

        $pattern = '/^'.preg_quote($prefix, '/').'(\d+)$/';
        $nextNumber = 1;
        $advanceNumber = static function (string $code) use ($pattern, &$nextNumber): void {
            if (preg_match($pattern, $code, $matches) === 1) {
                $nextNumber = max($nextNumber, (int) $matches[1] + 1);
            }
        };
        foreach (Village::query()->where('commune_code', $commune->code)->pluck('code') as $existingCode) {
            $advanceNumber((string) $existingCode);
        }

        if (Schema::hasTable('village_merge_histories')) {
            $history = DB::table('village_merge_histories')
                ->where('commune_code', $commune->code)
                ->get(['source_codes', 'target_code']);

            foreach ($history as $merge) {
                foreach ([...((array) json_decode($merge->source_codes, true)), (string) $merge->target_code] as $code) {
                    $advanceNumber((string) $code);
                }
            }
        }

        $code = $prefix.str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
        if (strlen($code) > 20) {
            $code = $prefix.$nextNumber;
        }

        if (strlen($code) > 20) {
            throw ValidationException::withMessages([
                'commune_code' => 'Đã hết số thứ tự để tự tạo mã thôn/xóm trong xã này.',
            ]);
        }

        return $code;
    }
}
