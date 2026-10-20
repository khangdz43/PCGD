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
use App\Models\User;
use App\Support\DataAccessScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class HouseholdPersonService
{
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
            'disability',
        ])->findOrFail($personId);
    }

    public function find(Household $household, int $personId): Person
    {
        return $household->persons()->findOrFail($personId);
    }

    public function latestEducation(Person $person): ?PersonEducation
    {
        return $person->educations()->orderByDesc('school_year')->first();
    }

    public function formData(
        Person $person,
        bool $isHouseholdHead,
        User $user,
        ?string $householdSchoolYear = null
    ): array {
        $educationQuery = $person->educations()->with('school');
        $education = $person->exists
            ? $educationQuery->orderByDesc('school_year')->first()
            : null;
        $education ??= new PersonEducation([
            'school_year' => $householdSchoolYear ?: $this->currentSchoolYear(),
        ]);

        $disability = $person->exists ? $person->disability()->first() : null;
        $disability ??= new PersonDisability;

        $provinceQuery = Province::query()->orderBy('name');
        $schoolQuery = School::query()->orderBy('name');
        if (! $user->hasRole(Role::SYSTEM_ADMIN)) {
            $provinceQuery->where('code', $user->province_code);
            if (in_array($user->roleScopeLevel(), ['commune', 'village'], true)) {
                $schoolQuery->where('commune_code', $user->commune_code);
            } else {
                $schoolQuery->where('province_code', $user->province_code);
            }
        }

        return [
            'education' => $education,
            'disability' => $disability,
            'ethnicities' => Ethnicity::orderBy('name')->get(['code', 'name']),
            'provinces' => $provinceQuery->get(['code', 'name']),
            'schools' => $schoolQuery->get(['code', 'name', 'level', 'province_code', 'commune_code']),
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
            $data['last_name'] = $household->head_last_name;
            $data['first_name'] = $household->head_first_name;
            $data['relationship_with_head'] = 'Chủ hộ';
        }
        [$personData, $educationData, $disabilityData] = $this->splitFormData($data);

        return DB::transaction(function () use ($household, $personData, $educationData, $disabilityData): Person {
            $person = $household->persons()->create($personData);
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
                $person->update($personData);
                $household->update([
                    'head_first_name' => $personData['first_name'],
                    'head_last_name' => $personData['last_name'],
                ]);
                $this->saveEducationAndDisability($person, $education, $educationData, $disabilityData);
            });

            return;
        }

        DB::transaction(function () use ($person, $education, $personData, $educationData, $disabilityData): void {
            $person->update($personData);
            $this->saveEducationAndDisability($person, $education, $educationData, $disabilityData);
        });
    }

    public function delete(Person $person, Household $household): ?bool
    {
        $isHouseholdHead = $person->relationship_with_head === 'Chủ hộ';
        if (
            $isHouseholdHead
            && $household->persons()->where('relationship_with_head', '!=', 'Chủ hộ')->exists()
        ) {
            return null;
        }

        $person->delete();

        return $isHouseholdHead;
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

    public function deleteMany(Collection $persons): void
    {
        DB::transaction(function () use ($persons): void {
            Person::whereIn('id', $persons->modelKeys())->delete();
        });
    }

    public function splitFormData(array $data): array
    {
        $personData = $data;
        unset($personData['education'], $personData['disability'], $personData['school_province_code'], $personData['school_commune_code']);

        $educationData = $data['education'];
        $educationData['is_complementary'] = (bool) ($educationData['is_complementary'] ?? false);
        preg_match('/\d+/u', (string) ($educationData['current_class'] ?? ''), $classNumber);
        $educationData['academic_block'] = $classNumber[0] ?? null;

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
