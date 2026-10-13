<?php

namespace Tests\Unit\Services\Households;

use App\Models\Role;
use App\Models\User;
use App\Services\Households\HouseholdService;
use Carbon\Carbon;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class HouseholdServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_default_filters_use_the_current_school_year_for_a_system_admin(): void
    {
        Carbon::setTestNow('2025-09-01');
        $user = Mockery::mock(User::class);
        $user->shouldReceive('hasRole')->once()->with(Role::SYSTEM_ADMIN)->andReturnTrue();

        self::assertSame(
            ['school_year' => '2025-2026'],
            (new HouseholdService)->defaultFilters($user),
        );
    }

    public function test_default_filters_are_limited_to_the_users_commune_and_single_managed_village(): void
    {
        Carbon::setTestNow('2025-05-01');
        $user = new User;
        $user->setRawAttributes(['province_code' => 'P01', 'commune_code' => 'C02']);
        /** @var User&MockInterface $user */
        $user = Mockery::mock($user)->makePartial();
        $user->shouldReceive('hasRole')->once()->with(Role::SYSTEM_ADMIN)->andReturnFalse();
        $user->shouldReceive('roleScopeLevel')->twice()->andReturn('village');
        $user->shouldReceive('managedVillageCodes')->once()->andReturn(['V03']);

        self::assertSame([
            'school_year' => '2024-2025',
            'province_code' => 'P01',
            'commune_code' => 'C02',
            'village_code' => 'V03',
        ], (new HouseholdService)->defaultFilters($user));
    }

    public function test_default_filters_do_not_force_a_village_when_multiple_are_managed(): void
    {
        $user = new User;
        $user->setRawAttributes(['province_code' => 'P01', 'commune_code' => 'C02']);
        /** @var User&MockInterface $user */
        $user = Mockery::mock($user)->makePartial();
        $user->shouldReceive('hasRole')->once()->with(Role::SYSTEM_ADMIN)->andReturnFalse();
        $user->shouldReceive('roleScopeLevel')->twice()->andReturn('village');
        $user->shouldReceive('managedVillageCodes')->once()->andReturn(['V03', 'V04']);

        $filters = (new HouseholdService)->defaultFilters($user);

        self::assertArrayNotHasKey('village_code', $filters);
        self::assertSame('P01', $filters['province_code']);
        self::assertSame('C02', $filters['commune_code']);
    }

    public function test_default_filters_omit_unassigned_location_codes(): void
    {
        $user = new User;
        $user->setRawAttributes(['province_code' => null, 'commune_code' => null]);
        /** @var User&MockInterface $user */
        $user = Mockery::mock($user)->makePartial();
        $user->shouldReceive('hasRole')->once()->with(Role::SYSTEM_ADMIN)->andReturnFalse();
        $user->shouldReceive('roleScopeLevel')->twice()->andReturn('province');

        self::assertSame(
            ['school_year' => (new HouseholdService)->currentSchoolYear()],
            (new HouseholdService)->defaultFilters($user),
        );
    }

    public function test_school_year_boundary_starts_the_new_year_in_august(): void
    {
        Carbon::setTestNow('2025-07-31');
        $service = new HouseholdService;
        self::assertSame('2024-2025', $service->currentSchoolYear());
        self::assertSame('2024-2025', $service->schoolYears()[4]);

        Carbon::setTestNow('2025-08-01');
        self::assertSame('2025-2026', $service->currentSchoolYear());
        self::assertSame('2025-2026', $service->schoolYears()[5]);
    }
}
