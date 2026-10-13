<?php

namespace Tests\Unit\Support;

use App\Models\Role;
use App\Models\User;
use App\Support\DelegatedAccess;
use Mockery;
use Tests\TestCase;

class DelegatedAccessTest extends TestCase
{
    public function test_system_admin_can_manage_global_and_province_accounts(): void
    {
        $actor = Mockery::mock(User::class);
        $actor->shouldReceive('hasRole')->once()->with(Role::SYSTEM_ADMIN)->andReturnTrue();

        self::assertSame(['global', 'province'], DelegatedAccess::manageableRoleLevels($actor));

        $actor = Mockery::mock(User::class);
        $actor->shouldReceive('hasRole')->twice()->with(Role::SYSTEM_ADMIN)->andReturnTrue();
        $actor->shouldReceive('canManageSubordinateUsers')->never();

        self::assertSame(['global', 'province'], DelegatedAccess::manageableAccountLevels($actor));
    }

    public function test_non_admin_role_levels_follow_the_actor_scope_hierarchy(): void
    {
        $cases = [
            'global' => ['province'],
            'province' => ['province', 'commune'],
            'commune' => ['commune', 'village'],
            'village' => [],
        ];

        foreach ($cases as $scope => $expected) {
            $actor = Mockery::mock(User::class);
            $actor->shouldReceive('hasRole')->once()->with(Role::SYSTEM_ADMIN)->andReturnFalse();
            $actor->shouldReceive('roleScopeLevel')->once()->andReturn($scope);

            self::assertSame($expected, DelegatedAccess::manageableRoleLevels($actor), $scope);
        }
    }

    public function test_account_management_requires_permission_and_fails_closed_for_unknown_scope(): void
    {
        $actor = Mockery::mock(User::class);
        $actor->shouldReceive('hasRole')->once()->with(Role::SYSTEM_ADMIN)->andReturnFalse();
        $actor->shouldReceive('canManageSubordinateUsers')->once()->andReturnFalse();

        try {
            DelegatedAccess::manageableAccountLevels($actor);
            self::fail('Expected an actor without account-management permission to be forbidden.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }

        $actor = Mockery::mock(User::class);
        $actor->shouldReceive('hasRole')->twice()->with(Role::SYSTEM_ADMIN)->andReturnFalse();
        $actor->shouldReceive('canManageSubordinateUsers')->once()->andReturnTrue();
        $actor->shouldReceive('roleScopeLevel')->once()->andReturn('unsupported');

        try {
            DelegatedAccess::manageableAccountLevels($actor);
            self::fail('Expected an unsupported scope to be forbidden.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_province_actor_can_create_only_province_and_commune_scope_assignments(): void
    {
        $actor = $this->actorWithAttributes(['province_code' => 'P01', 'commune_code' => null]);
        $actor->shouldReceive('hasRole')->with(Role::SYSTEM_ADMIN)->zeroOrMoreTimes()->andReturnFalse();
        $actor->shouldReceive('canManageSubordinateUsers')->zeroOrMoreTimes()->andReturnTrue();
        $actor->shouldReceive('roleScopeLevel')->zeroOrMoreTimes()->andReturn('province');

        self::assertTrue(DelegatedAccess::scopeIsAllowed(
            $actor,
            'commune',
            ['province_code' => 'P01', 'commune_code' => 'C02'],
        ));
        self::assertFalse(DelegatedAccess::scopeIsAllowed(
            $actor,
            'commune',
            ['province_code' => 'P02', 'commune_code' => 'C02'],
        ));
        self::assertFalse(DelegatedAccess::scopeIsAllowed(
            $actor,
            'village',
            ['province_code' => 'P01', 'commune_code' => 'C02'],
            ['V03'],
        ));
    }

    public function test_village_actor_cannot_assign_account_scopes(): void
    {
        $actor = $this->actorWithAttributes(['province_code' => 'P01', 'commune_code' => 'C02']);
        $actor->shouldReceive('hasRole')->with(Role::SYSTEM_ADMIN)->zeroOrMoreTimes()->andReturnFalse();
        $actor->shouldReceive('canManageSubordinateUsers')->zeroOrMoreTimes()->andReturnTrue();
        $actor->shouldReceive('roleScopeLevel')->zeroOrMoreTimes()->andReturn('village');
        $actor->shouldReceive('managedVillageCodes')->zeroOrMoreTimes()->andReturn(['V03']);

        self::assertFalse(DelegatedAccess::scopeIsAllowed(
            $actor,
            'village',
            ['province_code' => 'P01', 'commune_code' => 'C02'],
            ['V03'],
        ));
        self::assertFalse(DelegatedAccess::scopeIsAllowed(
            $actor,
            'village',
            ['province_code' => 'P01', 'commune_code' => 'C02'],
            ['V04'],
        ));
    }

    public function test_role_area_is_global_for_system_admin_and_local_for_territorial_actor(): void
    {
        $admin = Mockery::mock(User::class);
        $admin->shouldReceive('hasRole')->once()->with(Role::SYSTEM_ADMIN)->andReturnTrue();
        self::assertSame(
            ['province_code' => null, 'commune_code' => null],
            DelegatedAccess::roleAreaFor($admin),
        );

        $actor = $this->actorWithAttributes(['province_code' => 'P01', 'commune_code' => 'C02']);
        $actor->shouldReceive('hasRole')->once()->with(Role::SYSTEM_ADMIN)->andReturnFalse();
        $actor->shouldReceive('roleScopeLevel')->twice()->andReturn('commune');
        self::assertSame(
            ['province_code' => 'P01', 'commune_code' => 'C02'],
            DelegatedAccess::roleAreaFor($actor),
        );
    }

    private function actorWithAttributes(array $attributes): User&\Mockery\MockInterface
    {
        $actor = new User;
        $actor->setRawAttributes($attributes);

        return Mockery::mock($actor)->makePartial();
    }
}
