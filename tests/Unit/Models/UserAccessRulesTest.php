<?php

namespace Tests\Unit\Models;

use App\Models\Role;
use App\Models\User;
use App\Support\AccessPermission;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

class UserAccessRulesTest extends TestCase
{
    public function test_system_admin_has_global_scope_and_bypasses_permission_checks(): void
    {
        $user = new User;
        $user->setRelation('roles', new Collection([
            new Role(['name' => Role::SYSTEM_ADMIN, 'guard_name' => 'web']),
        ]));

        self::assertSame('global', $user->roleScopeLevel());
        self::assertTrue($user->hasPermissionTo(AccessPermission::MANAGE_SCHOOLS));
        self::assertTrue($user->hasPermissionTo('unknown-permission'));
    }

    public function test_non_admin_scope_level_comes_from_the_account_assignment(): void
    {
        $user = new User;
        $user->setRawAttributes(['scope_level' => 'commune']);
        $user->setRelation('roles', new Collection);

        self::assertSame('commune', $user->roleScopeLevel());
    }

    public function test_invalid_scope_level_does_not_grant_business_permissions(): void
    {
        $user = new User;
        $user->setRawAttributes(['scope_level' => 'invalid']);
        $user->setRelation('roles', new Collection);

        self::assertNull($user->roleScopeLevel());
        self::assertFalse($user->hasPermissionTo(AccessPermission::VIEW_HOUSEHOLDS));
    }

    public function test_village_scope_cannot_manage_schools_or_villages(): void
    {
        $user = new User;
        $user->setRawAttributes(['scope_level' => 'village']);
        $user->setRelation('roles', new Collection);

        self::assertFalse($user->hasPermissionTo(AccessPermission::MANAGE_SCHOOLS));
        self::assertFalse($user->hasPermissionTo(AccessPermission::MANAGE_VILLAGES));
    }

    public function test_has_role_respects_the_configured_guard(): void
    {
        config(['auth.defaults.guard' => 'web']);
        $user = new User;
        $user->setRelation('roles', new Collection([
            new Role(['name' => 'manager', 'guard_name' => 'api']),
        ]));

        self::assertFalse($user->hasRole('manager'));
    }
}
