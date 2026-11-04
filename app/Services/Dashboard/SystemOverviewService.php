<?php

namespace App\Services\Dashboard;

use App\Models\Commune;
use App\Models\Household;
use App\Models\Person;
use App\Models\Province;
use App\Models\School;
use App\Models\User;
use App\Models\Village;
use App\Services\Households\HouseholdService;

class SystemOverviewService
{
    public function __construct(private readonly HouseholdService $households) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(?string $schoolYear = null): array
    {
        $schoolYear ??= $this->households->currentSchoolYear();
        $householdsForYear = fn ($query) => $query->where('school_year', $schoolYear);

        return [
            'school_year' => $schoolYear,
            'school_years' => array_reverse($this->households->schoolYears()),
            'totals' => [
                'provinces' => Province::query()->count(),
                'communes' => Commune::query()->count(),
                'villages' => Village::query()->count(),
                'schools' => School::query()->whereNull('deleted_at')->count(),
                'active_users' => User::query()->where('is_active', true)->count(),
                'households' => Household::query()->where('school_year', $schoolYear)->count(),
                'persons' => Person::query()
                    ->whereHas('household', $householdsForYear)
                    ->count(),
            ],
            'provinces' => Province::query()
                ->withCount(['households as households_count' => $householdsForYear])
                ->orderByDesc('households_count')
                ->orderBy('name')
                ->limit(10)
                ->get(['code', 'name'])
                ->map(fn (Province $province): array => [
                    'code' => $province->code,
                    'name' => $province->name,
                    'households' => $province->households_count,
                ])
                ->all(),
        ];
    }

    /**
     * @return list<string>
     */
    public function schoolYears(): array
    {
        return $this->households->schoolYears();
    }
}
