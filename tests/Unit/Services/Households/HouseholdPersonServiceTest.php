<?php

namespace Tests\Unit\Services\Households;

use App\Models\Household;
use App\Models\Person;
use App\Services\Households\HouseholdPersonService;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HouseholdPersonServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_new_household_head_inherits_the_household_name_and_relationship(): void
    {
        $household = new Household([
            'head_first_name' => 'An',
            'head_last_name' => 'Nguyễn',
        ]);

        $person = (new HouseholdPersonService)->newPerson($household, true, null);

        self::assertSame('NAM', $person->gender);
        self::assertSame('An', $person->first_name);
        self::assertSame('Nguyễn', $person->last_name);
        self::assertSame('Chủ hộ', $person->relationship_with_head);
    }

    public function test_new_member_inherits_the_head_ethnicity_but_not_the_head_name(): void
    {
        $household = new Household([
            'head_first_name' => 'An',
            'head_last_name' => 'Nguyễn',
        ]);
        $head = new Person(['ethnicity_code' => '01']);

        $person = (new HouseholdPersonService)->newPerson($household, false, $head);

        self::assertSame('NAM', $person->gender);
        self::assertSame('01', $person->ethnicity_code);
        self::assertNull($person->first_name);
        self::assertNull($person->last_name);
        self::assertNull($person->relationship_with_head);
    }

    public function test_split_form_data_separates_person_education_and_disability_fields(): void
    {
        $data = [
            'first_name' => 'An',
            'school_province_code' => 'P01',
            'school_commune_code' => 'C01',
            'education' => ['school_year' => '2025-2026'],
            'disability' => ['autism' => 1],
        ];

        [$person, $education, $disability] = (new HouseholdPersonService)->splitFormData($data);

        self::assertSame(['first_name' => 'An'], $person);
        self::assertSame(['school_year' => '2025-2026', 'is_complementary' => false], $education);
        self::assertSame([
            'autism' => true,
            'mobility_disability' => false,
            'hearing_speech_disability' => false,
            'visual_disability' => false,
            'mental_disability' => false,
            'intellectual_disability' => false,
            'learning_disability' => false,
            'other_disability' => false,
            'has_disability_cert' => false,
            'can_study' => false,
        ], $disability);
    }

    #[DataProvider('relationshipAgeCases')]
    public function test_relationship_age_rule(
        string $relationship,
        string $birthDate,
        ?string $expectedMessage
    ): void {
        $head = new Person(['dob' => '1980-01-01']);
        $head->syncOriginal();

        self::assertSame(
            $expectedMessage,
            (new HouseholdPersonService)->relationshipAgeError($relationship, $birthDate, $head),
        );
    }

    public static function relationshipAgeCases(): array
    {
        return [
            'younger relationship rejects an older member' => [
                'Con',
                '1979-12-31',
                'Người có quan hệ Con phải nhỏ tuổi hơn chủ hộ.',
            ],
            'younger relationship accepts a later birth date' => ['Em', '1980-01-02', null],
            'older relationship rejects a younger member' => [
                'Mẹ',
                '1980-01-02',
                'Người có quan hệ Mẹ phải lớn tuổi hơn chủ hộ.',
            ],
            'older relationship accepts an earlier birth date' => ['Ông', '1979-12-31', null],
            'unconstrained relationship has no age error' => ['Vợ', '1980-01-01', null],
        ];
    }

    public function test_relationship_age_rule_is_skipped_without_a_head_birth_date_or_relationship(): void
    {
        $service = new HouseholdPersonService;
        $headWithoutBirthDate = new Person(['dob' => null]);
        $headWithoutBirthDate->syncOriginal();

        self::assertNull($service->relationshipAgeError('Con', '2000-01-01', $headWithoutBirthDate));
        self::assertNull($service->relationshipAgeError(null, '2000-01-01', new Person(['dob' => '1980-01-01'])));
    }

    public function test_school_year_boundary_starts_the_new_year_in_august(): void
    {
        Carbon::setTestNow('2025-07-31');
        $service = new HouseholdPersonService;
        self::assertSame('2024-2025', $service->currentSchoolYear());

        Carbon::setTestNow('2025-08-01');
        self::assertSame('2025-2026', $service->currentSchoolYear());
    }
}
