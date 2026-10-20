<?php

namespace App\Services\Households;

use App\Models\Household;
use App\Models\Province;
use App\Models\Role;
use App\Models\User;
use App\Support\DataAccessScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class HouseholdService
{
    public function defaultFilters(User $user): array
    {
        $filters = ['school_year' => $this->currentSchoolYear()];

        if (! $user->hasRole(Role::SYSTEM_ADMIN)) {
            if ($user->province_code !== null) {
                $filters['province_code'] = $user->province_code;
            }

            if (in_array($user->roleScopeLevel(), ['commune', 'village'], true) && $user->commune_code !== null) {
                $filters['commune_code'] = $user->commune_code;
            }

            $managedVillageCodes = $user->roleScopeLevel() === 'village'
                ? $user->managedVillageCodes()
                : [];
            if (count($managedVillageCodes) === 1) {
                $filters['village_code'] = $managedVillageCodes[0];
            }
        }

        return $filters;
    }

    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        $query = Household::with([
            'province',
            'commune',
            'village',
            'persons',
            'persons.ethnicity',
            'persons.educations',
            'persons.educations.school',
            'persons.disability',
        ])->withCount([
            'persons',
            'persons as head_persons_count' => fn ($builder) => $builder->where('relationship_with_head', 'Chủ hộ'),
        ]);

        DataAccessScope::households($query, $user);

        if (filled($filters['q'] ?? null)) {
            $search = trim((string) $filters['q']);
            $query->where(function ($builder) use ($search): void {
                $builder->where('household_code', 'like', "%{$search}%");
                $builder->orWhere('head_first_name', 'like', "%{$search}%")
                    ->orWhere('head_last_name', 'like', "%{$search}%")
                    ->orWhereRaw("CONCAT(head_last_name, ' ', head_first_name) LIKE ?", ["%{$search}%"])
                    ->orWhereRaw("CONCAT(head_first_name, ' ', head_last_name) LIKE ?", ["%{$search}%"]);
            });
        }

        foreach (['province_code', 'commune_code', 'village_code'] as $locationField) {
            if (filled($filters[$locationField] ?? null)) {
                $query->where($locationField, $filters[$locationField]);
            }
        }

        if (filled($filters['school_year'] ?? null) && $filters['school_year'] !== 'all') {
            $query->where('school_year', $filters['school_year']);
        }

        if (filled($filters['school_code'] ?? null)) {
            $query->whereHas('persons.educations', function ($builder) use ($filters): void {
                $builder->where('school_code', $filters['school_code']);
            });
        }

        return $query->orderBy('household_code')->paginate(7)->withQueryString();
    }

    public function provincesFor(User $user): Collection
    {
        $query = Province::orderBy('name');
        if (! $user->hasRole(Role::SYSTEM_ADMIN)) {
            $query->where('code', $user->province_code);
        }

        return $query->get(['code', 'name']);
    }

    public function details(Household $household): array
    {
        $household->load('province', 'commune', 'village');
        $persons = $household->persons()
            ->with('ethnicity')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        return [
            'persons' => $persons,
            'headPerson' => $persons->firstWhere('relationship_with_head', 'Chủ hộ'),
            'hasAnyHouseholdHead' => $household->persons()
                ->where('relationship_with_head', 'Chủ hộ')
                ->exists(),
        ];
    }

    public function villagePlaceholder(User $user): string
    {
        $managedVillageCodes = $user->roleScopeLevel() === 'village'
            ? $user->managedVillageCodes()
            : [];

        return count($managedVillageCodes) > 1
            ? '-- Tất cả thôn được giao --'
            : '-- Chọn Thôn/Bản --';
    }

    public function newFor(User $user): Household
    {
        $household = new Household;
        if (! $user->hasRole(Role::SYSTEM_ADMIN)) {
            $household->province_code = $user->province_code;
            $household->commune_code = $user->commune_code;
            $villageCodes = $user->managedVillageCodes();
            if ($user->roleScopeLevel() === 'village' && count($villageCodes) === 1) {
                $household->village_code = $villageCodes[0];
            }
        }
        $household->household_code = $this->nextHouseholdCode();
        $household->school_year = $this->currentSchoolYear();

        return $household;
    }

    public function create(User $user, array $data): Household
    {
        DataAccessScope::assertHouseholdLocation($user, $data);
        $data['household_code'] = $this->nextHouseholdCode($data['school_year']);

        return Household::create($data);
    }

    public function update(Household $household, User $user, array $data): void
    {
        DataAccessScope::assertHouseholdLocation($user, $data);
        $data['school_year'] = $household->school_year;

        DB::transaction(function () use ($household, $data): void {
            $household->update($data);
            $household->persons()->where('relationship_with_head', 'Chủ hộ')->update([
                'first_name' => $data['head_first_name'],
                'last_name' => $data['head_last_name'],
            ]);
        });
    }

    public function delete(Household $household, User $user): void
    {
        $household->deleted_by = $user->id;
        $household->save();
        $household->delete();
    }

    public function deleteMany(User $user, array $ids): int
    {
        $accessibleHouseholds = DataAccessScope::households(Household::query(), $user)
            ->whereIn('id', $ids)
            ->get();
        abort_unless($accessibleHouseholds->count() === count($ids), 404);

        return DB::transaction(function () use ($accessibleHouseholds, $user): int {
            foreach ($accessibleHouseholds as $household) {
                $this->delete($household, $user);
            }

            return $accessibleHouseholds->count();
        });
    }

    public function nextHouseholdCode(?string $schoolYear = null): string
    {
        $query = Household::withTrashed();
        if ($schoolYear !== null) {
            $query->where('school_year', $schoolYear);
        }

        $maxCode = $query
            ->pluck('household_code')
            ->filter(fn ($code) => ctype_digit((string) $code))
            ->map(fn ($code) => (int) $code)
            ->max() ?? 0;

        return str_pad((string) ($maxCode + 1), 2, '0', STR_PAD_LEFT);
    }

    public function schoolYears(): array
    {
        $lastSchoolYear = now()->month >= 8 ? now()->year : now()->year - 1;

        return array_map(
            fn (int $year): string => "{$year}-".($year + 1),
            range(2020, $lastSchoolYear)
        );
    }

    public function currentSchoolYear(): string
    {
        $year = now()->year;
        $startYear = now()->month >= 8 ? $year : $year - 1;

        return $startYear.'-'.($startYear + 1);
    }
}
