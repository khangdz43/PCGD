<?php

namespace Tests\Unit\Services\Profile;

use App\Models\User;
use App\Services\Profile\AccountProfileService;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class AccountProfileServiceTest extends TestCase
{
    public function test_view_data_returns_only_basic_account_information_with_formatted_birth_date(): void
    {
        $user = new User;
        $user->forceFill([
            'name' => 'Người dùng thử',
            'username' => 'test-user',
            'citizen_id' => '123456789012',
            'date_of_birth' => '1990-04-15',
            'email' => 'user@example.test',
            'phone' => '0912345678',
        ]);

        self::assertSame([
            'user' => [
                'name' => 'Người dùng thử',
                'username' => 'test-user',
                'citizen_id' => '123456789012',
                'date_of_birth' => '1990-04-15',
                'email' => 'user@example.test',
                'phone' => '0912345678',
            ],
        ], (new AccountProfileService)->viewData($user));
    }

    public function test_scope_summary_labels_global_scope(): void
    {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('load')->once()->andReturnSelf();
        $user->shouldReceive('roleScopeLevel')->once()->andReturn('global');

        $data = (new AccountProfileService)->scopeSummary($user);

        self::assertSame('Toàn hệ thống', $data['scope_level_label']);
        self::assertEquals(new Collection(['Toàn hệ thống']), $data['scope_areas']);
    }

    public function test_scope_summary_falls_back_to_the_assigned_province_code(): void
    {
        $user = new User;
        $user->setRawAttributes(['province_code' => 'P01']);
        $user->setRelation('province', null);
        $user = Mockery::mock($user)->makePartial();
        $user->shouldReceive('load')->once()->andReturnSelf();
        $user->shouldReceive('roleScopeLevel')->once()->andReturn('province');

        $data = (new AccountProfileService)->scopeSummary($user);

        self::assertSame('Cấp tỉnh', $data['scope_level_label']);
        self::assertSame(['Mã tỉnh / thành phố P01'], $data['scope_areas']->all());
    }

    public function test_scope_summary_reports_an_unassigned_area_for_an_unknown_scope(): void
    {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('load')->once()->andReturnSelf();
        $user->shouldReceive('roleScopeLevel')->once()->andReturn(null);

        $data = (new AccountProfileService)->scopeSummary($user);

        self::assertSame('Chưa xác định', $data['scope_level_label']);
        self::assertSame(['Chưa được gán địa bàn'], $data['scope_areas']->all());
    }
}
