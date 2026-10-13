<?php

namespace Tests\Unit\Services\Profile;

use App\Models\User;
use App\Services\Profile\AccountProfileService;
use App\Support\AccessPermission;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class AccountProfileServiceTest extends TestCase
{
    public function test_view_data_labels_global_scope_and_resolves_permission_names(): void
    {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('load')->once()->andReturnSelf();
        $user->shouldReceive('roleScopeLevel')->once()->andReturn('global');
        $user->shouldReceive('permissionNames')->once()->andReturn([
            AccessPermission::MANAGE_ACCESS_CONTROL,
            'unknown-permission',
        ]);

        $data = (new AccountProfileService)->viewData($user);

        self::assertSame('Toàn hệ thống', $data['scopeLevelLabel']);
        self::assertEquals(new Collection(['Toàn hệ thống']), $data['scopeAreas']);
        self::assertSame([
            ['name' => AccessPermission::MANAGE_ACCESS_CONTROL, 'label' => 'Quản lý nhóm người dùng'],
            ['name' => 'unknown-permission', 'label' => 'unknown-permission'],
        ], $data['permissions']->all());
        self::assertSame($user, $data['user']);
    }

    public function test_view_data_falls_back_to_the_assigned_province_code(): void
    {
        $user = new User;
        $user->setRawAttributes(['province_code' => 'P01']);
        $user->setRelation('province', null);
        $user = Mockery::mock($user)->makePartial();
        $user->shouldReceive('load')->once()->andReturnSelf();
        $user->shouldReceive('roleScopeLevel')->once()->andReturn('province');
        $user->shouldReceive('permissionNames')->once()->andReturn([]);

        $data = (new AccountProfileService)->viewData($user);

        self::assertSame('Cấp tỉnh', $data['scopeLevelLabel']);
        self::assertSame(['Mã tỉnh / thành phố P01'], $data['scopeAreas']->all());
        self::assertSame([], $data['permissions']->all());
    }

    public function test_view_data_reports_an_unassigned_area_for_an_unknown_scope(): void
    {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('load')->once()->andReturnSelf();
        $user->shouldReceive('roleScopeLevel')->once()->andReturn(null);
        $user->shouldReceive('permissionNames')->once()->andReturn([]);

        $data = (new AccountProfileService)->viewData($user);

        self::assertSame('Chưa xác định', $data['scopeLevelLabel']);
        self::assertSame(['Chưa được gán địa bàn'], $data['scopeAreas']->all());
    }
}
