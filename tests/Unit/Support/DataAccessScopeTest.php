<?php

namespace Tests\Unit\Support;

use App\Models\Household;
use App\Models\Role;
use App\Models\User;
use App\Support\DataAccessScope;
use Illuminate\Database\Eloquent\Builder;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DataAccessScopeTest extends TestCase
{
    public function test_household_query_is_limited_to_the_users_assigned_area(): void
    {
        $cases = [
            'province' => ['province_code', 'P01', ['province_code' => 'P01']],
            'commune' => ['commune_code', 'C02', ['commune_code' => 'C02']],
            'village' => ['village_code', ['V03', 'V04'], ['village_code', ['V03', 'V04']]],
        ];

        foreach ($cases as $scope => [$column, $expectedBindings, $attributes]) {
            $user = $this->userForScope($scope, $attributes);
            if ($scope === 'village') {
                $user->shouldReceive('managedVillageCodes')->once()->andReturn($expectedBindings);
                $user->shouldReceive('managedVillageCodes')->once()->andReturn($expectedBindings);
            }
            $query = DataAccessScope::households(Household::query(), $user);

            self::assertStringContainsString($column, $query->toSql(), $scope);
            self::assertSame(
                is_array($expectedBindings) ? $expectedBindings : [$expectedBindings],
                $query->getBindings(),
                $scope,
            );
        }
    }

    public function test_household_query_fails_closed_for_missing_or_unknown_scope_data(): void
    {
        foreach ([
            ['province', ['province_code' => null]],
            ['commune', ['commune_code' => null]],
            ['unknown', []],
        ] as [$scope, $attributes]) {
            $user = $this->userForScope($scope, $attributes);
            $query = DataAccessScope::households(Household::query(), $user);

            self::assertStringContainsString('1 = 0', $query->toSql(), $scope);
        }

        $villageUser = $this->userForScope('village', []);
        $villageUser->shouldReceive('managedVillageCodes')->once()->andReturn([]);
        $query = DataAccessScope::households(Household::query(), $villageUser);

        self::assertStringContainsString('1 = 0', $query->toSql());
    }

    public function test_system_admin_household_query_is_unrestricted(): void
    {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('hasRole')->once()->with(Role::SYSTEM_ADMIN)->andReturnTrue();
        $query = DataAccessScope::households(Household::query(), $user);

        self::assertSame([], $query->getBindings());
        self::assertStringNotContainsString('where', strtolower($query->toSql()));
    }

    public function test_household_access_checks_match_area_and_reject_mismatches(): void
    {
        $household = new Household([
            'province_code' => 'P01',
            'commune_code' => 'C02',
            'village_code' => 'V03',
        ]);
        $user = $this->userForScope('commune', ['commune_code' => 'C02']);

        self::assertTrue(DataAccessScope::canAccessHousehold($user, $household));

        $otherCommune = $this->userForScope('commune', ['commune_code' => 'C99']);
        self::assertFalse(DataAccessScope::canAccessHousehold($otherCommune, $household));
    }

    public function test_household_location_assertion_rejects_a_location_outside_the_scope(): void
    {
        $user = $this->userForScope('commune', [
            'province_code' => 'P01',
            'commune_code' => 'C02',
        ]);

        try {
            DataAccessScope::assertHouseholdLocation($user, [
                'province_code' => 'P01',
                'commune_code' => 'C99',
            ]);
            self::fail('Expected an out-of-scope household location to be forbidden.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
    }

    private function userForScope(string $scope, array $attributes): User&\Mockery\MockInterface
    {
        $user = new User;
        $user->setRawAttributes($attributes);
        $user = Mockery::mock($user)->makePartial();
        $user->shouldReceive('hasRole')->once()->with(Role::SYSTEM_ADMIN)->andReturnFalse();
        $user->shouldReceive('roleScopeLevel')->andReturn($scope);

        return $user;
    }
}
