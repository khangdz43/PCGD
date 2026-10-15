<?php

namespace Tests\Unit\Services\Admin\AccessControl;

use App\Models\Role;
use App\Models\User;
use App\Services\Admin\AccessControl\RoleManagementService;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class RoleManagementServiceTest extends TestCase
{
    public function test_system_admin_defaults_to_global_when_available(): void
    {
        $actor = Mockery::mock(User::class);
        $actor->shouldReceive('hasRole')->once()->with(Role::SYSTEM_ADMIN)->andReturnTrue();

        self::assertSame(
            'global',
            (new RoleManagementService)->defaultScopeLevel($actor, ['province', 'global']),
        );
    }

    public function test_actor_defaults_to_their_scope_when_it_is_allowed(): void
    {
        $actor = Mockery::mock(User::class);
        $actor->shouldReceive('hasRole')->once()->with(Role::SYSTEM_ADMIN)->andReturnFalse();
        $actor->shouldReceive('roleScopeLevel')->once()->andReturn('commune');

        self::assertSame(
            'commune',
            (new RoleManagementService)->defaultScopeLevel($actor, ['province', 'commune', 'village']),
        );
    }

    public function test_actor_scope_falls_back_to_the_first_available_level(): void
    {
        $actor = Mockery::mock(User::class);
        $actor->shouldReceive('hasRole')->once()->with(Role::SYSTEM_ADMIN)->andReturnFalse();
        $actor->shouldReceive('roleScopeLevel')->once()->andReturn('commune');

        self::assertSame(
            'province',
            (new RoleManagementService)->defaultScopeLevel($actor, ['province', 'village']),
        );
    }

    public function test_system_admin_can_manage_only_central_roles_and_global_roles_they_did_not_create(): void
    {
        $service = new RoleManagementService;
        $admin = Mockery::mock(User::class);
        $admin->shouldReceive('hasRole')->once()->with(Role::SYSTEM_ADMIN)->andReturnTrue();

        self::assertTrue($service->canManageRole(
            $admin,
            new Role(['name' => Role::SYSTEM_ADMIN, 'guard_name' => 'web']),
        ));

        $admin = Mockery::mock(User::class);
        $admin->shouldReceive('hasRole')->twice()->with(Role::SYSTEM_ADMIN)->andReturnTrue();
        self::assertTrue($service->canManageRole(
            $admin,
            new Role(['name' => 'province-role', 'guard_name' => 'web', 'scope_level' => 'province']),
        ));

        $admin = Mockery::mock(User::class);
        $admin->shouldReceive('hasRole')->once()->with(Role::SYSTEM_ADMIN)->andReturnTrue();
        self::assertFalse($service->canManageRole(
            $admin,
            new Role([
                'name' => 'created-role',
                'guard_name' => 'web',
                'scope_level' => 'province',
                'created_by' => 12,
            ]),
        ));
    }

    public function test_non_web_role_is_never_manageable(): void
    {
        $actor = Mockery::mock(User::class);
        $actor->shouldReceive('hasRole')->never();

        self::assertFalse((new RoleManagementService)->canManageRole(
            $actor,
            new Role(['name' => 'api-role', 'guard_name' => 'api']),
        ));
    }

    public function test_non_admin_can_manage_only_roles_created_by_them_inside_their_area(): void
    {
        $actor = new User;
        $actor->setRawAttributes(['id' => 8, 'province_code' => 'P01', 'commune_code' => 'C02']);
        /** @var User&MockInterface $actor */
        $actor = Mockery::mock($actor)->makePartial();
        $actor->shouldReceive('hasRole')->with(Role::SYSTEM_ADMIN)->zeroOrMoreTimes()->andReturnFalse();
        $actor->shouldReceive('roleScopeLevel')->zeroOrMoreTimes()->andReturn('commune');

        $service = new RoleManagementService;

        self::assertTrue($service->canManageRole(
            $actor,
            new Role([
                'name' => 'commune-role',
                'guard_name' => 'web',
                'scope_level' => 'commune',
                'created_by' => 8,
                'province_code' => 'P01',
                'commune_code' => 'C02',
            ]),
        ));
        self::assertFalse($service->canManageRole(
            $actor,
            new Role([
                'name' => 'other-area-role',
                'guard_name' => 'web',
                'scope_level' => 'commune',
                'created_by' => 8,
                'province_code' => 'P01',
                'commune_code' => 'C99',
            ]),
        ));
        self::assertFalse($service->canManageRole(
            $actor,
            new Role([
                'name' => 'other-creator-role',
                'guard_name' => 'web',
                'scope_level' => 'commune',
                'created_by' => 99,
                'province_code' => 'P01',
                'commune_code' => 'C02',
            ]),
        ));
    }
}
