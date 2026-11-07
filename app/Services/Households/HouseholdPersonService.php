<?php

namespace App\Services\Households;

use App\Models\Ethnicity;
use App\Models\Household;
use App\Models\Person;
use App\Models\PersonDisability;
use App\Models\PersonEducation;
use App\Models\Province;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolYear;
use App\Models\User;
use App\Services\Geography\VillageService;
use App\Support\DataAccessScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class HouseholdPersonService
{
    public function __construct(private readonly ?VillageService $villages = null) {}

    public function headFor(Household $household): ?Person
    {
        return $household->persons()->where('relationship_with_head', 'Chủ hộ')->first();
    }

    public function newPerson(Household $household, bool $isHouseholdHead, ?Person $headPerson): Person
    {
        $person = new Person;
        $person->gender = 'NAM';
        if ($isHouseholdHead) {
            $person->first_name = $household->head_first_name;
            $person->last_name = $household->head_last_name;
            $person->relationship_with_head = 'Chủ hộ';
        } elseif ($headPerson) {
            $person->ethnicity_code = $headPerson->ethnicity_code;
        }

        return $person;
    }

    public function findWithDetails(Household $household, int $personId): Person
    {
        $household->load('province', 'commune', 'village');

        return $household->persons()->with([
            'ethnicity',
            'educations',
            'educations.school',
            'educations.schoolYearRecord',
            'disability',
        ])->findOrFail($personId);
    }

    public function find(Household $household, int $personId): Person
    {
        return $household->persons()->findOrFail($personId);
    }

    public function latestEducation(Person $person): ?PersonEducation
    {
        return $person->educations()
            ->with('schoolYearRecord')
            ->get()
            ->sortByDesc(fn (PersonEducation $education): ?string => $education->schoolYearRecord?->start_time?->toDateString())
            ->first();
    }

    public function formData(
        Person $person,
        bool $isHouseholdHead,
        User $user,
        ?string $householdSchoolYear = null
    ): array {
        $educationQuery = $person->educations()->with(['school', 'schoolYearRecord']);
        $education = $person->exists
            ? $educationQuery->get()
                ->sortByDesc(fn (PersonEducation $item): ?string => $item->schoolYearRecord?->start_time?->toDateString())
                ->first()
            : null;
        $education ??= new PersonEducation([
            'school_year' => $householdSchoolYear ?: $this->currentSchoolYear(),
        ]);

        $disability = $person->exists ? $person->disability()->first() : null;
        $disability ??= new PersonDisability;

        $provinceQuery = Province::query()->orderBy('name');
        if (! $user->hasRole(Role::SYSTEM_ADMIN)) {
            $provinceQuery->where('code', $user->province_code);
        }
        $selectedSchool = $education->school_code
            ? $education->school
            : null;

        return [
            'education' => $education,
            'disability' => $disability,
            'ethnicities' => Ethnicity::orderBy('name')->get(['code', 'name']),
            'provinces' => $provinceQuery->get(['code', 'name']),
            'schools' => $selectedSchool
                ? [[
                    'code' => $selectedSchool->code,
                    'name' => $selectedSchool->name,
                    'level' => $selectedSchool->level,
                    'province_code' => $selectedSchool->province_code,
                    'commune_code' => $selectedSchool->commune_code,
                ]]
                : [],
        ];
    }

    public function relationshipAgeError(?string $relationship, string $birthDate, ?Person $headPerson): ?string
    {
        if (! $headPerson?->dob || $relationship === null) {
            return null;
        }

        $headBirthDate = substr((string) $headPerson->getRawOriginal('dob'), 0, 10);
        $isYoungerRelationship = in_array($relationship, ['Con', 'Em', 'Cháu'], true);
        $isOlderRelationship = in_array($relationship, ['Cha', 'Mẹ', 'Anh', 'Chị', 'Ông', 'Bà', 'Bác', 'Cô', 'Chú', 'Dì', 'Cậu'], true);

        if ($isYoungerRelationship && $birthDate <= $headBirthDate) {
            return "Người có quan hệ {$relationship} phải nhỏ tuổi hơn chủ hộ.";
        }

        if ($isOlderRelationship && $birthDate >= $headBirthDate) {
            return "Người có quan hệ {$relationship} phải lớn tuổi hơn chủ hộ.";
        }

        return null;
    }

    public function create(Household $household, array $data, bool $isHouseholdHead): Person
    {
        if ($isHouseholdHead) {
            $data['relationship_with_head'] = 'Chủ hộ';
        }
        [$personData, $educationData, $disabilityData] = $this->splitFormData($data);

        return DB::transaction(function () use ($household, $personData, $educationData, $disabilityData): Person {
            $this->lockOpenVillages([$household->village_code]);
            $person = $household->persons()->create($personData);
            if ($person->relationship_with_head === 'Chủ hộ') {
                $household->update([
                    'head_first_name' => $person->first_name,
                    'head_last_name' => $person->last_name,
                ]);
            }
            $person->educations()->create($educationData);
            $person->disability()->create($disabilityData);

            return $person;
        });
    }

    public function update(Household $household, Person $person, array $data, bool $isHouseholdHead, ?PersonEducation $education): void
    {
        if ($isHouseholdHead) {
            $data['education']['school_year'] = $education?->school_year
                ?: ($household->school_year ?: $this->currentSchoolYear());
        }
        [$personData, $educationData, $disabilityData] = $this->splitFormData($data);

        if ($isHouseholdHead) {
            $personData['relationship_with_head'] = 'Chủ hộ';
            DB::transaction(function () use ($person, $household, $personData, $education, $educationData, $disabilityData): void {
                $this->lockOpenVillages([$household->village_code]);
                $person->update($personData);
                $household->update([
                    'head_first_name' => $personData['first_name'],
                    'head_last_name' => $personData['last_name'],
                ]);
                $this->saveEducationAndDisability($person, $education, $educationData, $disabilityData);
            });

            return;
        }

        DB::transaction(function () use ($person, $household, $education, $personData, $educationData, $disabilityData): void {
            $this->lockOpenVillages([$household->village_code]);
            $person->update($personData);
            $this->saveEducationAndDisability($person, $education, $educationData, $disabilityData);
        });
    }

    public function delete(Person $person, Household $household, User $user): ?bool
    {
        return DB::transaction(function () use ($person, $household, $user): ?bool {
            $this->lockOpenVillages([$household->village_code]);
            $isHouseholdHead = $person->relationship_with_head === 'Chủ hộ';
            if (
                $isHouseholdHead
                && $household->persons()->whereKeyNot($person->getKey())->exists()
            ) {
                return null;
            }

            $person->deleted_by = $user->id;
            $person->save();
            $person->delete();

            return $isHouseholdHead;
        });
    }

    public function findManyForDeletion(User $user, array $personIds): Collection
    {
        $persons = Person::whereIn('id', $personIds)
            ->whereHas('household', fn ($query) => DataAccessScope::households($query, $user))
            ->get();
        abort_unless($persons->count() === count($personIds), 404);

        return $persons;
    }

    public function hasNonHeadMembersForSelectedHeads(Collection $persons): bool
    {
        foreach ($persons as $person) {
            if (
                $person->relationship_with_head === 'Chủ hộ'
                && Person::where('household_id', $person->household_id)
                    ->where('relationship_with_head', '!=', 'Chủ hộ')
                    ->exists()
            ) {
                return true;
            }
        }

        return false;
    }

    public function deleteMany(Collection $persons, User $user): void
    {
        DB::transaction(function () use ($persons, $user): void {
            $householdCodes = Household::withTrashed()
                ->whereIn('id', $persons->pluck('household_id')->unique())
                ->pluck('village_code')
                ->all();
            $this->lockOpenVillages($householdCodes);

            foreach ($persons as $person) {
                $person->deleted_by = $user->id;
                $person->save();
                $person->delete();
            }
        });
    }

    private function lockOpenVillages(array $villageCodes): void
    {
        $villageCodes = array_values(array_unique(array_filter(
            $villageCodes,
            fn ($villageCode): bool => $villageCode !== null && $villageCode !== ''
        )));
        sort($villageCodes);

        foreach ($villageCodes as $villageCode) {
            $this->villageService()->assertAcceptsHouseholds($villageCode, true);
        }
    }

    private function villageService(): VillageService
    {
        return $this->villages ?? app(VillageService::class);
    }

    public function splitFormData(array $data): array
    {
        $personData = $data;
        unset($personData['education'], $personData['disability'], $personData['school_province_code'], $personData['school_commune_code']);

        $educationData = $data['education'];
        $schoolYear = SchoolYear::resolveOrCreateFromLabel($educationData['school_year']);
        $educationData['school_year_id'] = $schoolYear->id;
        unset($educationData['school_year']);
        $educationData['is_complementary'] = (bool) ($educationData['is_complementary'] ?? false);
        preg_match(
            '/^\s*(?:lớp\s*)?(\d{1,2})(?=\D|$)/iu',
            (string) ($educationData['current_class'] ?? ''),
            $classNumber,
        );
        $classGrade = isset($classNumber[1]) ? (int) $classNumber[1] : null;
        if ($classGrade !== null && $classGrade >= 1 && $classGrade <= 12) {
            $educationData['academic_block'] = (string) $classGrade;
        } elseif (blank($educationData['academic_block'] ?? null)) {
            preg_match('/\d+/u', (string) ($educationData['current_class'] ?? ''), $classNumber);
            $educationData['academic_block'] = $classNumber[0] ?? null;
        }

        $disabilityData = $data['disability'] ?? [];
        foreach ($this->disabilityBooleanFields() as $field) {
            $disabilityData[$field] = (bool) ($disabilityData[$field] ?? false);
        }

        return [$personData, $educationData, $disabilityData];
    }

    public function disabilityBooleanFields(): array
    {
        return [
            'mobility_disability',
            'hearing_speech_disability',
            'visual_disability',
            'mental_disability',
            'intellectual_disability',
            'learning_disability',
            'autism',
            'other_disability',
            'has_disability_cert',
            'can_study',
        ];
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

    private function saveEducationAndDisability(
        Person $person,
        ?PersonEducation $education,
        array $educationData,
        array $disabilityData
    ): void {
        if ($education?->exists) {
            $education->update($educationData);
        } else {
            $person->educations()->create($educationData);
        }

        $person->disability()->updateOrCreate([], $disabilityData);
    }
}
