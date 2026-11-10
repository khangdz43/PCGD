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
            'commune' => ['commune'],
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
        $actor->shouldReceive('can')->once()->with('view-users')->andReturnFalse();

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

    public function test_view_users_permission_allows_account_lists_without_account_management_rights(): void
    {
        $actor = Mockery::mock(User::class);
        $actor->shouldReceive('hasRole')->twice()->with(Role::SYSTEM_ADMIN)->andReturnFalse();
        $actor->shouldReceive('canManageSubordinateUsers')->once()->andReturnFalse();
        $actor->shouldReceive('can')->once()->with('view-users')->andReturnTrue();
        $actor->shouldReceive('roleScopeLevel')->once()->andReturn('global');

        self::assertSame(['province'], DelegatedAccess::manageableAccountLevels($actor));
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

    public function test_commune_actor_can_assign_only_a_nonempty_subset_of_its_villages(): void
    {
        $actor = $this->actorWithAttributes(['province_code' => 'P01', 'commune_code' => 'C02']);
        $actor->shouldReceive('hasRole')->with(Role::SYSTEM_ADMIN)->zeroOrMoreTimes()->andReturnFalse();
        $actor->shouldReceive('canManageSubordinateUsers')->zeroOrMoreTimes()->andReturnTrue();
        $actor->shouldReceive('roleScopeLevel')->zeroOrMoreTimes()->andReturn('commune');
        $actor->shouldReceive('managedVillageCodes')->zeroOrMoreTimes()->andReturn(['V03', 'V04']);

        self::assertTrue(DelegatedAccess::scopeIsAllowed(
            $actor,
            'commune',
            ['province_code' => 'P01', 'commune_code' => 'C02'],
            ['V03'],
        ));
        self::assertFalse(DelegatedAccess::scopeIsAllowed(
            $actor,
            'commune',
            ['province_code' => 'P01', 'commune_code' => 'C02'],
        ));
        self::assertFalse(DelegatedAccess::scopeIsAllowed(
            $actor,
            'commune',
            ['province_code' => 'P01', 'commune_code' => 'C02'],
            ['V05'],
        ));
    }

    public function test_role_assignment_is_bound_to_the_creator_and_manageable_scope(): void
    {
        $actor = $this->actorWithAttributes(['id' => 8]);
        $actor->shouldReceive('hasRole')->with(Role::SYSTEM_ADMIN)->zeroOrMoreTimes()->andReturnFalse();
        $actor->shouldReceive('roleScopeLevel')->zeroOrMoreTimes()->andReturn('commune');

        self::assertTrue(DelegatedAccess::roleBelongsToActorArea(
            $actor,
            new Role(['scope_level' => 'commune', 'created_by' => 8]),
        ));
        self::assertFalse(DelegatedAccess::roleBelongsToActorArea(
            $actor,
            new Role(['scope_level' => 'province', 'created_by' => 8]),
        ));
        self::assertFalse(DelegatedAccess::roleBelongsToActorArea(
            $actor,
            new Role(['scope_level' => 'commune', 'created_by' => 99]),
        ));
    }

    private function actorWithAttributes(array $attributes): User&\Mockery\MockInterface
    {
        $actor = new User;
        $actor->setRawAttributes($attributes);

        return Mockery::mock($actor)->makePartial();
    }
}
