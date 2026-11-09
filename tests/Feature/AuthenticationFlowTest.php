<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\Permission;
use App\Support\AccessPermission;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuthenticationFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('username')->unique();
            $table->string('citizen_id', 12)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('phone', 20)->nullable();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->string('province_code')->nullable();
            $table->string('commune_code')->nullable();
            $table->string('scope_level')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->string('scope_level');
            $table->timestamps();
        });
        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->foreignId('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->primary(['role_id', 'model_id', 'model_type']);
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->string('description')->nullable();
            $table->timestamps();
        });
        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->foreignId('role_id');
            $table->foreignId('permission_id');
            $table->primary(['role_id', 'permission_id']);
        });
    }

    public function test_guest_can_get_csrf_cookie_but_cannot_read_session(): void
    {
        $this->get('/api/v1/csrf-cookie')
            ->assertNoContent()
            ->assertCookie('XSRF-TOKEN');

        $this->getJson('/api/v1/session')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_api_login_creates_a_reusable_session_and_returns_json_user_data(): void
    {
        $user = User::create([
            'name' => 'Người dùng API',
            'username' => 'api-user',
            'password' => Hash::make('Correct-password-1'),
            'is_active' => true,
            'scope_level' => 'global',
        ])->fresh();
        $role = Role::create([
            'name' => 'Nhóm người dùng trung ương',
            'guard_name' => 'web',
            'scope_level' => 'global',
        ]);
        $user->roles()->attach($role);

        $this->get('/api/v1/csrf-cookie')->assertNoContent();

        $this->postJson('/api/v1/login', [
            'username' => 'api-user',
            'password' => 'Correct-password-1',
            'remember' => true,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.username', 'api-user')
            ->assertJsonPath('data.user.role_name', 'Nhóm người dùng trung ương')
            ->assertJsonMissingPath('data.access_token')
            ->assertJsonMissingPath('data.refresh_token');

        $this->assertAuthenticatedAs($user, 'web');

        $this->getJson('/api/v1/session')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.scope_level_label', 'Toàn hệ thống')
            ->assertJsonPath('data.user.scope_area', 'Toàn hệ thống')
            ->assertJsonPath(
                'data.user.school_year',
                (now()->month >= 8 ? now()->year : now()->year - 1).'-'.((now()->month >= 8 ? now()->year : now()->year - 1) + 1),
            );

        Auth::forgetGuards();
        $this->getJson('/api/v1/session')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);
    }

    public function test_api_login_rejects_invalid_credentials_as_json(): void
    {
        $this->postJson('/api/v1/login', [
            'username' => 'missing-user',
            'password' => 'incorrect',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('status_code', 422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.details.0.field', 'username');
    }

    public function test_api_logout_ends_the_session_and_subsequent_requests_are_unauthorized(): void
    {
        $user = User::create([
            'name' => 'Người dùng API',
            'username' => 'api-user',
            'password' => Hash::make('Correct-password-1'),
            'is_active' => true,
        ])->fresh();
        $session = $this->actingAs($user, 'web')->getJson('/api/v1/session');
        $this->assertSame(200, $session->getStatusCode(), $session->getContent());

        $this->postJson('/api/v1/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Đăng xuất thành công.')
            ->assertJsonPath('data', null);

        Auth::forgetGuards();
        $this->getJson('/api/v1/session')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_inactive_account_is_logged_out_from_session_api(): void
    {
        $user = User::create([
            'name' => 'Người dùng API',
            'username' => 'api-user',
            'password' => Hash::make('Correct-password-1'),
        ]);
        $user->forceFill(['is_active' => true])->save();

        $this->actingAs($user, 'web')
            ->getJson('/api/v1/session')
            ->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($user->fresh(), 'web')
            ->getJson('/api/v1/session')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->assertGuest('web');
    }

    public function test_system_and_territorial_admins_get_dashboard_overviews_for_their_scope(): void
    {
        Schema::create('provinces', function (Blueprint $table): void {
            $table->string('code')->primary();
            $table->string('name');
        });
        Schema::create('communes', function (Blueprint $table): void {
            $table->string('code')->primary();
            $table->string('name')->nullable();
            $table->string('province_code');
        });
        Schema::create('villages', function (Blueprint $table): void {
            $table->string('code')->primary();
            $table->string('name')->nullable();
            $table->string('commune_code');
            $table->string('merged_into_code')->nullable();
        });
        Schema::create('schools', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('province_code')->nullable();
            $table->string('commune_code')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('households', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_year_id')->nullable();
            $table->string('province_code')->nullable();
            $table->string('commune_code')->nullable();
            $table->string('village_code')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('persons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('household_id');
            $table->date('dob')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('school_years', function (Blueprint $table): void {
            $table->id();
            $table->date('start_time');
            $table->date('end_time');
            $table->boolean('status')->default(false);
        });
        Schema::create('person_educations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('person_id');
            $table->foreignId('school_year_id');
            $table->string('academic_block')->nullable();
            $table->string('current_class')->nullable();
            $table->string('graduation_level')->nullable();
            $table->string('finished_class')->nullable();
            $table->string('dropped_class')->nullable();
            $table->string('completed_class')->nullable();
            $table->string('literacy_current_class')->nullable();
            $table->string('literacy_completed_class')->nullable();
            $table->string('literacy_relapse_level')->nullable();
            $table->string('learning_capacity')->nullable();
        });

        DB::table('provinces')->insert([
            ['code' => 'P01', 'name' => 'Tỉnh Một'],
            ['code' => 'P02', 'name' => 'Tỉnh Hai'],
        ]);
        DB::table('communes')->insert([
            ['code' => 'C01', 'name' => 'Xã Một', 'province_code' => 'P01'],
            ['code' => 'C02', 'name' => 'Xã Hai', 'province_code' => 'P02'],
        ]);
        DB::table('villages')->insert([
            ['code' => 'V01', 'name' => 'Thôn Một', 'commune_code' => 'C01'],
            ['code' => 'V02', 'name' => 'Thôn Hai', 'commune_code' => 'C02'],
        ]);
        DB::table('schools')->insert([
            'code' => 'S01',
            'name' => 'Trường Một',
            'province_code' => 'P01',
            'commune_code' => 'C01',
        ]);

        $schoolYear = (new \App\Services\Households\HouseholdService)->currentSchoolYear();
        $schoolYearStart = (int) substr($schoolYear, 0, 4);
        DB::table('school_years')->insert([
            ['id' => 1, 'start_time' => "{$schoolYearStart}-08-01", 'end_time' => ($schoolYearStart + 1).'-07-31', 'status' => true],
            ['id' => 2, 'start_time' => '2020-08-01', 'end_time' => '2021-07-31', 'status' => false],
        ]);
        DB::table('households')->insert([
            ['school_year_id' => 1, 'province_code' => 'P01', 'commune_code' => 'C01', 'village_code' => 'V01'],
            ['school_year_id' => 1, 'province_code' => 'P01', 'commune_code' => 'C01', 'village_code' => 'V01'],
            ['school_year_id' => 2, 'province_code' => 'P02', 'commune_code' => null, 'village_code' => null],
            ['school_year_id' => 1, 'province_code' => 'P02', 'commune_code' => 'C02', 'village_code' => 'V02'],
        ]);
        DB::table('persons')->insert([
            ['household_id' => 1, 'dob' => null],
            ['household_id' => 1, 'dob' => null],
            ['household_id' => 2, 'dob' => null],
            ['household_id' => 3, 'dob' => null],
            ['household_id' => 1, 'dob' => ($schoolYearStart - 5).'-06-01'],
            ['household_id' => 1, 'dob' => ($schoolYearStart - 8).'-06-01'],
            ['household_id' => 2, 'dob' => ($schoolYearStart - 15).'-06-01'],
            ['household_id' => 2, 'dob' => ($schoolYearStart - 25).'-06-01'],
            ['household_id' => 2, 'dob' => ($schoolYearStart - 10).'-06-01'],
            ['household_id' => 4, 'dob' => ($schoolYearStart - 5).'-06-01'],
        ]);
        DB::table('person_educations')->insert([
            [
                'person_id' => 5,
                'school_year_id' => 1,
                'academic_block' => '5-6 tuổi',
                'current_class' => 'MN5A',
                'graduation_level' => 'MN',
                'completed_class' => null,
                'literacy_current_class' => null,
                'literacy_completed_class' => null,
                'literacy_relapse_level' => null,
                'learning_capacity' => null,
            ],
            [
                'person_id' => 6,
                'school_year_id' => 1,
                'academic_block' => '3',
                'current_class' => '3A',
                'graduation_level' => null,
                'completed_class' => null,
                'literacy_current_class' => null,
                'literacy_completed_class' => null,
                'literacy_relapse_level' => null,
                'learning_capacity' => null,
            ],
            [
                'person_id' => 7,
                'school_year_id' => 1,
                'academic_block' => '7',
                'current_class' => '7A',
                'graduation_level' => 'THCS',
                'completed_class' => null,
                'literacy_current_class' => null,
                'literacy_completed_class' => '3',
                'literacy_relapse_level' => '1',
                'learning_capacity' => null,
            ],
            [
                'person_id' => 8,
                'school_year_id' => 1,
                'academic_block' => null,
                'current_class' => null,
                'graduation_level' => null,
                'completed_class' => null,
                'literacy_current_class' => '1',
                'literacy_completed_class' => null,
                'literacy_relapse_level' => null,
                'learning_capacity' => null,
            ],
        ]);

        $user = User::create([
            'name' => 'Quản trị hệ thống',
            'username' => 'central-admin',
            'password' => Hash::make('Correct-password-1'),
        ]);
        $user->forceFill(['is_active' => true])->save();
        $this->actingAs($user, 'web')
            ->getJson('/api/v1/dashboard/overview')
            ->assertForbidden();

        $systemRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'guard_name' => 'web',
            'scope_level' => 'global',
        ]);
        $user->roles()->attach($systemRole);
        $user->unsetRelation('roles');

        $this->getJson('/api/v1/dashboard/overview')
            ->assertOk()
            ->assertJsonPath('data.school_year', $schoolYear)
            ->assertJsonPath('data.totals.provinces', 2)
            ->assertJsonPath('data.totals.communes', 2)
            ->assertJsonPath('data.totals.villages', 2)
            ->assertJsonPath('data.totals.schools', 1)
            ->assertJsonPath('data.totals.active_users', 1)
            ->assertJsonPath('data.totals.households', 3)
            ->assertJsonPath('data.totals.persons', 9)
            ->assertJsonPath('data.age_reference_date', "{$schoolYearStart}-12-31")
            ->assertJsonPath('data.education_summary.preschool_age_5.population', 2)
            ->assertJsonPath('data.education_summary.preschool_age_5.studying', 1)
            ->assertJsonPath('data.education_summary.preschool_age_5.completed', 1)
            ->assertJsonPath('data.education_summary.primary_age_6_to_14.population', 2)
            ->assertJsonPath('data.education_summary.primary_age_6_to_14.studying', 1)
            ->assertJsonPath('data.education_summary.lower_secondary_age_11_to_18.population', 1)
            ->assertJsonPath('data.education_summary.lower_secondary_age_11_to_18.completed', 1)
            ->assertJsonPath('data.education_summary.literacy_age_15_to_60.population', 2)
            ->assertJsonPath('data.education_summary.literacy_age_15_to_60.studying', 1)
            ->assertJsonPath('data.education_summary.literacy_age_15_to_60.completed', 1)
            ->assertJsonPath('data.education_summary.literacy_age_15_to_60.relapse', 1)
            ->assertJsonPath('data.education_summary.data_quality.school_age_missing_education', 2)
            ->assertJsonPath('data.education_summary.data_quality.missing_birth_dates', 3)
            ->assertJsonPath('data.provinces.0.name', 'Tỉnh Một')
            ->assertJsonPath('data.provinces.0.households', 2)
            ->assertJsonPath('data.school_years.0', $schoolYear);

        $this->getJson('/api/v1/dashboard/overview?school_year=2020-2021')
            ->assertOk()
            ->assertJsonPath('data.school_year', '2020-2021')
            ->assertJsonPath('data.totals.households', 1)
            ->assertJsonPath('data.totals.persons', 1)
            ->assertJsonPath('data.provinces.0.name', 'Tỉnh Hai')
            ->assertJsonPath('data.provinces.0.households', 1);

        $this->getJson('/api/v1/dashboard/overview?school_year=not-a-school-year')
            ->assertUnprocessable();

        $provinceRole = Role::create([
            'name' => 'Quản trị tỉnh',
            'guard_name' => 'web',
            'scope_level' => 'province',
        ]);
        $regularRole = Role::create([
            'name' => 'Điều tra viên tỉnh',
            'guard_name' => 'web',
            'scope_level' => 'province',
        ]);
        $viewHouseholdsPermission = Permission::create([
            'name' => AccessPermission::VIEW_HOUSEHOLDS,
            'guard_name' => 'web',
        ]);
        $regularRole->permissions()->attach($viewHouseholdsPermission->id);
        $regularUser = User::create([
            'name' => 'Tài khoản thường',
            'username' => 'regular-province-user',
            'password' => Hash::make('Correct-password-1'),
        ]);
        $regularUser->forceFill([
            'is_active' => true,
            'scope_level' => 'province',
            'province_code' => 'P01',
        ])->save();
        $regularUser->roles()->attach($regularRole);
        $this->actingAs($regularUser, 'web')
            ->getJson('/api/v1/dashboard/overview')
            ->assertForbidden();

        $provinceAdmin = User::create([
            'name' => 'Quản trị tỉnh',
            'username' => 'province-admin',
            'password' => Hash::make('Correct-password-1'),
        ]);
        $provinceAdmin->forceFill([
            'is_active' => true,
            'scope_level' => 'province',
            'province_code' => 'P01',
        ])->save();
        $provinceAdmin->roles()->attach($provinceRole);

        $this->actingAs($provinceAdmin, 'web')
            ->getJson('/api/v1/session')
            ->assertOk()
            ->assertJsonPath('data.user.can_view_dashboard', true);

        $this->actingAs($provinceAdmin, 'web')
            ->getJson('/api/v1/dashboard/overview')
            ->assertOk()
            ->assertJsonPath('data.scope_level', 'province')
            ->assertJsonPath('data.scope_area', 'Tỉnh Một')
            ->assertJsonPath('data.totals.communes', 1)
            ->assertJsonPath('data.totals.households', 2)
            ->assertJsonPath('data.totals.persons', 8)
            ->assertJsonPath('data.education_summary.preschool_age_5.population', 1)
            ->assertJsonPath('data.units.0.name', 'Xã Một')
            ->assertJsonPath('data.units.0.households', 2);

        $communeRole = Role::create([
            'name' => 'Quản trị xã',
            'guard_name' => 'web',
            'scope_level' => 'commune',
        ]);
        $communeAdmin = User::create([
            'name' => 'Quản trị xã',
            'username' => 'commune-admin',
            'password' => Hash::make('Correct-password-1'),
        ]);
        $communeAdmin->forceFill([
            'is_active' => true,
            'scope_level' => 'commune',
            'province_code' => 'P01',
            'commune_code' => 'C01',
        ])->save();
        $communeAdmin->roles()->attach($communeRole);

        $this->actingAs($communeAdmin, 'web')
            ->getJson('/api/v1/dashboard/overview')
            ->assertOk()
            ->assertJsonPath('data.scope_level', 'commune')
            ->assertJsonPath('data.scope_area', 'Xã Một')
            ->assertJsonPath('data.totals.villages', 1)
            ->assertJsonPath('data.totals.households', 2)
            ->assertJsonPath('data.totals.persons', 8)
            ->assertJsonPath('data.education_summary.preschool_age_5.population', 1)
            ->assertJsonPath('data.units.0.name', 'Thôn Một')
            ->assertJsonPath('data.units.0.households', 2);
    }

    public function test_authenticated_user_can_update_basic_account_information(): void
    {
        $user = User::create([
            'name' => 'Người dùng cũ',
            'username' => 'profile-user',
            'email' => 'old@example.test',
            'password' => Hash::make('Correct-password-1'),
        ]);
        $user->forceFill(['is_active' => true])->save();

        $this->actingAs($user, 'web')
            ->patchJson('/api/v1/account', [
                'name' => 'Người dùng mới',
                'email' => 'new@example.test',
                'phone' => '0912345678',
                'citizen_id' => '123456789012',
                'date_of_birth' => '1990-04-15',
            ])
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Người dùng mới')
            ->assertJsonPath('data.user.email', 'new@example.test')
            ->assertJsonPath('data.user.phone', '0912345678')
            ->assertJsonPath('data.user.citizen_id', '123456789012')
            ->assertJsonPath('data.user.date_of_birth', '1990-04-15');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Người dùng mới',
            'email' => 'new@example.test',
            'phone' => '0912345678',
            'citizen_id' => '123456789012',
            'date_of_birth' => '1990-04-15 00:00:00',
        ]);
    }

    public function test_account_profile_accepts_international_phone_numbers_and_rejects_other_characters(): void
    {
        $user = User::create([
            'name' => 'Người dùng API',
            'username' => 'profile-phone-user',
            'password' => Hash::make('Correct-password-1'),
        ]);
        $user->forceFill(['is_active' => true])->save();
        $this->actingAs($user, 'web');

        $this->patchJson('/api/v1/account', [
            'name' => 'Người dùng API',
            'phone' => '+84912345678',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.phone', '+84912345678');

        $this->patchJson('/api/v1/account', [
            'name' => 'Người dùng API',
            'phone' => '+84 912345678',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'phone')
            ->assertJsonPath(
                'error.details.0.issue',
                'Số điện thoại chỉ được chứa chữ số hoặc dấu + ở đầu.',
            );
    }

    public function test_account_profile_rejects_invalid_or_duplicate_citizen_id_and_future_birth_date(): void
    {
        $user = User::create([
            'name' => 'Người dùng API',
            'username' => 'profile-user',
            'password' => Hash::make('Correct-password-1'),
        ]);
        $user->forceFill(['is_active' => true])->save();
        User::create([
            'name' => 'Người dùng khác',
            'username' => 'another-user',
            'citizen_id' => '123456789012',
            'password' => Hash::make('Correct-password-1'),
        ]);

        $this->actingAs($user, 'web')
            ->patchJson('/api/v1/account', [
                'name' => 'Người dùng API',
                'citizen_id' => '123456789012',
                'date_of_birth' => now()->addDay()->toDateString(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'citizen_id');

        $this->patchJson('/api/v1/account', [
            'name' => 'Người dùng API',
            'citizen_id' => '1234',
            'date_of_birth' => now()->addDay()->toDateString(),
        ])
            ->assertUnprocessable()
            ->assertJsonFragment(['field' => 'date_of_birth']);
    }

    public function test_account_profile_rejects_an_email_used_by_another_user(): void
    {
        $user = User::create([
            'name' => 'Người dùng API',
            'username' => 'profile-user',
            'password' => Hash::make('Correct-password-1'),
        ]);
        $user->forceFill(['is_active' => true])->save();
        User::create([
            'name' => 'Người dùng khác',
            'username' => 'another-user',
            'email' => 'used@example.test',
            'password' => Hash::make('Correct-password-1'),
        ]);

        $this->actingAs($user, 'web')
            ->patchJson('/api/v1/account', [
                'name' => 'Người dùng API',
                'email' => 'used@example.test',
                'phone' => '',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'email');
    }

    public function test_authenticated_user_can_change_password_only_after_validating_current_and_new_passwords(): void
    {
        $user = User::create([
            'name' => 'Người dùng API',
            'username' => 'password-user',
            'password' => Hash::make('Correct-password-1'),
        ]);
        $user->forceFill(['is_active' => true])->save();

        $this->actingAs($user, 'web')
            ->patchJson('/api/v1/account/password', [
                'current_password' => 'incorrect',
                'password' => 'New-password-123',
                'password_confirmation' => 'New-password-123',
            ])
            ->assertUnprocessable();

        $this->patchJson('/api/v1/account/password', [
            'current_password' => 'Correct-password-1',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertUnprocessable();

        $this->patchJson('/api/v1/account/password', [
            'current_password' => 'Correct-password-1',
            'password' => 'New-password-123',
            'password_confirmation' => 'New-password-123',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Đổi mật khẩu thành công.');

        self::assertTrue(Hash::check('New-password-123', $user->fresh()->password));
    }
}
