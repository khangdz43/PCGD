<?php

namespace App\Services\Dashboard;

use App\Models\Commune;
use App\Models\Household;
use App\Models\Person;
use App\Models\Province;
use App\Models\School;
use App\Models\SchoolYear;
use App\Models\User;
use App\Models\Village;
use App\Services\Households\HouseholdService;
use App\Support\DataAccessScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

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
        $schoolYearRecord = SchoolYear::findByLabel($schoolYear);
        $schoolYearStart = (int) substr($schoolYear, 0, 4);
        $ageReferenceDate = sprintf('%04d-12-31', $schoolYearStart);
        $educationSummary = $schoolYearRecord
            ? $this->educationSummary($schoolYearRecord->id, $schoolYear, $schoolYearStart)
            : $this->emptyEducationSummary();

        return [
            'school_year' => $schoolYear,
            'age_reference_date' => $ageReferenceDate,
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
            'education_summary' => $educationSummary,
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
     * @return array<string, mixed>
     */
    private function educationSummary(
        int $schoolYearId,
        string $schoolYear,
        int $schoolYearStart,
        ?User $user = null,
    ): array
    {
        $preschool = $this->ageCohort(
            $schoolYearId,
            $schoolYear,
            $schoolYearStart - 5,
            $schoolYearStart - 5,
            "UPPER(TRIM(COALESCE(education.current_class, ''))) LIKE 'MN%'",
            "UPPER(TRIM(COALESCE(education.graduation_level, ''))) = 'MN'",
            user: $user,
        );
        $primary = $this->ageCohort(
            $schoolYearId,
            $schoolYear,
            $schoolYearStart - 14,
            $schoolYearStart - 6,
            'CAST(education.academic_block AS SIGNED) BETWEEN 1 AND 5',
            "UPPER(TRIM(COALESCE(education.graduation_level, ''))) = 'TH'",
            user: $user,
        );
        $lowerSecondary = $this->ageCohort(
            $schoolYearId,
            $schoolYear,
            $schoolYearStart - 18,
            $schoolYearStart - 11,
            'CAST(education.academic_block AS SIGNED) BETWEEN 6 AND 9',
            "UPPER(TRIM(COALESCE(education.graduation_level, ''))) = 'THCS'",
            user: $user,
        );
        $literacy = $this->ageCohort(
            $schoolYearId,
            $schoolYear,
            $schoolYearStart - 60,
            $schoolYearStart - 15,
            "NULLIF(TRIM(COALESCE(education.literacy_current_class, '')), '') IS NOT NULL
                OR NULLIF(TRIM(COALESCE(education.learning_capacity, '')), '') IS NOT NULL",
            "COALESCE(
                NULLIF(TRIM(education.literacy_completed_class), ''),
                NULLIF(TRIM(education.completed_class), ''),
                ''
            ) <> ''",
            "NULLIF(TRIM(COALESCE(education.literacy_relapse_level, '')), '') IS NOT NULL",
            $user,
        );

        $schoolAgeStart = sprintf('%04d-01-01', $schoolYearStart - 18);
        $schoolAgeEnd = sprintf('%04d-12-31', $schoolYearStart - 5);
        $quality = $this->peopleForSchoolYear($schoolYearId, $schoolYear, $user)
            ->whereBetween('persons.dob', [$schoolAgeStart, $schoolAgeEnd])
            ->selectRaw(
                "SUM(CASE WHEN education.id IS NULL
                    OR NULLIF(TRIM(COALESCE(education.current_class, '')), '') IS NULL
                    AND NULLIF(TRIM(COALESCE(education.academic_block, '')), '') IS NULL
                    AND NULLIF(TRIM(COALESCE(education.graduation_level, '')), '') IS NULL
                    AND NULLIF(TRIM(COALESCE(education.finished_class, '')), '') IS NULL
                    AND NULLIF(TRIM(COALESCE(education.dropped_class, '')), '') IS NULL
                    THEN 1 ELSE 0 END) AS school_age_missing_education"
            )
            ->first();

        $missingBirthDates = $this->peopleForSchoolYear($schoolYearId, $schoolYear, $user)
            ->whereNull('persons.dob')
            ->count();

        return [
            'preschool_age_5' => $preschool,
            'primary_age_6_to_14' => $primary,
            'lower_secondary_age_11_to_18' => $lowerSecondary,
            'literacy_age_15_to_60' => $literacy,
            'data_quality' => [
                'school_age_missing_education' => (int) ($quality->school_age_missing_education ?? 0),
                'missing_birth_dates' => $missingBirthDates,
            ],
        ];
    }

    /**
     * @return array{population: int, studying: int, completed: int, relapse?: int}
     */
    private function ageCohort(
        int $schoolYearId,
        string $schoolYear,
        int $birthYearFrom,
        int $birthYearTo,
        string $studyingCondition,
        string $completedCondition,
        ?string $relapseCondition = null,
        ?User $user = null,
    ): array {
        $query = $this->peopleForSchoolYear($schoolYearId, $schoolYear, $user)
            ->whereBetween('persons.dob', [
                sprintf('%04d-01-01', $birthYearFrom),
                sprintf('%04d-12-31', $birthYearTo),
            ])
            ->selectRaw('COUNT(*) AS population')
            ->selectRaw("SUM(CASE WHEN {$studyingCondition} THEN 1 ELSE 0 END) AS studying")
            ->selectRaw("SUM(CASE WHEN {$completedCondition} THEN 1 ELSE 0 END) AS completed");

        if ($relapseCondition !== null) {
            $query->selectRaw("SUM(CASE WHEN {$relapseCondition} THEN 1 ELSE 0 END) AS relapse");
        }

        $summary = $query->first();
        $result = [
            'population' => (int) ($summary->population ?? 0),
            'studying' => (int) ($summary->studying ?? 0),
            'completed' => (int) ($summary->completed ?? 0),
        ];
        if ($relapseCondition !== null) {
            $result['relapse'] = (int) ($summary->relapse ?? 0);
        }

        return $result;
    }

    private function peopleForSchoolYear(
        int $schoolYearId,
        string $schoolYear,
        ?User $user = null,
    ): Builder
    {
        $query = DB::table('persons')
            ->join('households', function ($join): void {
                $join->on('households.id', '=', 'persons.household_id')
                    ->whereNull('households.deleted_at');
            })
            ->leftJoin('person_educations as education', function ($join) use ($schoolYearId): void {
                $join->on('education.person_id', '=', 'persons.id')
                    ->where('education.school_year_id', '=', $schoolYearId);
            })
            ->whereNull('persons.deleted_at')
            ->where('households.school_year', $schoolYear);

        if ($user !== null) {
            if ($user->roleScopeLevel() === 'province') {
                $query->where('households.province_code', $user->province_code);
            } elseif ($user->roleScopeLevel() === 'commune') {
                $query->where('households.commune_code', $user->commune_code);
                $managedVillageCodes = $user->managedVillageCodes();
                if ($managedVillageCodes !== []) {
                    $query->whereIn('households.village_code', $managedVillageCodes);
                }
            }
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyEducationSummary(): array
    {
        $emptyCohort = ['population' => 0, 'studying' => 0, 'completed' => 0];

        return [
            'preschool_age_5' => $emptyCohort,
            'primary_age_6_to_14' => $emptyCohort,
            'lower_secondary_age_11_to_18' => $emptyCohort,
            'literacy_age_15_to_60' => [...$emptyCohort, 'relapse' => 0],
            'data_quality' => [
                'school_age_missing_education' => 0,
                'missing_birth_dates' => 0,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function schoolYears(): array
    {
        return $this->households->schoolYears();
    }

    /**
     * @return array<string, mixed>
     */
    public function scopedSummary(User $user, ?string $schoolYear = null): array
    {
        $schoolYear ??= $this->households->currentSchoolYear();
        $scopeLevel = $user->roleScopeLevel();
        $schoolYearRecord = SchoolYear::findByLabel($schoolYear);
        $schoolYearStart = (int) substr($schoolYear, 0, 4);
        $households = DataAccessScope::households(Household::query(), $user)
            ->where('school_year', $schoolYear);
        $people = $this->peopleForSchoolYear(
            $schoolYearRecord?->id ?? 0,
            $schoolYear,
            $user,
        );
        $area = $scopeLevel === 'province'
            ? Province::query()->where('code', $user->province_code)->firstOrFail()
            : Commune::query()->where('code', $user->commune_code)->firstOrFail();
        $schoolQuery = School::query()->whereNull('deleted_at');
        if ($scopeLevel === 'province') {
            $schoolQuery->where('province_code', $user->province_code);
            $communeCount = Commune::query()->where('province_code', $user->province_code)->count();
            $villageCount = Village::query()
                ->whereIn('commune_code', Commune::query()
                    ->select('code')
                    ->where('province_code', $user->province_code))
                ->whereNull('merged_into_code')
                ->count();
            $units = Commune::query()
                ->where('province_code', $user->province_code)
                ->withCount(['households as households_count' => fn ($query) => $query
                    ->where('school_year', $schoolYear)])
                ->orderByDesc('households_count')
                ->orderBy('name')
                ->limit(10)
                ->get(['code', 'name'])
                ->map(fn (Commune $commune): array => [
                    'code' => $commune->code,
                    'name' => $commune->name,
                    'households' => $commune->households_count,
                ])
                ->all();
            $accountQuery = User::query()->where('province_code', $user->province_code);
        } else {
            $schoolQuery->where('commune_code', $user->commune_code);
            $villageQuery = Village::query()
                ->where('commune_code', $user->commune_code)
                ->whereNull('merged_into_code');
            $managedVillageCodes = $user->managedVillageCodes();
            if ($managedVillageCodes !== []) {
                $villageQuery->whereIn('code', $managedVillageCodes);
            }
            $villageCount = (clone $villageQuery)->count();
            $communeCount = 1;
            $units = (clone $villageQuery)
                ->withCount(['households as households_count' => fn ($query) => $query
                    ->where('school_year', $schoolYear)])
                ->orderByDesc('households_count')
                ->orderBy('name')
                ->limit(10)
                ->get(['code', 'name'])
                ->map(fn (Village $village): array => [
                    'code' => $village->code,
                    'name' => $village->name,
                    'households' => $village->households_count,
                ])
                ->all();
            $accountQuery = User::query()
                ->where('commune_code', $user->commune_code)
                ->where('scope_level', 'commune');
            if ($managedVillageCodes !== []) {
                $accountQuery->whereHas('villages', fn ($query) => $query
                    ->whereIn('villages.code', $managedVillageCodes));
            }
        }
        $activeAccounts = (clone $accountQuery)->where('is_active', true)->count();
        $schoolCount = $schoolQuery->count();
        $educationSummary = $schoolYearRecord
            ? $this->educationSummary($schoolYearRecord->id, $schoolYear, $schoolYearStart, $user)
            : $this->emptyEducationSummary();

        return [
            'scope_level' => $scopeLevel,
            'scope_area' => $area->name,
            'school_year' => $schoolYear,
            'school_years' => array_reverse($this->households->schoolYears()),
            'totals' => [
                'communes' => $communeCount,
                'villages' => $villageCount,
                'schools' => $schoolCount,
                'active_users' => $activeAccounts,
                'households' => $households->count(),
                'persons' => $people->count(),
            ],
            'education_summary' => $educationSummary,
            'units' => $units,
        ];
    }
}
