<?php

namespace Tests\Feature;

use App\Http\Controllers\Households\HouseholdImportExportController;
use App\Jobs\ProcessHouseholdImport;
use App\Models\Household;
use App\Models\ImportLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Households\HouseholdImportProcessingService;
use App\Support\AccessPermission;
use App\Support\DataAccessScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('name');
            $table->string('username')->nullable()->unique();
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
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
            $table->string('guard_name')->default('web');
            $table->string('scope_level')->default('global');
            $table->string('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('province_code')->nullable();
            $table->string('commune_code')->nullable();
            $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name')->default('web');
            $table->string('description')->nullable();
            $table->timestamps();
        });
        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->foreignId('role_id');
            $table->foreignId('permission_id');
            $table->primary(['role_id', 'permission_id']);
        });
        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->foreignId('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->primary(['role_id', 'model_id', 'model_type']);
        });
        Schema::create('provinces', function (Blueprint $table): void {
            $table->string('code')->primary();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('communes', function (Blueprint $table): void {
            $table->string('code')->primary();
            $table->string('name');
            $table->string('province_code');
            $table->timestamps();
        });
        Schema::create('villages', function (Blueprint $table): void {
            $table->string('code')->primary();
            $table->string('name');
            $table->string('commune_code');
            $table->timestamps();
        });
        Schema::create('user_villages', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->string('village_code');
            $table->primary(['user_id', 'village_code']);
        });
        Schema::create('schools', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->string('province_code')->nullable();
            $table->string('commune_code')->nullable();
            $table->timestamps();
        });
        Schema::create('persons', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('household_id');
            $table->timestamps();
        });
        Schema::create('person_educations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('person_id');
            $table->string('school_code')->nullable();
            $table->timestamps();
        });
        Schema::create('households', function (Blueprint $table): void {
            $table->id();
            $table->string('household_code');
            $table->string('school_year');
            $table->string('head_first_name')->nullable();
            $table->string('head_last_name')->nullable();
            $table->string('province_code')->nullable();
            $table->string('commune_code')->nullable();
            $table->string('village_code')->nullable();
            $table->timestamps();
        });
    }

    public function test_login_page_is_public_but_application_pages_require_authentication(): void
    {
        $this->get('/login')->assertOk();
        $this->get('/households')->assertRedirectToRoute('login');
    }

    public function test_role_checks_reuse_the_loaded_roles_for_the_current_user(): void
    {
        $role = Role::create([
            'name' => 'Nhóm kiểm tra cache quyền',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertTrue($user->hasRole($role->name));
        $queryCountAfterFirstCheck = count(DB::getQueryLog());
        $this->assertSame(1, $queryCountAfterFirstCheck);
        $this->assertFalse($user->hasRole(Role::SYSTEM_ADMIN));
        $this->assertSame($queryCountAfterFirstCheck, count(DB::getQueryLog()));

        DB::disableQueryLog();
    }

    public function test_admin_menu_destination_opens_the_first_available_management_page(): void
    {
        $systemAdminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $systemAdmin = User::factory()->create(['is_active' => true]);
        $systemAdmin->roles()->attach($systemAdminRole);

        $this->actingAs($systemAdmin)
            ->get(route('admin.index'))
            ->assertRedirectToRoute('admin.roles.index');

        $this->actingAs($systemAdmin)
            ->get('/')
            ->assertOk()
            ->assertSee('href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"', false)
            ->assertSee('appNavbarFallbackInitialized', false);

        $accountPermission = Permission::create([
            'name' => AccessPermission::MANAGE_USER_ACCOUNTS,
            'guard_name' => 'web',
        ]);
        $accountManagerRole = Role::create([
            'name' => 'Quản lý tài khoản cấp tỉnh',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $accountManagerRole->permissions()->attach($accountPermission);
        $accountManager = User::factory()->create([
            'scope_level' => 'province',
            'province_code' => 'P1',
            'is_active' => true,
        ]);
        $accountManager->roles()->attach($accountManagerRole);

        $this->actingAs($accountManager)
            ->get(route('admin.index'))
            ->assertRedirectToRoute('admin.roles.index');
    }

    public function test_household_member_deletion_returns_json_without_redirecting(): void
    {
        Schema::table('persons', function (Blueprint $table): void {
            $table->string('relationship_with_head')->nullable();
        });
        $adminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach($adminRole);
        $household = Household::create([
            'household_code' => 'HK-DELETE',
            'school_year' => '2026-2027',
        ]);
        $headId = DB::table('persons')->insertGetId([
            'household_id' => $household->id,
            'relationship_with_head' => 'Chủ hộ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $memberId = DB::table('persons')->insertGetId([
            'household_id' => $household->id,
            'relationship_with_head' => 'Con',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->deleteJson(route('households.members.destroy', [$household, $headId]))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Hãy xóa các thành viên khác trước khi xóa hồ sơ chủ hộ.');
        $this->assertDatabaseHas('persons', ['id' => $headId]);

        $this->deleteJson(route('households.members.destroy', [$household, $memberId]))
            ->assertOk()
            ->assertJsonPath('remaining_count', 1)
            ->assertJsonPath('is_household_head', false);
        $this->assertDatabaseMissing('persons', ['id' => $memberId]);

        $this->deleteJson(route('households.members.destroy', [$household, $headId]))
            ->assertOk()
            ->assertJsonPath('remaining_count', 0)
            ->assertJsonPath('is_household_head', true);
        $this->assertDatabaseMissing('persons', ['id' => $headId]);

        $bulkIds = collect(['Anh', 'Chị'])->map(fn(string $relationship): int => DB::table('persons')->insertGetId([
            'household_id' => $household->id,
            'relationship_with_head' => $relationship,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->all();
        $this->deleteJson(route('households.members.bulk-destroy'), ['person_ids' => $bulkIds])
            ->assertOk()
            ->assertJsonPath('deleted_ids', $bulkIds);
        foreach ($bulkIds as $personId) {
            $this->assertDatabaseMissing('persons', ['id' => $personId]);
        }
    }

    public function test_village_delete_returns_json_for_ajax_and_deletes_the_village(): void
    {
        DB::table('provinces')->insert([
            'code' => 'P1',
            'name' => 'Tỉnh một',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('communes')->insert([
            'code' => 'C1',
            'name' => 'Xã một',
            'province_code' => 'P1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('villages')->insert([
            'code' => 'V1',
            'name' => 'Thôn một',
            'commune_code' => 'C1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $role = Role::create([
            'name' => 'village-manager',
            'scope_level' => 'commune',
            'guard_name' => 'web',
        ]);
        $role->permissions()->attach(Permission::create([
            'name' => AccessPermission::MANAGE_VILLAGES,
            'guard_name' => 'web',
        ]));
        $user = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'commune',
            'is_active' => true,
        ]);
        $user->roles()->attach($role);

        $this->actingAs($user)
            ->deleteJson('/villages/V1')
            ->assertOk()
            ->assertJsonPath('message', 'Đã xóa thôn/bản thành công!');

        $this->assertDatabaseMissing('villages', ['code' => 'V1']);
    }

    public function test_account_page_shows_assigned_role_permissions_and_investigation_scope(): void
    {
        DB::table('provinces')->insert([
            'code' => 'P1',
            'name' => 'Tỉnh một',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('communes')->insert([
            'code' => 'C1',
            'name' => 'Xã một',
            'province_code' => 'P1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $userId = DB::table('users')->insertGetId([
            'name' => 'Người dùng thử',
            'email' => 'profile@example.test',
            'password' => Hash::make('old-secure-password'),
            'is_active' => true,
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'commune',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::findOrFail($userId);
        $role = Role::create([
            'name' => 'Điều tra viên xã',
            'scope_level' => 'commune',
            'guard_name' => 'web',
        ]);
        $permission = Permission::create([
            'name' => AccessPermission::VIEW_HOUSEHOLDS,
            'guard_name' => 'web',
            'description' => 'Xem phiếu điều tra và nhân khẩu',
        ]);
        $role->permissions()->attach($permission);
        $user->roles()->attach($role);

        $this->actingAs($user)
            ->get(route('account.show'))
            ->assertOk()
            ->assertSee('Người dùng thử')
            ->assertSee('Điều tra viên xã')
            ->assertSee('Tỉnh một / Xã một')
            ->assertSee('Xem phiếu điều tra và nhân khẩu')
            ->assertSee(route('account.show'))
            ->assertSee('app-account-card', false)
            ->assertSee('app-account-location', false)
            ->assertSee('Năm học: ' . $this->currentSchoolYear())
            ->assertSee('Đăng xuất');
    }

    public function test_account_password_can_be_changed_only_after_current_password_is_verified(): void
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'Người dùng đổi mật khẩu',
            'email' => 'password@example.test',
            'password' => Hash::make('old-secure-password'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::findOrFail($userId);
        $this->actingAs($user);

        $this->from(route('account.show'))
            ->put(route('account.password.update'), [
                'current_password' => 'incorrect-password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ])
            ->assertRedirect(route('account.show'))
            ->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('old-secure-password', $user->fresh()->password));

        $this->put(route('account.password.update'), [
            'current_password' => 'old-secure-password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])
            ->assertRedirect(route('account.show'))
            ->assertSessionHas('success', 'Đổi mật khẩu thành công.');
        $this->assertTrue(Hash::check('new-secure-password', $user->fresh()->password));
    }

    public function test_temporary_password_does_not_block_application_access(): void
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'Người dùng cần đổi mật khẩu',
            'username' => 'must-change-password',
            'password' => Hash::make('Huukhang2k4#'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::findOrFail($userId);
        $this->actingAs($user);

        $this->get('/')->assertOk();
        $this->get(route('account.show'))
            ->assertOk()
            ->assertSee('Tài khoản và nhóm quyền')
            ->assertSee('Đổi mật khẩu');

        $this->put(route('account.password.update'), [
            'current_password' => 'Huukhang2k4#',
            'password' => 'a-different-secure-password',
            'password_confirmation' => 'a-different-secure-password',
        ])->assertRedirectToRoute('account.show');

        $this->assertTrue(Hash::check('a-different-secure-password', $user->fresh()->password));
        $this->get('/')->assertOk();
    }

    public function test_user_create_form_shows_message_when_all_provinces_have_admin_accounts(): void
    {
        DB::table('provinces')->insert([
            'code' => 'P1',
            'name' => 'Tỉnh một',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $systemAdminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $systemAdmin = User::factory()->create(['is_active' => true]);
        $systemAdmin->roles()->attach($systemAdminRole);

        User::factory()->create([
            'name' => 'Quản trị tỉnh một',
            'scope_level' => 'province',
            'province_code' => 'P1',
            'is_active' => true,
        ]);

        $this->actingAs($systemAdmin)
            ->get(route('admin.users.create'))
            ->assertOk()
            ->assertSee('Đã tạo đầy đủ tài khoản quản trị cho các tỉnh/thành phố.')
            ->assertDontSee('<select id="province_code"', false)
            ->assertSee('type="submit"', false)
            ->assertSee('        disabled>', false);
    }

    public function test_system_admin_can_reset_a_managed_account_password_without_blocking_access(): void
    {
        $adminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach($adminRole);

        $provinceRole = Role::create([
            'name' => 'Quản trị tỉnh cần reset',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $target = User::factory()->create([
            'name' => 'Tài khoản tỉnh cần reset',
            'scope_level' => 'province',
            'province_code' => 'P1',
            'is_active' => true,
            'password' => 'old-secure-password',
        ]);
        $target->roles()->attach($provinceRole);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee(route('admin.users.reset-password', $target));

        $this->post(route('admin.users.reset-password', $target))
            ->assertRedirectToRoute('admin.users.index')
            ->assertSessionHas('success');

        $target->refresh();
        $this->assertTrue(Hash::check('Huukhang2k4#', $target->password));

        $this->post(route('admin.users.reset-password', $admin))->assertForbidden();

        $this->actingAs($target)
            ->get('/')
            ->assertOk();
    }

    public function test_province_and_commune_managers_can_create_same_level_and_subordinate_groups_and_accounts(): void
    {
        DB::table('provinces')->insert([
            ['code' => 'P1', 'name' => 'Tỉnh một', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'P2', 'name' => 'Tỉnh hai', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('communes')->insert([
            ['code' => 'C1', 'name' => 'Xã một', 'province_code' => 'P1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'C2', 'name' => 'Xã khác tỉnh', 'province_code' => 'P2', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('villages')->insert([
            ['code' => 'V1', 'name' => 'Thôn một', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $permissions = collect([
            AccessPermission::MANAGE_USER_ACCOUNTS,
            AccessPermission::MANAGE_ACCESS_CONTROL,
            AccessPermission::MANAGE_SCHOOLS,
            AccessPermission::MANAGE_VILLAGES,
            AccessPermission::VIEW_HOUSEHOLDS,
        ])->mapWithKeys(function (string $name): array {
            $permission = Permission::create(['name' => $name, 'guard_name' => 'web']);

            return [$name => $permission];
        });

        $provinceRole = Role::create([
            'name' => 'Quản trị tỉnh',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $provinceRole->permissions()->sync([
            $permissions[AccessPermission::MANAGE_USER_ACCOUNTS]->id,
            $permissions[AccessPermission::MANAGE_ACCESS_CONTROL]->id,
            $permissions[AccessPermission::MANAGE_SCHOOLS]->id,
        ]);

        $provinceAdminId = DB::table('users')->insertGetId([
            'name' => 'Quản trị tỉnh một',
            'username' => 'province-admin-one',
            'email' => 'province-admin-one@example.test',
            'password' => Hash::make('secure-password'),
            'is_active' => true,
            'province_code' => 'P1',
            'scope_level' => 'province',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $provinceAdmin = User::findOrFail($provinceAdminId);
        $provinceAdmin->roles()->attach($provinceRole);

        $this->actingAs($provinceAdmin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('id="organization-unit-tree"', false)
            ->assertSee('data-unit-type="province"', false)
            ->assertSee('data-unit-code="P1"', false)
            ->assertDontSee('data-unit-code="P2"', false)
            ->assertSee('id="account-tree-results"', false);

        $this->actingAs($provinceAdmin)->post('/admin/roles', [
            'name' => 'Nhóm tỉnh hạn chế',
            'scope_level' => 'province',
            'permissions' => [$permissions[AccessPermission::MANAGE_SCHOOLS]->id],
        ])->assertRedirectToRoute('admin.roles.index');

        $limitedProvinceRole = Role::where('name', 'Nhóm tỉnh hạn chế')->firstOrFail();
        $this->assertSame($provinceAdmin->id, $limitedProvinceRole->created_by);
        $this->assertSame(
            [AccessPermission::MANAGE_SCHOOLS],
            $limitedProvinceRole->permissions()->pluck('name')->all()
        );

        $this->from('/admin/roles/create')->post('/admin/roles', [
            'name' => 'Nhóm tỉnh sao chép toàn quyền',
            'scope_level' => 'province',
            'permissions' => [
                $permissions[AccessPermission::MANAGE_USER_ACCOUNTS]->id,
                $permissions[AccessPermission::MANAGE_ACCESS_CONTROL]->id,
                $permissions[AccessPermission::MANAGE_SCHOOLS]->id,
            ],
        ])->assertSessionHasErrors([
            'permissions' => 'Nhóm cùng cấp phải có ít quyền hơn tài khoản tạo nhóm; không thể sao chép toàn bộ quyền.',
        ]);

        $this->get('/admin/users/create')
            ->assertOk()
            ->assertSee('Nhóm tỉnh hạn chế')
            ->assertSee('value="province"', false)
            ->assertSee('value="commune"', false);

        $this->post('/admin/users', [
            'name' => 'Tài khoản tỉnh hạn chế',
            'username' => 'limited-province-user',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
            'role_id' => $limitedProvinceRole->id,
            'scope_level' => 'province',
            'province_code' => 'P1',
            'is_active' => '1',
        ])->assertRedirectToRoute('admin.users.index');

        $limitedProvinceUser = User::where('username', 'limited-province-user')->firstOrFail();
        $this->assertSame('P1', $limitedProvinceUser->province_code);
        $this->assertTrue($limitedProvinceUser->can('manage-schools'));
        $this->assertFalse($limitedProvinceUser->can('manage-user-accounts'));
        $this->assertFalse($limitedProvinceUser->can('manage-access-control'));

        $this->actingAs($provinceAdmin)->post('/admin/roles', [
            'name' => 'Nhóm quản trị xã',
            'scope_level' => 'commune',
            'permissions' => [
                $permissions[AccessPermission::MANAGE_USER_ACCOUNTS]->id,
                $permissions[AccessPermission::MANAGE_ACCESS_CONTROL]->id,
                $permissions[AccessPermission::VIEW_HOUSEHOLDS]->id,
            ],
        ])->assertRedirectToRoute('admin.roles.index');

        $communeRole = Role::where('name', 'Nhóm quản trị xã')->firstOrFail();
        $this->assertSame($provinceAdmin->id, $communeRole->created_by);
        $this->assertSame('P1', $communeRole->province_code);
        $this->assertNull($communeRole->commune_code);

        $this->get('/admin/users/create')
            ->assertOk()
            ->assertSee('Nhóm quản trị xã')
            ->assertSee('value="commune"', false);

        $this->post('/admin/users', [
            'name' => 'Quản trị xã một',
            'username' => 'commune-admin-one',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
            'role_id' => $communeRole->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => '1',
        ])->assertRedirectToRoute('admin.users.index');

        $communeAdmin = User::where('username', 'commune-admin-one')->firstOrFail();
        $this->assertSame('C1', $communeAdmin->commune_code);
        $this->assertSame($provinceAdmin->id, $communeAdmin->created_by);
        $this->assertTrue($communeAdmin->can('manage-access-control'));
        $this->assertTrue($communeAdmin->can('manage-user-accounts'));

        $this->actingAs($communeAdmin)->put(route('account.password.update'), [
            'current_password' => 'a-secure-password-123',
            'password' => 'commune-admin-new-password',
            'password_confirmation' => 'commune-admin-new-password',
        ])->assertRedirectToRoute('account.show');

        $this->actingAs($communeAdmin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('id="organization-unit-tree"', false)
            ->assertSee('data-unit-type="commune"', false)
            ->assertSee('data-unit-code="C1"', false)
            ->assertDontSee('data-unit-code="C2"', false)
            ->assertSee('id="account-tree-results"', false);

        $this->actingAs($communeAdmin)->post('/admin/roles', [
            'name' => 'Nhóm xã vượt quyền',
            'scope_level' => 'commune',
            'permissions' => [
                $permissions[AccessPermission::MANAGE_VILLAGES]->id,
            ],
        ])->assertSessionHasErrors('permissions');

        $this->actingAs($communeAdmin)
            ->get('/admin/roles/create')
            ->assertOk()
            ->assertSee('Mô tả quyền')
            ->assertDontSee('Mã hệ thống')
            ->assertSee('value="commune"', false)
            ->assertSee('value="village"', false);

        $this->actingAs($communeAdmin)->post('/admin/roles', [
            'name' => 'Nhóm xã hạn chế',
            'scope_level' => 'commune',
            'permissions' => [$permissions[AccessPermission::VIEW_HOUSEHOLDS]->id],
        ])->assertRedirectToRoute('admin.roles.index');

        $limitedCommuneRole = Role::where('name', 'Nhóm xã hạn chế')->firstOrFail();
        $this->assertSame($communeAdmin->id, $limitedCommuneRole->created_by);

        $this->post('/admin/users', [
            'name' => 'Tài khoản xã hạn chế',
            'username' => 'limited-commune-user',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
            'role_id' => $limitedCommuneRole->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => '1',
        ])->assertRedirectToRoute('admin.users.index');

        $limitedCommuneUser = User::where('username', 'limited-commune-user')->firstOrFail();
        $this->assertSame('C1', $limitedCommuneUser->commune_code);
        $this->assertTrue($limitedCommuneUser->can('view-households'));
        $this->assertFalse($limitedCommuneUser->can('manage-user-accounts'));
        $this->assertFalse($limitedCommuneUser->can('manage-access-control'));

        $this->actingAs($communeAdmin)->post('/admin/roles', [
            'name' => 'Nhóm nhập liệu thôn',
            'scope_level' => 'village',
            'permissions' => [$permissions[AccessPermission::VIEW_HOUSEHOLDS]->id],
        ])->assertRedirectToRoute('admin.roles.index');

        $villageRole = Role::where('name', 'Nhóm nhập liệu thôn')->firstOrFail();
        $this->assertSame($communeAdmin->id, $villageRole->created_by);
        $this->assertSame('C1', $villageRole->commune_code);

        $this->get('/admin/users/create')
            ->assertOk()
            ->assertSee('Nhóm nhập liệu thôn')
            ->assertSee('value="village"', false);

        $this->actingAs($communeAdmin)->post('/admin/users', [
            'name' => 'Cán bộ thôn một',
            'username' => 'village-officer-one',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
            'role_id' => $villageRole->id,
            'scope_level' => 'village',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'village_codes' => ['V1'],
            'is_active' => '1',
        ])->assertRedirectToRoute('admin.users.index');

        $villageUser = User::where('username', 'village-officer-one')->firstOrFail();
        $this->assertSame(['V1'], $villageUser->managedVillageCodes());
        $this->assertSame($communeAdmin->id, $villageUser->created_by);
    }

    public function test_inactive_accounts_cannot_sign_in_and_active_accounts_can(): void
    {
        $active = User::factory()->create([
            'username' => 'active-account',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->post('/login', ['username' => $active->username, 'password' => 'password'])
            ->assertRedirectToRoute('index');
        $this->assertAuthenticatedAs($active);

        $this->post('/logout')->assertRedirectToRoute('login');

        $inactive = User::factory()->create([
            'username' => 'inactive-account',
            'password' => Hash::make('password'),
        ]);
        $inactive->forceFill(['is_active' => false])->save();
        $this->post('/login', ['username' => $inactive->username, 'password' => 'password'])
            ->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_bootstrap_command_creates_system_and_scope_roles_without_hardcoded_credentials(): void
    {
        $this->artisan('app:create-admin')
            ->expectsQuestion('Tên người quản trị', 'Quản trị hệ thống')
            ->expectsQuestion('Tên đăng nhập', 'root')
            ->expectsQuestion('Mật khẩu (ít nhất 12 ký tự)', 'a-secure-password-123')
            ->expectsQuestion('Nhập lại mật khẩu', 'a-secure-password-123')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['username' => 'root', 'name' => 'Quản trị hệ thống']);
        $admin = User::where('username', 'root')->firstOrFail();
        $this->assertTrue(Hash::check('a-secure-password-123', $admin->password));
        $this->assertTrue($admin->hasRole(Role::SYSTEM_ADMIN));
        $this->assertDatabaseHas('roles', ['name' => Role::SYSTEM_ADMIN, 'scope_level' => 'global']);
        $this->assertDatabaseMissing('roles', ['name' => 'province-admin']);
        $this->assertDatabaseMissing('roles', ['name' => 'commune-admin']);
        $this->assertDatabaseMissing('roles', ['name' => 'village-officer']);
        $this->assertDatabaseMissing('roles', ['scope_level' => 'school']);
    }

    public function test_province_manager_can_create_commune_accounts_inside_their_area(): void
    {
        DB::table('provinces')->insert([
            ['code' => 'P1', 'name' => 'Tỉnh một', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'P2', 'name' => 'Tỉnh hai', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('communes')->insert([
            ['code' => 'C1', 'name' => 'Xã một', 'province_code' => 'P1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'C2', 'name' => 'Xã hai', 'province_code' => 'P2', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'C3', 'name' => 'Xã ba', 'province_code' => 'P1', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('villages')->insert([
            'code' => 'V1',
            'name' => 'Thôn một',
            'commune_code' => 'C1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $managerRole = Role::create(['name' => 'province-manager', 'scope_level' => 'province', 'guard_name' => 'web']);
        $managerPermission = Permission::create([
            'name' => AccessPermission::MANAGE_USER_ACCOUNTS,
            'guard_name' => 'web',
        ]);
        $manageGroupsPermission = Permission::create([
            'name' => AccessPermission::MANAGE_ACCESS_CONTROL,
            'guard_name' => 'web',
        ]);
        $manageSchoolsPermission = Permission::create([
            'name' => AccessPermission::MANAGE_SCHOOLS,
            'guard_name' => 'web',
        ]);
        $viewPermission = Permission::create([
            'name' => AccessPermission::VIEW_HOUSEHOLDS,
            'guard_name' => 'web',
        ]);
        $managerRole->permissions()->attach([
            $managerPermission,
            $manageGroupsPermission,
            $manageSchoolsPermission,
            $viewPermission,
        ]);

        $manager = User::factory()->create([
            'province_code' => 'P1',
            'scope_level' => 'province',
            'is_active' => true,
        ]);
        $manager->roles()->attach($managerRole);
        $this->actingAs($manager);

        $this->get('/admin/users/create')
            ->assertOk()
            ->assertSee('Nhóm người dùng / phòng ban')
            ->assertSee('Có thể tạo tài khoản cùng cấp hoặc cấp dưới trực tiếp.')
            ->assertSee('value="province"', false)
            ->assertSee('value="commune"', false)
            ->assertDontSee('<option value="village"', false)
            ->assertDontSee('name="permission_ids[]"', false)
            ->assertSee('id="village_codes"', false)
            ->assertSee('checkbox.name =', false)
            ->assertSee("'village_codes[]';", false)
            ->assertDontSee('<select id="village_codes"', false)
            ->assertDontSee('Quyền trong nhóm');
        $this->get('/admin/roles')->assertOk();
        $this->get('/admin/roles/create')
            ->assertOk()
            ->assertSee('data-group-control data-scopes="commune"', false)
            ->assertDontSee('data-group-control data-scopes="province', false);
        $this->post('/admin/roles', [
            'name' => 'province-limited-viewer',
            'scope_level' => 'province',
            'permissions' => [$viewPermission->id],
        ])->assertSessionHasErrors('permissions');

        $this->from('/admin/roles/create')->post('/admin/roles', [
            'name' => 'province-role-above-manager',
            'scope_level' => 'province',
            'permissions' => [Permission::create([
                'name' => AccessPermission::CREATE_HOUSEHOLDS,
                'guard_name' => 'web',
            ])->id],
        ])->assertSessionHasErrors('permissions');

        $this->post('/admin/roles', [
            'name' => 'commune-admin-group',
            'description' => 'Nhóm quản lý cấp xã trong tỉnh',
            'scope_level' => 'commune',
            'permissions' => [
                $managerPermission->id,
                $manageGroupsPermission->id,
                $viewPermission->id,
            ],
        ])->assertRedirectToRoute('admin.roles.index');
        $localRole = Role::where('name', 'commune-admin-group')->firstOrFail();
        $this->assertSame($manager->id, $localRole->created_by);

        $this->from('/admin/roles/create')->post('/admin/roles', [
            'name' => 'village-scope-not-allowed',
            'scope_level' => 'village',
            'permissions' => [$viewPermission->id],
        ])->assertSessionHasErrors('scope_level');

        $this->from('/admin/roles/create')->post('/admin/roles', [
            'name' => 'too-powerful-group',
            'scope_level' => 'commune',
            'permissions' => [$managerPermission->id, $viewPermission->id, Permission::create([
                'name' => AccessPermission::MANAGE_SCHOOLS,
                'guard_name' => 'web',
            ])->id],
        ])->assertSessionHasErrors('permissions');

        $this->post('/admin/roles', [
            'name' => 'commune-data-entry',
            'scope_level' => 'commune',
            'permissions' => [$viewPermission->id],
        ])->assertRedirectToRoute('admin.roles.index');

        $this->post('/admin/users', [
            'name' => 'Tài khoản tỉnh vượt cấp',
            'username' => 'province-too-high',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
            'role_id' => $localRole->id,
            'scope_level' => 'province',
            'province_code' => 'P1',
            'is_active' => '1',
        ])->assertSessionHasErrors('role_id');

        $this->post('/admin/users', [
            'name' => 'Cán bộ xã',
            'username' => 'commune-user-one',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
            'role_id' => $localRole->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => '1',
        ])->assertRedirectToRoute('admin.users.index');

        $this->from('/admin/users/create')->post('/admin/users', [
            'name' => 'Tài khoản xã cùng cấp thứ hai',
            'username' => 'commune-user-two',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
            'role_id' => $localRole->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => '1',
        ])->assertRedirectToRoute('admin.users.index');
        $this->assertDatabaseHas('users', [
            'username' => 'commune-user-two',
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ]);

        $this->post('/admin/users', [
            'name' => 'Cán bộ xã khác',
            'username' => 'commune-user-other',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
            'role_id' => $localRole->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C3',
            'is_active' => '1',
        ])->assertRedirectToRoute('admin.users.index');

        $this->assertDatabaseHas('users', ['username' => 'commune-user-one', 'province_code' => 'P1', 'commune_code' => 'C1']);
        $peer = User::where('username', 'commune-user-one')->firstOrFail();
        $this->assertTrue($peer->hasPermissionTo(AccessPermission::VIEW_HOUSEHOLDS));
        $this->assertDatabaseHas('model_has_roles', [
            'model_id' => $peer->id,
            'model_type' => User::class,
            'role_id' => $localRole->id,
            'assigned_by' => $manager->id,
        ]);

        $this->post('/admin/users', [
            'name' => 'Cán bộ thôn không thuộc cấp được giao',
            'username' => 'village-too-deep',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
            'role_id' => Role::create(['name' => 'village-data-entry', 'scope_level' => 'village', 'guard_name' => 'web'])->id,
            'scope_level' => 'village',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'village_codes' => ['V1'],
            'is_active' => '1',
        ])->assertSessionHasErrors('scope_level');

        $this->from('/admin/users/create')->post('/admin/users', [
            'name' => 'Ngoài tỉnh',
            'username' => 'outside-province',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
            'role_id' => $localRole->id,
            'scope_level' => 'commune',
            'province_code' => 'P2',
            'commune_code' => 'C2',
            'is_active' => '1',
        ])->assertForbidden();

        $managerRole->permissions()->detach([$viewPermission->id, $manageGroupsPermission->id]);
        $this->assertFalse($peer->fresh()->hasPermissionTo(AccessPermission::VIEW_HOUSEHOLDS));
    }

    public function test_system_administrator_can_open_access_control_pages(): void
    {
        $role = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        Role::create([
            'name' => 'Phòng nhập liệu',
            'description' => 'Xem và nhập phiếu điều tra.',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $rolesPage = $this->actingAs($user)
            ->get('/admin/roles')
            ->assertOk()
            ->assertSee('Nhóm người dùng / Phòng ban')
            ->assertSee('Trung ương')
            ->assertSee('Tỉnh')
            ->assertSee('system-admin')
            ->assertSee('Phòng nhập liệu')
            ->assertSee('aria-expanded="false"', false)
            ->assertDontSee('class="scope-body open"', false);
        $content = $rolesPage->getContent();
        $this->assertLessThan(
            strpos($content, 'scope-title">Cấp Tỉnh'),
            strpos($content, 'scope-title">Trung ương')
        );
        $this->get('/admin/permissions')->assertNotFound();
        $this->get('/admin/roles/create')
            ->assertOk()
            ->assertSee('Phạm vi sử dụng của nhóm')
            ->assertSee('<option value="province"', false)
            ->assertDontSee('<option value="commune"', false)
            ->assertDontSee('<option value="village"', false)
            ->assertSee('Tạo nhóm cấp Tỉnh để khởi tạo tài khoản tỉnh.')
            ->assertSee('Quyền thao tác phiếu tự kèm quyền xem.');
        $this->get('/admin/users/create')
            ->assertOk()
            ->assertSee('Cấp hoạt động')
            ->assertSee('id="scope_level"', false)
            ->assertSee('value="global"', false)
            ->assertSee('Nhóm người dùng / phòng ban')
            ->assertSee('type="radio"', false)
            ->assertSee('role-choice')
            ->assertSee('data-role-choice-row', false)
            ->assertSee('Phòng nhập liệu')
            ->assertSee('Chọn cấp hoạt động và nhóm quyền phù hợp.');

        $viewPermission = Permission::create([
            'name' => AccessPermission::VIEW_HOUSEHOLDS,
            'guard_name' => 'web',
            'description' => 'Xem phiếu điều tra và nhân khẩu',
        ]);
        foreach (AccessPermission::definitions() as $name => $description) {
            if ($name === AccessPermission::VIEW_HOUSEHOLDS) {
                continue;
            }
            Permission::create([
                'name' => $name,
                'guard_name' => 'web',
                'description' => $description,
            ]);
        }
        Permission::where('name', AccessPermission::MANAGE_ACCESS_CONTROL)
            ->update(['description' => 'Quản lý vai trò và quyền']);
        $this->get('/admin/roles/create')
            ->assertOk()
            ->assertSee('Phạm vi sử dụng của nhóm')
            ->assertSee('Mô tả quyền')
            ->assertDontSee('Mã hệ thống')
            ->assertSee('Quản trị về nhân khẩu và phiếu')
            ->assertSee('permission_groups[]', false)
            ->assertSee('create-households')
            ->assertSee('Quản lý nhóm người dùng')
            ->assertSee('Tạo tài khoản và gán nhóm người dùng trong phạm vi quản lý')
            ->assertDontSee('Quản lý vai trò và quyền')
            ->assertSee('Quản lý danh mục trường học (chỉ cấp tỉnh)')
            ->assertDontSee('data-scopes="commune"', false);

        $roleForm = $this->get('/admin/roles/create');
        $this->assertSame(2, substr_count($roleForm->getContent(), 'data-scopes="global,province"'));
        $this->assertSame(1, substr_count($roleForm->getContent(), 'data-scopes="province"'));
        $roleForm->assertDontSee('data-scopes="commune"', false);
        $roleForm->assertSee('data-default-scope="global"', false)
            ->assertSee('<option value="global" selected>', false)
            ->assertSee('data-permission-prerequisites=', false)
            ->assertSee('&quot;update-households&quot;:[&quot;view-households&quot;]', false)
            ->assertSee('JSON.parse(', false)
            ->assertSee('function enablePrerequisites', false)
            ->assertSee('function disableDependents', false);

        $this->from('/admin/roles/create')->post('/admin/roles', [
            'name' => 'province-household-admin',
            'scope_level' => 'province',
            'permission_groups' => ['households'],
        ])->assertSessionHasErrors('permission_groups');
        $scopedUser = User::factory()->create([
            'province_code' => 'P1',
            'scope_level' => 'province',
            'is_active' => true,
        ]);
        $legacyProvinceRole = Role::create([
            'name' => 'legacy-province-viewer',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $legacyProvinceRole->permissions()->attach($viewPermission);
        $scopedUser->roles()->attach($legacyProvinceRole);
        $this->assertFalse(Gate::forUser($scopedUser)->allows(AccessPermission::VIEW_HOUSEHOLDS));

        $updatePermission = Permission::create([
            'name' => AccessPermission::UPDATE_HOUSEHOLDS,
            'guard_name' => 'web',
        ]);
        $this->from('/admin/roles/create')->post('/admin/roles', [
            'name' => 'province-editor',
            'scope_level' => 'province',
            'permissions' => [$updatePermission->id],
        ])->assertSessionHasErrors('permissions');

        $legacyRole = Role::create([
            'name' => 'legacy-province-editor',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $legacyRole->permissions()->attach($updatePermission);
        $scopedUser->roles()->attach($legacyRole);
        $this->assertFalse($scopedUser->fresh()->hasPermissionTo(AccessPermission::UPDATE_HOUSEHOLDS));
    }

    public function test_role_member_count_opens_a_member_table_without_showing_permission_count(): void
    {
        $adminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach($adminRole);

        $group = Role::create([
            'name' => 'Nhóm quản trị tỉnh',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $group->permissions()->attach(Permission::create([
            'name' => AccessPermission::MANAGE_SCHOOLS,
            'guard_name' => 'web',
        ]));
        $activeMember = User::factory()->create([
            'name' => 'Thành viên đang hoạt động',
            'username' => 'active-member',
            'scope_level' => 'province',
            'is_active' => true,
        ]);
        $inactiveMember = User::factory()->create([
            'name' => 'Thành viên đã khóa',
            'username' => 'inactive-member',
            'scope_level' => 'province',
            'is_active' => false,
        ]);
        $notMember = User::factory()->create([
            'name' => 'Không thuộc nhóm',
            'username' => 'not-member',
            'is_active' => true,
        ]);
        $group->users()->attach([$activeMember->id, $inactiveMember->id]);

        $this->actingAs($admin)->get('/admin/roles')
            ->assertOk()
            ->assertSee('2 thành viên')
            ->assertSee(route('admin.roles.members', $group))
            ->assertSee('data-role-members-toggle', false)
            ->assertSee('aria-expanded="false"', false)
            ->assertDontSee('class="scope-body open"', false)
            ->assertDontSee('>Quyền</th>', false)
            ->assertDontSee('permissions_count');

        $this->get(route('admin.roles.members', $group), [
            'HTTP_ACCEPT' => 'text/html',
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
        ])
            ->assertOk()
            ->assertSee('active-member')
            ->assertSee('inactive-member')
            ->assertSee('Đang hoạt động')
            ->assertSee('Đã vô hiệu hóa')
            ->assertDontSee('not-member');

        $this->get(route('admin.roles.members', $group))
            ->assertOk()
            ->assertSee('Thành viên nhóm')
            ->assertSee('active-member')
            ->assertDontSee('not-member');
    }

    public function test_role_index_renders_expandable_extra_permissions(): void
    {
        $adminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach($adminRole);

        $group = Role::create([
            'name' => 'Nhóm nhiều quyền',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $permissions = collect(range(1, 4))->map(fn(int $index) => Permission::create([
            'name' => "test-permission-{$index}",
            'description' => "Quyền kiểm thử {$index}",
            'guard_name' => 'web',
        ]));
        $group->permissions()->attach($permissions->pluck('id')->all());

        $this->actingAs($admin)
            ->get(route('admin.roles.index'))
            ->assertOk()
            ->assertSee('+1 quyền khác')
            ->assertSee('data-role-permissions-toggle', false)
            ->assertSee('aria-expanded="false"', false)
            ->assertSee('id="role-permissions-extra-' . $group->id . '" hidden', false)
            ->assertSee('Quyền kiểm thử 4');
    }

    public function test_local_account_cannot_view_members_of_another_accounts_group(): void
    {
        $adminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach($adminRole);
        $group = Role::create([
            'name' => 'Nhóm tỉnh khác',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);

        $manageGroups = Permission::create([
            'name' => AccessPermission::MANAGE_ACCESS_CONTROL,
            'guard_name' => 'web',
        ]);
        $localGroup = Role::create([
            'name' => 'Nhóm quản lý nhóm',
            'scope_level' => 'province',
            'guard_name' => 'web',
            'created_by' => $admin->id,
            'province_code' => 'P1',
        ]);
        $localGroup->permissions()->attach($manageGroups);
        $localUser = User::factory()->create([
            'scope_level' => 'province',
            'province_code' => 'P1',
            'is_active' => true,
        ]);
        $localUser->roles()->attach($localGroup);

        $this->actingAs($localUser)->get(route('admin.roles.members', $group))
            ->assertForbidden();
    }

    public function test_school_management_group_is_only_available_for_province_scope_accounts(): void
    {
        DB::table('provinces')->insert([
            'code' => 'P1',
            'name' => 'Tỉnh một',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('communes')->insert([
            'code' => 'C1',
            'name' => 'Xã một',
            'province_code' => 'P1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $adminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach($adminRole);

        $schoolManagerRole = Role::create([
            'name' => 'Quản lý trường học',
            'description' => 'Quản lý danh mục trường học.',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $provincePermissions = collect([
            [
                'name' => AccessPermission::MANAGE_SCHOOLS,
                'guard_name' => 'web',
            ],
            [
                'name' => AccessPermission::MANAGE_ACCESS_CONTROL,
                'guard_name' => 'web',
            ],
            [
                'name' => AccessPermission::MANAGE_USER_ACCOUNTS,
                'guard_name' => 'web',
            ],
        ])->map(fn(array $attributes) => Permission::create($attributes));
        $schoolManagerRole->permissions()->attach($provincePermissions);

        $this->actingAs($admin)
            ->get('/admin/users/create')
            ->assertOk()
            ->assertSee('Cấp hoạt động')
            ->assertSee('value="global"', false)
            ->assertSee('Nhóm người dùng / phòng ban')
            ->assertSee('data-role-scopes="province"', false);

        $this->from('/admin/users/create')->post('/admin/users', [
            'name' => 'Quản lý trường cấp xã',
            'email' => 'commune-school-manager@example.test',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
            'role_id' => $schoolManagerRole->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => '1',
        ])->assertSessionHasErrors('scope_level');

        $this->assertDatabaseMissing('users', ['email' => 'commune-school-manager@example.test']);
    }

    public function test_a_group_can_be_deleted_after_all_assigned_users_are_revoked(): void
    {
        $adminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach($adminRole);

        $group = Role::create([
            'name' => 'Phòng nhập liệu',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $account = User::factory()->create([
            'province_code' => 'P1',
            'scope_level' => 'province',
            'is_active' => true,
        ]);
        $account->roles()->attach($group);

        $this->actingAs($admin)
            ->delete(route('admin.roles.destroy', $group))
            ->assertRedirect()
            ->assertSessionHasErrors('role');
        $this->assertDatabaseHas('roles', ['id' => $group->id]);

        $this->patch(route('admin.users.status', $account), ['is_active' => '0'])
            ->assertRedirectToRoute('admin.users.index');
        $this->delete(route('admin.roles.destroy', $group))
            ->assertRedirectToRoute('admin.roles.index')
            ->assertSessionHas('success', 'Đã xóa nhóm người dùng.');

        $this->assertDatabaseMissing('roles', ['id' => $group->id]);
        $this->assertDatabaseMissing('model_has_roles', [
            'role_id' => $group->id,
            'model_id' => $account->id,
            'model_type' => User::class,
        ]);
    }

    public function test_household_action_permissions_also_grant_list_access(): void
    {
        $user = User::factory()->create([
            'province_code' => 'P1',
            'scope_level' => 'commune',
            'is_active' => true,
        ]);
        $permissions = collect([
            AccessPermission::CREATE_HOUSEHOLDS,
            AccessPermission::IMPORT_HOUSEHOLDS,
        ])->map(function (string $permission): Permission {
            return Permission::create([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        });
        $role = Role::create([
            'name' => 'household-action-role',
            'scope_level' => 'commune',
            'guard_name' => 'web',
        ]);
        $role->permissions()->sync($permissions->pluck('id')->all());
        $user->roles()->attach($role, ['assigned_by' => null]);

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertSee('Danh sách phiếu')
            ->assertSee('Tạo phiếu mới')
            ->assertSee('Nhập dữ liệu')
            ->assertSee('Phiếu điều tra')
            ->assertSee('href="/households/create"', false)
            ->assertDontSee('dropdown-toggle');

        $this->get('/households/create')
            ->assertOk()
            ->assertSee('aria-label="Đường dẫn trang"', false)
            ->assertSee('Tạo phiếu')
            ->assertSee('href="/households"', false);
        $this->get('/households/import')->assertOk()->assertSee('href="/households"', false);
        $this->get('/households')->assertOk();
    }

    public function test_account_management_permission_also_grants_group_management(): void
    {
        $accountPermission = Permission::create([
            'name' => AccessPermission::MANAGE_USER_ACCOUNTS,
            'guard_name' => 'web',
        ]);
        Permission::create([
            'name' => AccessPermission::MANAGE_ACCESS_CONTROL,
            'guard_name' => 'web',
        ]);
        $role = Role::create([
            'name' => 'account-manager-role',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $role->permissions()->attach($accountPermission);
        $user = User::factory()->create([
            'scope_level' => 'province',
            'is_active' => true,
        ]);
        $user->roles()->attach($role);

        $this->assertTrue($user->can('manage-user-accounts'));
        $this->assertTrue($user->can('manage-access-control'));
        $this->assertContains(
            AccessPermission::MANAGE_ACCESS_CONTROL,
            $user->permissionNames()
        );
    }

    public function test_global_scoped_roles_do_not_grant_non_admin_permissions(): void
    {
        $viewPermission = Permission::create([
            'name' => AccessPermission::VIEW_HOUSEHOLDS,
            'guard_name' => 'web',
        ]);
        $globalRole = Role::create([
            'name' => 'legacy-global-viewer',
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $globalRole->permissions()->attach($viewPermission);

        $provinceUser = User::factory()->create([
            'scope_level' => 'province',
            'is_active' => true,
        ]);
        $provinceUser->roles()->attach($globalRole);
        $this->assertFalse($provinceUser->hasPermissionTo(AccessPermission::VIEW_HOUSEHOLDS));

        $systemRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $systemUser = User::factory()->create(['is_active' => true]);
        $systemUser->roles()->attach($systemRole);
        $this->assertTrue($systemUser->hasPermissionTo(AccessPermission::VIEW_HOUSEHOLDS));
    }

    public function test_household_action_permissions_grant_read_but_read_only_does_not_grant_mutations(): void
    {
        $permissionIds = collect([
            AccessPermission::VIEW_HOUSEHOLDS,
            AccessPermission::CREATE_HOUSEHOLDS,
            AccessPermission::UPDATE_HOUSEHOLDS,
            AccessPermission::DELETE_HOUSEHOLDS,
            AccessPermission::IMPORT_HOUSEHOLDS,
            AccessPermission::EXPORT_HOUSEHOLDS,
        ])->mapWithKeys(function (string $permission): array {
            $record = Permission::create([
                'name' => $permission,
                'guard_name' => 'web',
            ]);

            return [$permission => $record->id];
        });

        $editorRole = Role::create([
            'name' => 'household-editor',
            'scope_level' => 'commune',
            'guard_name' => 'web',
        ]);
        $editorRole->permissions()->sync(collect([
            $permissionIds[AccessPermission::CREATE_HOUSEHOLDS],
            $permissionIds[AccessPermission::UPDATE_HOUSEHOLDS],
            $permissionIds[AccessPermission::DELETE_HOUSEHOLDS],
            $permissionIds[AccessPermission::IMPORT_HOUSEHOLDS],
            $permissionIds[AccessPermission::EXPORT_HOUSEHOLDS],
        ])->all());
        $editor = User::factory()->create(['scope_level' => 'commune', 'is_active' => true]);
        $editor->roles()->attach($editorRole, ['assigned_by' => null]);

        $this->assertTrue($editor->hasPermissionTo(AccessPermission::VIEW_HOUSEHOLDS));
        $this->assertTrue($editor->hasPermissionTo(AccessPermission::CREATE_HOUSEHOLDS));
        $this->assertTrue($editor->hasPermissionTo(AccessPermission::UPDATE_HOUSEHOLDS));
        $this->assertTrue($editor->hasPermissionTo(AccessPermission::DELETE_HOUSEHOLDS));
        $this->assertTrue($editor->hasPermissionTo(AccessPermission::IMPORT_HOUSEHOLDS));
        $this->assertTrue($editor->hasPermissionTo(AccessPermission::EXPORT_HOUSEHOLDS));
        $this->assertContains(AccessPermission::VIEW_HOUSEHOLDS, $editor->permissionNames());

        $viewerRole = Role::create([
            'name' => 'household-viewer',
            'scope_level' => 'commune',
            'guard_name' => 'web',
        ]);
        $viewerRole->permissions()->attach($permissionIds[AccessPermission::VIEW_HOUSEHOLDS]);
        $viewer = User::factory()->create(['scope_level' => 'commune', 'is_active' => true]);
        $viewer->roles()->attach($viewerRole, ['assigned_by' => null]);

        $this->assertTrue($viewer->hasPermissionTo(AccessPermission::VIEW_HOUSEHOLDS));
        $this->assertFalse($viewer->hasPermissionTo(AccessPermission::CREATE_HOUSEHOLDS));
        $this->assertFalse($viewer->hasPermissionTo(AccessPermission::UPDATE_HOUSEHOLDS));
        $this->assertFalse($viewer->hasPermissionTo(AccessPermission::DELETE_HOUSEHOLDS));
        $this->assertFalse($viewer->hasPermissionTo(AccessPermission::IMPORT_HOUSEHOLDS));
        $this->assertFalse($viewer->hasPermissionTo(AccessPermission::EXPORT_HOUSEHOLDS));

        $unassigned = User::factory()->create(['is_active' => true]);
        $this->assertFalse($unassigned->hasPermissionTo(AccessPermission::VIEW_HOUSEHOLDS));
    }

    public function test_import_page_limits_geography_and_prefills_the_assigned_area(): void
    {
        DB::table('provinces')->insert([
            ['code' => 'P1', 'name' => 'Tỉnh một', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'P2', 'name' => 'Tỉnh hai', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('communes')->insert([
            ['code' => 'C1', 'name' => 'Xã một', 'province_code' => 'P1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'C2', 'name' => 'Xã hai', 'province_code' => 'P2', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('villages')->insert([
            ['code' => 'V1', 'name' => 'Thôn được giao', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'V2', 'name' => 'Thôn ngoài phạm vi', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $provinceRole = Role::create(['name' => 'province-importer', 'scope_level' => 'province', 'guard_name' => 'web']);
        $provinceRole->permissions()->attach(Permission::create([
            'name' => AccessPermission::IMPORT_HOUSEHOLDS,
            'guard_name' => 'web',
        ]));
        $provinceUser = User::factory()->create([
            'province_code' => 'P1',
            'scope_level' => 'province',
            'is_active' => true,
        ]);
        $provinceUser->roles()->attach($provinceRole);

        $this->actingAs($provinceUser)
            ->get('/households/import')
            ->assertForbidden();

        $communeRole = Role::create(['name' => 'commune-importer', 'scope_level' => 'commune', 'guard_name' => 'web']);
        $communeRole->permissions()->attach(Permission::where('name', AccessPermission::IMPORT_HOUSEHOLDS)->firstOrFail());
        $communeUser = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'commune',
            'is_active' => true,
        ]);
        $communeUser->roles()->attach($communeRole);

        $this->actingAs($communeUser)
            ->get('/households/import')
            ->assertOk()
            ->assertSee('id="import-school-year"', false)
            ->assertSee('name="school_year" value="' . (now()->month >= 8 ? now()->year : now()->year - 1) . '-' . ((now()->month >= 8 ? now()->year : now()->year - 1) + 1) . '"', false)
            ->assertDontSee('<select id="import-school-year"', false)
            ->assertSee('value="P1" selected', false)
            ->assertDontSee('value="P2"', false)
            ->assertSee('data-selected-code="C1"', false)
            ->assertSee('<option value="C1" selected>', false)
            ->assertSee('data-village', false)
            ->assertSee('data-selected-code=""', false)
            ->assertSee('<option value="V1"', false)
            ->assertSee('<option value="V2"', false)
            ->assertSee('required', false)
            ->assertSee('Thôn/Bản <span class="text-danger">*</span>', false);

        $villageRole = Role::create(['name' => 'village-importer', 'scope_level' => 'village', 'guard_name' => 'web']);
        $villageRole->permissions()->attach(Permission::create([
            'name' => AccessPermission::IMPORT_HOUSEHOLDS,
            'guard_name' => 'web',
        ]));
        $villageUser = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'village',
            'is_active' => true,
        ]);
        $villageUser->roles()->attach($villageRole);
        $villageUser->villages()->sync(['V1']);

        $this->actingAs($villageUser)
            ->get('/households/import')
            ->assertOk()
            ->assertSee('value="P1" selected', false)
            ->assertSee('data-selected-code="C1"', false)
            ->assertSee('data-selected-code="V1"', false)
            ->assertSee('<option value="C1" selected>', false)
            ->assertSee('<option value="V1" selected>', false);
        $this->get('/api/communes/C1/villages')
            ->assertOk()
            ->assertExactJson([['code' => 'V1', 'name' => 'Thôn được giao']]);

        $villageUser->villages()->sync(['V1', 'V2']);
        $this->get('/households/import')
            ->assertOk()
            ->assertSee('data-selected-code=""', false)
            ->assertSee('<option value="V1"', false)
            ->assertSee('<option value="V2"', false);
        $this->get('/api/communes/C1/villages')
            ->assertOk()
            ->assertExactJson([
                ['code' => 'V1', 'name' => 'Thôn được giao'],
                ['code' => 'V2', 'name' => 'Thôn ngoài phạm vi'],
            ]);
    }

    public function test_user_list_shows_province_name_instead_of_province_code(): void
    {
        DB::table('provinces')->insert([
            'code' => '01',
            'name' => 'Thành phố Hà Nội',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $adminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach($adminRole);

        User::factory()->create([
            'name' => 'Tài khoản tỉnh',
            'province_code' => '01',
            'scope_level' => 'province',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertOk()
            ->assertSee('Thành phố Hà Nội')
            ->assertDontSee('>01<', false);
    }

    public function test_user_list_filters_the_account_tree_by_province_commune_and_village(): void
    {
        DB::table('provinces')->insert([
            ['code' => 'P1', 'name' => 'Tỉnh một', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'P2', 'name' => 'Tỉnh hai', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('communes')->insert([
            ['code' => 'C1', 'name' => 'Xã một', 'province_code' => 'P1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'C2', 'name' => 'Xã hai', 'province_code' => 'P2', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('villages')->insert([
            ['code' => 'V1', 'name' => 'Thôn một', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'V2', 'name' => 'Thôn hai', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $adminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach($adminRole);
        $accountRole = Role::create([
            'name' => 'Nhân viên nhập liệu',
            'scope_level' => 'village',
            'guard_name' => 'web',
        ]);

        $villageOneUser = User::factory()->create([
            'name' => 'Người dùng thôn một',
            'username' => 'thonmot',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'village',
            'is_active' => true,
        ]);
        $villageOneUser->roles()->attach($accountRole);
        $villageOneUser->villages()->sync(['V1']);

        $villageTwoUser = User::factory()->create([
            'name' => 'Người dùng thôn hai',
            'username' => 'thonthai',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'village',
            'is_active' => true,
        ]);
        $villageTwoUser->roles()->attach($accountRole);
        $villageTwoUser->villages()->sync(['V2']);

        $otherProvinceUser = User::factory()->create([
            'name' => 'Người dùng tỉnh hai',
            'province_code' => 'P2',
            'commune_code' => 'C2',
            'scope_level' => 'commune',
            'is_active' => true,
        ]);
        $otherProvinceUser->roles()->attach(Role::create([
            'name' => 'Quản trị xã tỉnh hai',
            'scope_level' => 'commune',
            'guard_name' => 'web',
        ]));

        $this->actingAs($admin)
            ->get(route('admin.users.index', [
                'q' => 'thonmot',
                'province_code' => 'P1',
                'commune_code' => 'C1',
                'village_code' => 'V1',
            ]))
            ->assertOk()
            ->assertSee('name="q"', false)
            ->assertSee('Tìm tên hoặc tên đăng nhập')
            ->assertSee('Tỉnh một')
            ->assertSee('Xã một')
            ->assertSee('Thôn một')
            ->assertSee('Người dùng thôn một')
            ->assertSee('aria-expanded="false"', false)
            ->assertDontSee('class="tree-body open"', false)
            ->assertSee('id="organization-unit-tree"', false)
            ->assertSee('Cây đơn vị')
            ->assertDontSee('Cây đơn vị và thống kê')
            ->assertSee('data-tree-toggle', false)
            ->assertSee('id="account-tree-results"', false)
            ->assertDontSee('fa-pen-to-square')
            ->assertDontSee('fa-lock')
            ->assertDontSee('>Lọc địa bàn</button>', false)
            ->assertDontSee('Thôn hai')
            ->assertDontSee('Người dùng thôn hai')
            ->assertSee('Tỉnh hai')
            ->assertDontSee('Người dùng tỉnh hai');

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('id="organization-unit-tree"', false)
            ->assertSee('Số bên cạnh là tài khoản trong đơn vị và các cấp dưới.');
    }

    public function test_system_admin_account_tree_paginates_account_cards(): void
    {
        DB::table('provinces')->insert([
            'code' => 'P1',
            'name' => 'Tỉnh một',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $adminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach($adminRole);
        $accountRole = Role::create([
            'name' => 'Quản trị tỉnh',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);

        foreach (range(0, 30) as $index) {
            $user = User::factory()->create([
                'name' => sprintf('Tài khoản %02d', $index),
                'username' => sprintf('account-%02d', $index),
                'province_code' => 'P1',
                'scope_level' => 'province',
                'is_active' => true,
            ]);
            $user->roles()->attach($accountRole);
        }

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('31')
            ->assertSee('Tài khoản 00')
            ->assertDontSee('Tài khoản 30')
            ->assertSee('data-pagination-select', false);

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['page' => 4]))
            ->assertOk()
            ->assertSee('Tài khoản 30')
            ->assertDontSee('Tài khoản 00');
    }

    public function test_inline_role_member_pagination_returns_only_the_member_table(): void
    {
        $adminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach($adminRole);
        $group = Role::create([
            'name' => 'Nhóm nhiều thành viên',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);

        foreach (range(1, 6) as $index) {
            $member = User::factory()->create([
                'name' => "Thành viên {$index}",
                'username' => "member-{$index}",
                'scope_level' => 'province',
                'is_active' => true,
            ]);
            $group->users()->attach($member);
        }

        $this->actingAs($admin)
            ->get(route('admin.roles.members', $group), [
                'HTTP_ACCEPT' => 'text/html',
                'HTTP_X-Requested-With' => 'XMLHttpRequest',
            ])
            ->assertOk()
            ->assertSee('Thành viên 1')
            ->assertDontSee('Thành viên 6')
            ->assertSee('aria-label="Phân trang thành viên nhóm"', false)
            ->assertSee('aria-label="Chọn trang thành viên"', false)
            ->assertSee('page=2', false)
            ->assertDontSee('<!DOCTYPE html>', false);

        $this->get(route('admin.roles.members', ['role' => $group, 'page' => 2]), [
            'HTTP_ACCEPT' => 'text/html',
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
        ])
            ->assertOk()
            ->assertSee('Thành viên 6')
            ->assertDontSee('Thành viên 1')
            ->assertSee('page=2" selected', false)
            ->assertDontSee('<!DOCTYPE html>', false);
    }

    public function test_management_accounts_show_a_crown_and_appear_before_regular_accounts(): void
    {
        $adminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach($adminRole);

        $manageUsers = Permission::create([
            'name' => AccessPermission::MANAGE_USER_ACCOUNTS,
            'guard_name' => 'web',
        ]);
        $provinceRole = Role::create([
            'name' => 'Quản trị tỉnh',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $provinceRole->permissions()->attach($manageUsers);
        $communeRole = Role::create([
            'name' => 'Quản trị xã',
            'scope_level' => 'commune',
            'guard_name' => 'web',
        ]);
        $communeRole->permissions()->attach($manageUsers);
        $regularRole = Role::create([
            'name' => 'Chỉ xem dữ liệu',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $regularRole->permissions()->attach(Permission::create([
            'name' => AccessPermission::VIEW_HOUSEHOLDS,
            'guard_name' => 'web',
        ]));

        foreach (
            [
                ['name' => 'Tài khoản thường', 'role' => $regularRole, 'scope' => 'province'],
                ['name' => 'Tài khoản quản trị xã', 'role' => $communeRole, 'scope' => 'commune'],
                ['name' => 'Tài khoản quản trị tỉnh', 'role' => $provinceRole, 'scope' => 'province'],
            ] as $account
        ) {
            $user = User::factory()->create([
                'name' => $account['name'],
                'scope_level' => $account['scope'],
                'is_active' => true,
            ]);
            $user->roles()->attach($account['role']);
        }

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('fa-crown')
            ->assertSeeTextInOrder([
                'Tài khoản quản trị tỉnh',
                'Tài khoản quản trị xã',
                'Tài khoản thường',
            ]);
    }

    public function test_add_village_form_prefills_assigned_province_and_commune(): void
    {
        DB::table('provinces')->insert([
            'code' => 'P1',
            'name' => 'Tỉnh một',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('communes')->insert([
            'code' => 'C1',
            'name' => 'Xã được giao',
            'province_code' => 'P1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $role = Role::create([
            'name' => 'commune-village-manager',
            'scope_level' => 'commune',
            'guard_name' => 'web',
        ]);
        $role->permissions()->attach(Permission::create([
            'name' => AccessPermission::MANAGE_VILLAGES,
            'guard_name' => 'web',
        ]));
        $user = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'commune',
            'is_active' => true,
        ]);
        $user->roles()->attach($role);

        $this->actingAs($user)
            ->get('/villages/create')
            ->assertOk()
            ->assertSee('value="P1" selected', false)
            ->assertSee('data-selected-code="C1"', false);

        $this->get('/villages')
            ->assertOk()
            ->assertSee('value="P1" selected', false)
            ->assertSee('data-selected-code="C1"', false);
    }

    public function test_commune_user_sees_only_assigned_commune_schools_and_cannot_submit_another_commune(): void
    {
        Schema::create('ethnicities', function (Blueprint $table): void {
            $table->string('code')->primary();
            $table->string('name');
            $table->timestamps();
        });
        DB::table('provinces')->insert(['code' => 'P1', 'name' => 'Tỉnh một', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('communes')->insert([
            ['code' => 'C1', 'name' => 'Xã được giao', 'province_code' => 'P1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'C2', 'name' => 'Xã khác', 'province_code' => 'P1', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('schools')->insert([
            ['code' => 'S1', 'name' => 'Trường được giao', 'province_code' => 'P1', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'S2', 'name' => 'Trường ngoài phạm vi', 'province_code' => 'P1', 'commune_code' => 'C2', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'S3', 'name' => null, 'province_code' => 'P1', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('ethnicities')->insert(['code' => 'KINH', 'name' => 'Kinh', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('households')->insert([
            'household_code' => 'HK1',
            'school_year' => '2025-2026',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $role = Role::create(['name' => 'commune-form-editor', 'scope_level' => 'commune', 'guard_name' => 'web']);
        $role->permissions()->attach([
            Permission::create(['name' => AccessPermission::CREATE_HOUSEHOLDS, 'guard_name' => 'web'])->id,
            Permission::create(['name' => AccessPermission::VIEW_HOUSEHOLDS, 'guard_name' => 'web'])->id,
        ]);
        $user = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'commune',
            'is_active' => true,
        ]);
        $user->roles()->attach($role);

        $this->actingAs($user)
            ->get('/households/1/members/create?is_head=1')
            ->assertOk()
            ->assertSee('value="S1"', false)
            ->assertSee('Chưa cập nhật tên (S3)')
            ->assertDontSee('value="S2"', false);

        $this->get('/api/provinces/P1/schools?commune_code=C1')
            ->assertOk()
            ->assertJsonFragment(['code' => 'S3', 'name' => 'Chưa cập nhật tên (S3)'])
            ->assertJsonMissing(['code' => 'S2']);

        $this->post('/households/1/members?is_head=1', [
            'first_name' => 'An',
            'last_name' => 'Nguyễn',
            'dob' => '2010-01-01',
            'gender' => 'NAM',
            'ethnicity_code' => 'KINH',
            'school_province_code' => 'P1',
            'school_commune_code' => 'C2',
            'education' => [
                'school_year' => '2025-2026',
                'school_code' => 'S2',
            ],
        ])
            ->assertSessionHasErrors('school_commune_code');

        $this->assertSame(0, DB::table('persons')->count());
    }

    private function currentSchoolYear(): string
    {
        $year = now()->month >= 8 ? now()->year : now()->year - 1;

        return "{$year}-" . ($year + 1);
    }

    public function test_import_rejects_school_code_outside_the_selected_commune(): void
    {
        Schema::create('import_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('file_name');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('file_path')->nullable();
            $table->string('error_file_path')->nullable();
            $table->json('context')->nullable();
            $table->integer('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->integer('success_rows')->default(0);
            $table->integer('error_rows')->default(0);
            $table->string('status')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::table('households', function (Blueprint $table): void {
            $table->unsignedBigInteger('import_log_id')->nullable();
        });
        Schema::table('persons', function (Blueprint $table): void {
            $table->unsignedBigInteger('import_log_id')->nullable();
        });
        Schema::table('person_educations', function (Blueprint $table): void {
            $table->unsignedBigInteger('import_log_id')->nullable();
        });
        Schema::create('person_disabilities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('person_id');
            $table->timestamps();
        });
        Queue::fake();
        Storage::fake('local');

        Schema::create('ethnicities', function (Blueprint $table): void {
            $table->string('code')->primary();
            $table->string('name');
            $table->timestamps();
        });
        DB::table('provinces')->insert(['code' => 'P1', 'name' => 'Tỉnh một', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('communes')->insert([
            ['code' => 'C1', 'name' => 'Xã được giao', 'province_code' => 'P1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'C2', 'name' => 'Xã khác', 'province_code' => 'P1', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('villages')->insert([
            'code' => 'V1',
            'name' => 'Thôn được chọn',
            'commune_code' => 'C1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('schools')->insert([
            'code' => 'S2',
            'name' => 'Trường ngoài phạm vi',
            'province_code' => 'P1',
            'commune_code' => 'C2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('ethnicities')->insert(['code' => 'KINH', 'name' => 'Kinh', 'created_at' => now(), 'updated_at' => now()]);

        $role = Role::create(['name' => 'commune-importer', 'scope_level' => 'commune', 'guard_name' => 'web']);
        $role->permissions()->attach(Permission::create([
            'name' => AccessPermission::IMPORT_HOUSEHOLDS,
            'guard_name' => 'web',
        ]));
        $user = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'commune',
            'is_active' => true,
        ]);
        $user->roles()->attach($role);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('O2', 'Mã hộ');
        $sheet->setCellValue('AY2', 'Ghi chú');
        $sheet->setCellValue('C5', 'Nguyễn');
        $sheet->setCellValue('D5', 'An');
        $sheet->setCellValue('E5', '1');
        $sheet->setCellValue('F5', '1');
        $sheet->setCellValue('G5', now()->year + 1);
        $sheet->setCellValue('I5', 'Kinh');
        $sheet->setCellValue('L5', 'Nguyễn');
        $sheet->setCellValue('M5', 'An');
        $sheet->setCellValue('O5', 'HK-IMPORT');
        $sheet->setCellValue('V5', 'S2');
        $sheet->setCellValue('AV5', 'Chủ hộ');
        $spreadsheet->createSheet(0)
            ->setTitle('XXXX')
            ->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        $spreadsheet->setActiveSheetIndex(1);
        $makeImportFile = function () use ($spreadsheet): UploadedFile {
            ob_start();
            (new Xls($spreadsheet))->save('php://output');
            $fileContents = ob_get_clean();

            return UploadedFile::fake()->createWithContent('import.xls', $fileContents);
        };
        $currentSchoolYearStart = now()->month >= 8 ? now()->year : now()->year - 1;
        $previousSchoolYear = ($currentSchoolYearStart - 1) . '-' . $currentSchoolYearStart;

        $this->actingAs($user)
            ->post('/households/import', [
                'file' => $makeImportFile(),
                'school_year' => $this->currentSchoolYear(),
                'province_code' => 'P1',
                'commune_code' => 'C1',
            ])
            ->assertSessionHasErrors('village_code');

        $this->postJson('/households/import', [
            'file' => $makeImportFile(),
            'school_year' => $previousSchoolYear,
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'village_code' => 'V1',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('school_year');

        $response = $this->postJson('/households/import', [
            'file' => $makeImportFile(),
            'school_year' => $this->currentSchoolYear(),
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'village_code' => 'V1',
        ])->assertAccepted();

        $importLog = ImportLog::findOrFail($response->json('import_id'));
        Queue::assertPushed(ProcessHouseholdImport::class);
        $this->getJson($response->json('status_url'))
            ->assertOk()
            ->assertJsonPath('status', 'PENDING')
            ->assertJsonPath('progress', 0);
        (new ProcessHouseholdImport($importLog->id))->handle(app(HouseholdImportProcessingService::class));
        $this->assertSame('FAILED', $importLog->fresh()->status);
        $this->assertNotNull($importLog->fresh()->error_file_path);
        $annotatedWorkbook = IOFactory::load(Storage::disk('local')->path($importLog->fresh()->error_file_path));
        $errorMessage = $annotatedWorkbook->getSheet(1)->getCell('A5')->getValue();
        $this->assertStringContainsString('không thuộc xã được chọn', $errorMessage);
        $this->assertStringContainsString('Ngày sinh không được ở tương lai.', $errorMessage);
        $annotatedWorkbook->disconnectWorksheets();
        $originalWorkbook = IOFactory::load(Storage::disk('local')->path($importLog->file_path));
        $this->assertEmpty($originalWorkbook->getSheet(1)->getCell('A5')->getValue());
        $originalWorkbook->disconnectWorksheets();
        $this->getJson($response->json('status_url'))
            ->assertOk()
            ->assertJsonPath('status', 'FAILED')
            ->assertJsonPath('progress', 90)
            ->assertJsonPath('has_error_file', true);
        $this->assertSame(0, DB::table('households')->count());

        $otherUser = User::factory()->create();
        ImportLog::create([
            'file_name' => 'file-khac.xls',
            'user_id' => $otherUser->id,
            'status' => 'SUCCESS',
        ]);
        $this->get('/households/imports')
            ->assertOk()
            ->assertSee('import.xls')
            ->assertSee('Xử lý Excel')
            ->assertSee('Tải file lỗi')
            ->assertDontSee('Xem chi tiết lỗi')
            ->assertDontSee('Ngày sinh không được ở tương lai.')
            ->assertDontSee('file-khac.xls');
        $this->get(route('households.import.download', $importLog))
            ->assertOk()
            ->assertHeader('content-disposition');
        $this->get(route('households.import.download-errors', $importLog))
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=import-loi.xls');
        $importLog->update(['error_file_path' => null]);
        $this->get(route('households.import.download-errors', $importLog))
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=import-loi.xls');
        $this->assertNotNull($importLog->fresh()->error_file_path);
        $this->deleteJson(route('households.import.destroy', $importLog))
            ->assertOk()
            ->assertJsonPath('message', 'Đã xóa lịch sử tải file');
        $this->assertDatabaseMissing('import_logs', ['id' => $importLog->id]);

        $successfulImport = ImportLog::create([
            'file_name' => 'successful.xls',
            'user_id' => $user->id,
            'status' => 'SUCCESS',
            'success_rows' => 2,
            'context' => ['school_year' => '2025-2026'],
        ]);
        $householdId = DB::table('households')->insertGetId([
            'household_code' => 'IMPORTED-1',
            'school_year' => '2025-2026',
            'import_log_id' => $successfulImport->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $personId = DB::table('persons')->insertGetId([
            'household_id' => $householdId,
            'import_log_id' => $successfulImport->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('person_educations')->insert([
            'person_id' => $personId,
            'import_log_id' => $successfulImport->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('person_disabilities')->insert([
            'person_id' => $personId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherImport = ImportLog::create([
            'file_name' => 'other-successful.xls',
            'user_id' => $user->id,
            'status' => 'SUCCESS',
            'success_rows' => 1,
        ]);
        $otherHouseholdId = DB::table('households')->insertGetId([
            'household_code' => 'IMPORTED-OTHER',
            'school_year' => '2025-2026',
            'import_log_id' => $otherImport->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson(route('households.import.destroy', $successfulImport))
            ->assertOk()
            ->assertJsonPath('message', 'Đã xóa lịch sử tải file');
        $this->assertDatabaseHas('households', ['id' => $householdId]);
        $this->assertDatabaseHas('persons', ['id' => $personId]);
        $this->assertDatabaseHas('person_educations', ['person_id' => $personId]);
        $this->assertDatabaseHas('person_disabilities', ['person_id' => $personId]);
        $this->assertDatabaseHas('households', ['id' => $otherHouseholdId]);
        $this->assertDatabaseMissing('import_logs', ['id' => $successfulImport->id]);
        $this->deleteJson(route('households.import.destroy', $successfulImport))
            ->assertNotFound();
        $this->get('/households/imports')
            ->assertOk()
            ->assertSee('other-successful.xls');

        $sheet->setCellValue('O2', 'Số phiếu');
        $sheet->setCellValue('V5', 'S-UNKNOWN');
        $response = $this->postJson('/households/import', [
            'file' => $makeImportFile(),
            'school_year' => $this->currentSchoolYear(),
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'village_code' => 'V1',
        ])->assertAccepted();

        $importLog = ImportLog::findOrFail($response->json('import_id'));
        (new ProcessHouseholdImport($importLog->id))->handle(app(HouseholdImportProcessingService::class));
        $this->assertSame('FAILED', $importLog->fresh()->status);
        $this->assertNotNull($importLog->fresh()->error_file_path);
        $annotatedWorkbook = IOFactory::load(Storage::disk('local')->path($importLog->fresh()->error_file_path));
        $this->assertStringContainsString(
            'không tồn tại trong danh mục trường học',
            $annotatedWorkbook->getSheet(1)->getCell('A5')->getValue()
        );
        $annotatedWorkbook->disconnectWorksheets();
        $this->assertDatabaseMissing('households', ['household_code' => 'HK-IMPORT']);
        $this->assertDatabaseMissing('schools', ['code' => 'S-UNKNOWN']);
        $spreadsheet->disconnectWorksheets();
    }

    public function test_village_and_school_catalog_permissions_are_independent_and_school_management_is_province_only(): void
    {
        $villagePermission = Permission::create([
            'name' => AccessPermission::MANAGE_VILLAGES,
            'guard_name' => 'web',
        ]);
        $schoolPermission = Permission::create([
            'name' => AccessPermission::MANAGE_SCHOOLS,
            'guard_name' => 'web',
        ]);
        $legacyPermission = Permission::create([
            'name' => 'manage-catalogs',
            'guard_name' => 'web',
        ]);

        $villageManager = User::factory()->create([
            'scope_level' => 'commune',
            'is_active' => true,
        ]);
        $villageRole = Role::create([
            'name' => 'village-catalog-manager',
            'scope_level' => 'commune',
            'guard_name' => 'web',
        ]);
        $villageRole->permissions()->attach($villagePermission);
        $villageManager->roles()->attach($villageRole, ['assigned_by' => null]);
        $this->assertTrue(Gate::forUser($villageManager)->allows(AccessPermission::MANAGE_VILLAGES));
        $this->assertFalse(Gate::forUser($villageManager)->allows(AccessPermission::MANAGE_SCHOOLS));
        $this->actingAs($villageManager)
            ->get('/villages')
            ->assertOk()
            ->assertSee('Danh sách thôn/xóm')
            ->assertSee('data-turbo="false"', false);
        $this->get('/schools')->assertForbidden();

        $legacyManager = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'commune',
            'is_active' => true,
        ]);
        $legacyRole = Role::create([
            'name' => 'legacy-catalog-manager',
            'scope_level' => 'commune',
            'guard_name' => 'web',
        ]);
        $legacyRole->permissions()->attach($legacyPermission);
        $legacyManager->roles()->attach($legacyRole, ['assigned_by' => null]);
        $this->assertFalse(Gate::forUser($legacyManager)->allows(AccessPermission::MANAGE_VILLAGES));
        $this->assertFalse(Gate::forUser($legacyManager)->allows(AccessPermission::MANAGE_SCHOOLS));
        $this->assertFalse(Gate::forUser($legacyManager)->allows('manage-catalogs'));
        $this->actingAs($legacyManager)->get('/schools')->assertForbidden();

        $provinceManager = User::factory()->create([
            'province_code' => 'P1',
            'scope_level' => 'province',
            'is_active' => true,
        ]);
        $provinceRole = Role::create([
            'name' => 'province-school-manager',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $provinceRole->permissions()->attach($schoolPermission);
        $provinceManager->roles()->attach($provinceRole, ['assigned_by' => null]);
        $this->assertTrue(Gate::forUser($provinceManager)->allows(AccessPermission::MANAGE_SCHOOLS));
        $this->actingAs($provinceManager)
            ->get('/schools')
            ->assertOk()
            ->assertSee('data-turbo="false"', false);
    }

    public function test_village_and_school_pagination_stays_out_of_turbo_navigation(): void
    {
        $villagePermission = Permission::create([
            'name' => AccessPermission::MANAGE_VILLAGES,
            'guard_name' => 'web',
        ]);
        $villageRole = Role::create([
            'name' => 'village-pagination-manager',
            'scope_level' => 'commune',
            'guard_name' => 'web',
        ]);
        $villageRole->permissions()->attach($villagePermission);
        $villageManager = User::factory()->create([
            'scope_level' => 'commune',
            'is_active' => true,
        ]);
        $villageManager->roles()->attach($villageRole, ['assigned_by' => null]);

        $this->actingAs($villageManager)
            ->get('/villages')
            ->assertOk()
            ->assertSee('class="mt-2" data-turbo="false"', false);

        $schoolPermission = Permission::create([
            'name' => AccessPermission::MANAGE_SCHOOLS,
            'guard_name' => 'web',
        ]);
        $schoolRole = Role::create([
            'name' => 'school-pagination-manager',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $schoolRole->permissions()->attach($schoolPermission);
        $schoolManager = User::factory()->create([
            'scope_level' => 'province',
            'is_active' => true,
        ]);
        $schoolManager->roles()->attach($schoolRole, ['assigned_by' => null]);

        $this->actingAs($schoolManager)
            ->get('/schools')
            ->assertOk()
            ->assertSee('class="mt-2" data-turbo="false"', false);
    }

    public function test_system_administrator_creates_province_and_limited_central_accounts(): void
    {
        DB::table('provinces')->insert([
            ['code' => 'P1', 'name' => 'Tỉnh một', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'P2', 'name' => 'Tỉnh hai', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('communes')->insert([
            ['code' => 'C1', 'name' => 'Xã một', 'province_code' => 'P1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'C2', 'name' => 'Xã hai', 'province_code' => 'P1', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('villages')->insert([
            ['code' => 'V1', 'name' => 'Thôn một', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'V2', 'name' => 'Thôn hai', 'commune_code' => 'C2', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $adminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach($adminRole);
        $viewHouseholdsPermission = Permission::create([
            'name' => AccessPermission::VIEW_HOUSEHOLDS,
            'guard_name' => 'web',
        ]);
        $createHouseholdsPermission = Permission::create([
            'name' => AccessPermission::CREATE_HOUSEHOLDS,
            'guard_name' => 'web',
        ]);
        $manageAccountsPermission = Permission::create([
            'name' => AccessPermission::MANAGE_USER_ACCOUNTS,
            'guard_name' => 'web',
        ]);
        $manageGroupsPermission = Permission::create([
            'name' => AccessPermission::MANAGE_ACCESS_CONTROL,
            'guard_name' => 'web',
        ]);
        $manageSchoolsPermission = Permission::create([
            'name' => AccessPermission::MANAGE_SCHOOLS,
            'guard_name' => 'web',
        ]);
        $manageVillagesPermission = Permission::create([
            'name' => AccessPermission::MANAGE_VILLAGES,
            'guard_name' => 'web',
        ]);
        $this->actingAs($admin)->post('/admin/roles', [
            'name' => 'Quản lý cấp tỉnh',
            'scope_level' => 'province',
            'description' => 'Quản lý trường học và tạo nhóm, tài khoản cấp dưới.',
            'permissions' => [
                $manageAccountsPermission->id,
                $manageGroupsPermission->id,
                $manageSchoolsPermission->id,
            ],
        ])->assertRedirectToRoute('admin.roles.index');
        $provinceRole = Role::where('name', 'Quản lý cấp tỉnh')->firstOrFail();
        $this->assertSame('province', $provinceRole->scope_level);

        $this->from('/admin/roles/create')->post('/admin/roles', [
            'name' => 'Nhóm cấp xã do Trung ương tạo',
            'scope_level' => 'commune',
            'permissions' => [$viewHouseholdsPermission->id],
        ])->assertSessionHasErrors('scope_level');
        $unassignedCentralCommuneRole = Role::create([
            'name' => 'Nhóm cấp xã khác',
            'scope_level' => 'commune',
            'guard_name' => 'web',
        ]);
        $unassignedCentralCommuneRole->permissions()->attach($viewHouseholdsPermission);

        $legacyGlobalRole = Role::create([
            'name' => 'legacy-global-viewer',
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $legacyGlobalRole->permissions()->attach($viewHouseholdsPermission);

        $this->from('/admin/roles/create')->post('/admin/roles', [
            'name' => 'Nhóm thôn do trung ương tạo',
            'scope_level' => 'village',
            'permissions' => [$viewHouseholdsPermission->id],
        ])->assertSessionHasErrors('scope_level');

        $this->actingAs($admin)->post('/admin/roles', [
            'name' => 'Vai trò trung ương chỉ xem',
            'scope_level' => 'global',
            'permissions' => [$viewHouseholdsPermission->id],
        ])->assertRedirectToRoute('admin.roles.index');
        $limitedCentralRole = Role::where('name', 'Vai trò trung ương chỉ xem')->firstOrFail();

        $this->actingAs($admin)
            ->get('/admin/users/create')
            ->assertOk()
            ->assertSee('Quản lý cấp tỉnh')
            ->assertDontSee('Nhóm cấp xã khác')
            ->assertSee('Vai trò trung ương chỉ xem')
            ->assertSee('Nhóm người dùng / phòng ban')
            ->assertDontSee('Chọn quyền riêng')
            ->assertSee('Cấp hoạt động')
            ->assertSee('id="scope_level"', false)
            ->assertSee('value="global"', false)
            ->assertSee('value="province"', false)
            ->assertDontSee('<option value="commune"', false)
            ->assertDontSee('<option value="village"', false);

        $this->post('/admin/users', [
            'name' => 'Tài khoản trung ương giới hạn',
            'username' => 'central-limited',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
            'scope_level' => 'global',
            'role_id' => $limitedCentralRole->id,
            'is_active' => '1',
        ])->assertRedirectToRoute('admin.users.index');

        $centralUser = User::where('name', 'Tài khoản trung ương giới hạn')->firstOrFail();
        $this->assertSame('global', $centralUser->roleScopeLevel());
        $this->assertFalse($centralUser->hasRole(Role::SYSTEM_ADMIN));
        $this->assertTrue($centralUser->hasPermissionTo(AccessPermission::VIEW_HOUSEHOLDS));
        $this->assertFalse($centralUser->hasPermissionTo(AccessPermission::CREATE_HOUSEHOLDS));
        $this->assertFalse($centralUser->hasPermissionTo(AccessPermission::MANAGE_USER_ACCOUNTS));
        $this->actingAs($centralUser)->get('/admin/users/create')->assertForbidden();

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertOk()
            ->assertSee('Tài khoản trung ương giới hạn')
            ->assertSee('user-card-central', false)
            ->assertSee('account-scope-badge">Trung ương', false)
            ->assertSee(route('admin.users.status', $centralUser))
            ->assertSee(route('admin.users.reset-password', $centralUser));
        $this->post(route('admin.users.reset-password', $centralUser))
            ->assertRedirectToRoute('admin.users.index');
        $this->assertTrue(Hash::check(User::TEMPORARY_PASSWORD, $centralUser->fresh()->password));
        $this->patch(route('admin.users.status', $centralUser), ['is_active' => '0'])
            ->assertRedirectToRoute('admin.users.index');
        $this->assertDatabaseHas('users', ['id' => $centralUser->id, 'is_active' => false]);

        $this->actingAs($admin)->from('/admin/users/create')->post('/admin/users', [
            'name' => 'Tài khoản giả quản trị',
            'username' => 'central-admin-escalation',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
            'scope_level' => 'global',
            'role_id' => $adminRole->id,
            'is_active' => '1',
        ])->assertSessionHasErrors('role_id');

        $this->post('/admin/users', [
            'name' => 'Tài khoản tỉnh',
            'username' => 'province-account',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
            'scope_level' => 'province',
            'role_id' => $provinceRole->id,
            'province_code' => 'P1',
            'is_active' => '1',
        ])->assertRedirectToRoute('admin.users.index');

        $this->from('/admin/users/create')->post('/admin/users', [
            'name' => 'Tài khoản tỉnh trùng',
            'username' => 'province-duplicate',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
            'scope_level' => 'province',
            'role_id' => $provinceRole->id,
            'province_code' => 'P1',
            'is_active' => '1',
        ])->assertSessionHasErrors('province_code');

        $this->post('/admin/users', [
            'name' => 'Tài khoản tỉnh khác',
            'username' => 'province-other',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
            'scope_level' => 'province',
            'role_id' => $provinceRole->id,
            'province_code' => 'P2',
            'is_active' => '1',
        ])->assertRedirectToRoute('admin.users.index');

        $this->actingAs($admin)
            ->get('/admin/users/create')
            ->assertOk()
            ->assertSee('Đã tạo đầy đủ tài khoản quản trị cho các tỉnh/thành phố.')
            ->assertSee('data-requires-province="1"', false)
            ->assertSee("saveButton.dataset.requiresProvince === '1' && !isGlobal", false);

        $this->post('/admin/users', [
            'name' => 'Tài khoản trung ương sau khi đủ tỉnh',
            'username' => 'central-after-provinces-full',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
            'scope_level' => 'global',
            'role_id' => $limitedCentralRole->id,
            'is_active' => '1',
        ])->assertRedirectToRoute('admin.users.index');

        $this->from('/admin/users/create')->post('/admin/users', [
            'name' => 'Tài khoản tỉnh dùng nhóm cũ',
            'username' => 'province-global-role',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
            'scope_level' => 'province',
            'role_id' => $legacyGlobalRole->id,
            'province_code' => 'P1',
            'is_active' => '1',
        ])->assertSessionHasErrors('role_id');

        $this->from('/admin/users/create')->post('/admin/users', [
            'name' => 'Tài khoản xã vượt cấp',
            'username' => 'commune-direct',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
            'role_id' => $provinceRole->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => '1',
        ])->assertSessionHasErrors('scope_level');

        $provinceUser = User::where('username', 'province-account')->firstOrFail();
        $this->assertSame('province', $provinceUser->roleScopeLevel());
        $this->assertTrue($provinceUser->hasPermissionTo(AccessPermission::MANAGE_SCHOOLS));
        $this->assertFalse($provinceUser->hasPermissionTo(AccessPermission::CREATE_HOUSEHOLDS));

        $this->actingAs($provinceUser)->post('/admin/roles', [
            'name' => 'Nhóm tỉnh chỉ xem',
            'scope_level' => 'province',
            'permissions' => [$viewHouseholdsPermission->id],
        ])->assertSessionHasErrors('permissions');

        $this->actingAs($provinceUser)->get('/admin/users/create')
            ->assertOk()
            ->assertSee('value="province"', false)
            ->assertSee('value="commune"', false);

        $this->actingAs($provinceUser)->post('/admin/roles', [
            'name' => 'Nhóm quản lý cấp xã',
            'scope_level' => 'commune',
            'description' => 'Quản lý phiếu, địa bàn và tài khoản thôn.',
            'permission_groups' => ['households'],
            'permissions' => [
                $createHouseholdsPermission->id,
                $manageAccountsPermission->id,
                $manageGroupsPermission->id,
                $manageVillagesPermission->id,
            ],
        ])->assertRedirectToRoute('admin.roles.index');
        $communeRole = Role::where('name', 'Nhóm quản lý cấp xã')->firstOrFail();
        $this->assertSame($provinceUser->id, $communeRole->created_by);
        $this->assertEqualsCanonicalizing(
            AccessPermission::permissionsForScope('commune'),
            $communeRole->permissions()->pluck('name')->all()
        );

        $this->actingAs($provinceUser)
            ->get('/admin/roles')
            ->assertOk()
            ->assertSee('Nhóm quản lý cấp xã')
            ->assertDontSee('Nhóm cấp xã khác')
            ->assertDontSee('Quản lý cấp tỉnh')
            ->assertDontSee('Nhóm không do tài khoản này tạo');

        $this->actingAs($admin)
            ->get('/admin/roles')
            ->assertOk()
            ->assertDontSee('Nhóm cấp xã khác')
            ->assertDontSee('Nhóm quản lý cấp xã');
        $this->get("/admin/roles/{$communeRole->id}/edit")->assertForbidden();

        $this->actingAs($provinceUser)
            ->get('/admin/users/create')
            ->assertOk()
            ->assertSee('Nhóm quản lý cấp xã')
            ->assertDontSee('Nhóm cấp xã khác')
            ->assertSee('data-role-scopes="commune"', false);

        $otherProvinceAccount = User::factory()->create(['province_code' => 'P1']);
        $otherProvinceRole = Role::create([
            'name' => 'Nhóm xã của tài khoản tỉnh khác',
            'scope_level' => 'commune',
            'guard_name' => 'web',
            'created_by' => $otherProvinceAccount->id,
            'province_code' => 'P1',
        ]);
        $otherProvinceRole->permissions()->attach($viewHouseholdsPermission);
        $this->actingAs($provinceUser)
            ->get('/admin/users/create')
            ->assertDontSee('Nhóm xã của tài khoản tỉnh khác');

        $this->actingAs($provinceUser)->post('/admin/users', [
            'name' => 'Tài khoản xã',
            'username' => 'commune-account',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
            'role_id' => $communeRole->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => '1',
        ])->assertRedirectToRoute('admin.users.index');

        $this->from('/admin/users/create')->post('/admin/users', [
            'name' => 'Tài khoản xã dùng nhóm tài khoản khác',
            'username' => 'commune-other-role',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
            'role_id' => $otherProvinceRole->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => '1',
        ])->assertSessionHasErrors('role_id');

        $communeUser = User::where('username', 'commune-account')->firstOrFail();
        $this->assertSame('commune', $communeUser->roleScopeLevel());
        $this->assertTrue($communeUser->hasRole('Nhóm quản lý cấp xã'));
        $this->assertTrue($communeUser->hasPermissionTo(AccessPermission::MANAGE_VILLAGES));
        $this->assertFalse($communeUser->hasPermissionTo(AccessPermission::MANAGE_SCHOOLS));

        $this->actingAs($communeUser)->post('/admin/roles', [
            'name' => 'Nhóm xã chỉ xem',
            'scope_level' => 'commune',
            'permissions' => [$viewHouseholdsPermission->id],
        ])->assertRedirectToRoute('admin.roles.index');

        $this->from('/admin/users/create')->post('/admin/users', [
            'name' => 'Tài khoản xã trùng',
            'username' => 'commune-viewer',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
            'role_id' => $communeRole->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => '1',
        ])->assertSessionHasErrors('role_id');

        foreach (AccessPermission::definitions() as $name => $description) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['description' => $description]
            );
        }
        $this->actingAs($communeUser)
            ->get('/admin/roles/create')
            ->assertOk()
            ->assertSee('Quản trị về nhân khẩu và phiếu')
            ->assertSee('create-households')
            ->assertSee('update-households')
            ->assertSee('delete-households')
            ->assertSee('import-households')
            ->assertSee('export-households')
            ->assertSee('view-households')
            ->assertSee('data-household-permission="true"', false);

        $this->actingAs($communeUser)
            ->get('/admin/roles')
            ->assertOk()
            ->assertDontSee('Nhóm quản lý cấp xã')
            ->assertSee('Nhóm xã chỉ xem')
            ->assertDontSee('Nhóm cấp xã khác')
            ->assertDontSee('Quản lý cấp tỉnh');

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertOk()
            ->assertSee('commune-account');
        $this->get("/admin/users/{$communeUser->id}/edit")->assertForbidden();

        $this->actingAs($communeUser)->post('/admin/roles', [
            'name' => 'Nhóm nhập liệu thôn',
            'scope_level' => 'village',
            'description' => 'Được xem và tạo phiếu trong thôn được giao.',
            'permissions' => [$viewHouseholdsPermission->id, $createHouseholdsPermission->id],
        ])->assertRedirectToRoute('admin.roles.index');
        $villageRole = Role::where('name', 'Nhóm nhập liệu thôn')->firstOrFail();
        $this->assertSame($communeUser->id, $villageRole->created_by);
        $this->actingAs($communeUser)
            ->get('/admin/roles')
            ->assertOk()
            ->assertDontSee('Nhóm quản lý cấp xã')
            ->assertSee('Nhóm xã chỉ xem')
            ->assertSee('Nhóm nhập liệu thôn')
            ->assertDontSee('Nhóm cấp xã khác')
            ->assertDontSee('Quản lý cấp tỉnh');

        $this->actingAs($communeUser)->post('/admin/users', [
            'name' => 'Tài khoản thôn',
            'username' => 'village-account',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
            'role_id' => $villageRole->id,
            'scope_level' => 'village',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'village_codes' => ['V1'],
            'is_active' => '1',
        ])->assertRedirectToRoute('admin.users.index');

        $villageUser = User::where('username', 'village-account')->firstOrFail();
        $this->assertSame('village', $villageUser->roleScopeLevel());
        $this->assertTrue($villageUser->hasRole('Nhóm nhập liệu thôn'));
        $this->assertTrue($villageUser->hasPermissionTo(AccessPermission::CREATE_HOUSEHOLDS));
        $this->assertTrue($villageUser->hasPermissionTo(AccessPermission::VIEW_HOUSEHOLDS));
        $this->assertFalse($villageUser->hasPermissionTo(AccessPermission::MANAGE_VILLAGES));
        $this->assertFalse($villageUser->hasPermissionTo(AccessPermission::MANAGE_USER_ACCOUNTS));
        $this->assertSame(['V1'], $villageUser->managedVillageCodes());
        $this->assertDatabaseHas('model_has_roles', [
            'model_id' => $villageUser->id,
            'model_type' => User::class,
            'role_id' => $villageRole->id,
        ]);

        $this->actingAs($villageUser)
            ->get('/')
            ->assertOk()
            ->assertSee('Danh sách phiếu');
        $this->get('/households')->assertOk();
        $this->get('/households/create')->assertOk()->assertSee('Lưu phiếu và tiếp tục');
    }

    public function test_province_accounts_can_create_groups_with_same_name_independently(): void
    {
        $manageGroupsPermission = Permission::create([
            'name' => AccessPermission::MANAGE_ACCESS_CONTROL,
            'guard_name' => 'web',
        ]);
        $managerRole = Role::create([
            'name' => 'province-manager',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $managerRole->permissions()->attach($manageGroupsPermission);

        $firstManager = User::factory()->create([
            'province_code' => 'P1',
            'scope_level' => 'province',
            'is_active' => true,
        ]);
        $firstManager->roles()->attach($managerRole);

        $secondManager = User::factory()->create([
            'province_code' => 'P2',
            'scope_level' => 'province',
            'is_active' => true,
        ]);
        $secondManager->roles()->attach($managerRole);

        foreach ([$firstManager, $secondManager] as $manager) {
            $this->actingAs($manager)->post('/admin/roles', [
                'name' => 'Quản trị nhân khẩu',
                'scope_level' => 'commune',
            ])->assertRedirectToRoute('admin.roles.index');
        }

        $this->assertDatabaseHas('roles', [
            'name' => 'Quản trị nhân khẩu',
            'created_by' => $firstManager->id,
            'province_code' => 'P1',
        ]);
        $this->assertDatabaseHas('roles', [
            'name' => 'Quản trị nhân khẩu',
            'created_by' => $secondManager->id,
            'province_code' => 'P2',
        ]);
    }

    public function test_role_permission_seeder_does_not_create_shared_user_groups(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertDatabaseHas('roles', ['name' => Role::SYSTEM_ADMIN, 'scope_level' => 'global']);
        $this->assertDatabaseMissing('roles', ['name' => 'province-admin']);
        $this->assertDatabaseMissing('roles', ['name' => 'commune-admin']);
        $this->assertDatabaseMissing('roles', ['name' => 'village-officer']);
        $this->assertDatabaseMissing('roles', ['name' => 'Cán bộ điều tra, nhập liệu']);
    }

    public function test_system_administrator_can_revoke_and_restore_a_managed_users_login(): void
    {
        $adminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $provinceRole = Role::create([
            'name' => 'province-user',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach($adminRole);
        $target = User::factory()->create([
            'province_code' => 'P1',
            'scope_level' => 'province',
            'is_active' => true,
        ]);
        $target->roles()->attach($provinceRole);

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertOk()
            ->assertSee('Thu hồi tài khoản')
            ->assertSee(route('admin.users.status', $target));

        $this->patch(route('admin.users.status', $target), ['is_active' => '0'])
            ->assertRedirectToRoute('admin.users.index')
            ->assertSessionHas('success', 'Đã thu hồi quyền đăng nhập của tài khoản.');
        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => false]);

        $this->patch(route('admin.users.status', $target), ['is_active' => '1'])
            ->assertRedirectToRoute('admin.users.index')
            ->assertSessionHas('success', 'Đã kích hoạt lại tài khoản.');
        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => true]);
    }

    public function test_user_account_manager_can_revoke_a_user_in_their_area(): void
    {
        DB::table('provinces')->insert([
            'code' => 'P1',
            'name' => 'Tỉnh một',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('communes')->insert([
            'code' => 'C1',
            'name' => 'Xã một',
            'province_code' => 'P1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $managerRole = Role::create([
            'name' => 'province-account-manager',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $managerRole->permissions()->attach(Permission::create([
            'name' => AccessPermission::MANAGE_USER_ACCOUNTS,
            'guard_name' => 'web',
        ]));
        $manager = User::factory()->create([
            'province_code' => 'P1',
            'scope_level' => 'province',
            'is_active' => true,
        ]);
        $manager->roles()->attach($managerRole);
        $communeRole = Role::create([
            'name' => 'commune-user',
            'scope_level' => 'commune',
            'guard_name' => 'web',
            'created_by' => $manager->id,
            'province_code' => 'P1',
        ]);
        $communeRole->permissions()->attach(Permission::create([
            'name' => AccessPermission::CREATE_HOUSEHOLDS,
            'guard_name' => 'web',
        ]));
        $target = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'commune',
            'is_active' => true,
        ]);
        $target->roles()->attach($communeRole);
        $sameLevelAccount = User::factory()->create([
            'province_code' => 'P1',
            'scope_level' => 'province',
            'is_active' => true,
        ]);
        $sameLevelAccount->roles()->attach($managerRole);

        $this->actingAs($manager)
            ->get('/admin/users')
            ->assertOk()
            ->assertSee('Thu hồi tài khoản')
            ->assertSee(route('admin.users.status', $target))
            ->assertDontSee(route('admin.users.status', $sameLevelAccount));

        $this->get(route('admin.users.edit', $target))
            ->assertOk()
            ->assertSee('commune-user')
            ->assertSee('Tài khoản');

        $this->patch(route('admin.users.status', $target), ['is_active' => '0'])
            ->assertRedirectToRoute('admin.users.index');
        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => false]);
        $this->patch(route('admin.users.status', $sameLevelAccount), ['is_active' => '0'])
            ->assertForbidden();
    }

    public function test_commune_account_manager_can_revoke_village_accounts_only_in_its_commune(): void
    {
        DB::table('provinces')->insert([
            'code' => 'P1',
            'name' => 'Tỉnh một',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('communes')->insert([
            ['code' => 'C1', 'name' => 'Xã một', 'province_code' => 'P1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'C2', 'name' => 'Xã hai', 'province_code' => 'P1', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('villages')->insert([
            ['code' => 'V1', 'name' => 'Thôn một', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'V2', 'name' => 'Thôn hai', 'commune_code' => 'C2', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $manageUsers = Permission::create([
            'name' => AccessPermission::MANAGE_USER_ACCOUNTS,
            'guard_name' => 'web',
        ]);
        $managerRole = Role::create([
            'name' => 'commune-account-manager',
            'scope_level' => 'commune',
            'guard_name' => 'web',
        ]);
        $managerRole->permissions()->attach($manageUsers);
        $manager = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'commune',
            'is_active' => true,
        ]);
        $manager->roles()->attach($managerRole);

        $villageRole = Role::create([
            'name' => 'village-user',
            'scope_level' => 'village',
            'guard_name' => 'web',
            'created_by' => $manager->id,
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ]);
        $villageRole->permissions()->attach(Permission::create([
            'name' => AccessPermission::VIEW_HOUSEHOLDS,
            'guard_name' => 'web',
        ]));
        $target = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'village',
            'is_active' => true,
        ]);
        $target->roles()->attach($villageRole);
        $target->villages()->sync(['V1']);

        $outsideTarget = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C2',
            'scope_level' => 'village',
            'is_active' => true,
        ]);
        $outsideTarget->roles()->attach($villageRole);
        $outsideTarget->villages()->sync(['V2']);

        $this->actingAs($manager)
            ->get('/admin/users')
            ->assertOk()
            ->assertSee(route('admin.users.status', $target))
            ->assertDontSee(route('admin.users.status', $outsideTarget));

        $this->patch(route('admin.users.status', $target), ['is_active' => '0'])
            ->assertRedirectToRoute('admin.users.index');
        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => false]);
        $this->patch(route('admin.users.status', $outsideTarget), ['is_active' => '0'])
            ->assertForbidden();
    }

    public function test_system_administrator_can_restore_access_for_unassigned_users(): void
    {
        DB::table('provinces')->insert([
            'code' => 'P1',
            'name' => 'Tỉnh một',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('communes')->insert([
            'code' => 'C1',
            'name' => 'Xã một',
            'province_code' => 'P1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $adminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach($adminRole);

        $target = User::factory()->create([
            'username' => 'unassigned-account',
            'province_code' => null,
            'commune_code' => null,
            'is_active' => true,
        ]);
        $provinceRole = Role::create(['name' => 'province-officer', 'scope_level' => 'province', 'guard_name' => 'web']);

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertOk()
            ->assertSee('unassigned-account');
        $this->get("/admin/users/{$target->id}/edit")->assertOk();
        $this->put("/admin/users/{$target->id}", [
            'name' => $target->name,
            'username' => $target->username,
            'password' => '',
            'password_confirmation' => '',
            'role_id' => $provinceRole->id,
            'scope_level' => 'province',
            'province_code' => 'P1',
            'is_active' => '1',
        ])->assertRedirectToRoute('admin.users.index');

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'province_code' => 'P1',
            'commune_code' => null,
        ]);
        $this->assertTrue($target->fresh()->hasRole('province-officer'));
    }

    public function test_household_access_is_limited_to_assigned_commune(): void
    {
        DB::table('provinces')->insert([
            ['code' => 'P1', 'name' => 'Tỉnh một', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'P2', 'name' => 'Tỉnh hai', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('communes')->insert([
            ['code' => 'C1', 'name' => 'Xã một', 'province_code' => 'P1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'C2', 'name' => 'Xã hai', 'province_code' => 'P2', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('households')->insert([
            ['household_code' => '01', 'school_year' => '2026-2027', 'province_code' => 'P1', 'commune_code' => 'C1', 'head_first_name' => 'Trong vùng', 'created_at' => now(), 'updated_at' => now()],
            ['household_code' => '02', 'school_year' => '2026-2027', 'province_code' => 'P2', 'commune_code' => 'C2', 'head_first_name' => 'Ngoài vùng', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $role = Role::create(['name' => 'commune-viewer', 'scope_level' => 'commune', 'guard_name' => 'web']);
        $permission = Permission::create([
            'name' => AccessPermission::VIEW_HOUSEHOLDS,
            'guard_name' => 'web',
        ]);
        $role->permissions()->attach($permission);
        $user = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'commune',
            'is_active' => true,
        ]);
        $user->roles()->attach($role);

        $visibleCodes = DataAccessScope::households(Household::query(), $user)
            ->orderBy('household_code')
            ->pluck('household_code')
            ->all();

        $this->assertSame(['01'], $visibleCodes);
        $this->actingAs($user)->get('/households')
            ->assertOk()
            ->assertSee('01')
            ->assertSee('title="Xem chi tiết hộ"', false)
            ->assertDontSee('class="household-row"', false)
            ->assertDontSee('data-url=', false)
            ->assertSee('id="select-all-households"', false)
            ->assertSee('class="form-check-input household-checkbox"', false)
            ->assertDontSee('member-fixed-column" rowspan="2">Chọn', false)
            ->assertDontSee('class="form-check-input member-checkbox"', false)
            ->assertDontSee('Ngoài vùng');
        $this->get('/households/1')
            ->assertOk()
            ->assertSee('Chi tiết hộ 01')
            ->assertSee('Địa chỉ và cư trú')
            ->assertSee('Danh sách nhân khẩu')
            ->assertSee('household-members-table', false)
            ->assertSee('Thao tác')
            ->assertSee('Vuốt ngang bảng để xem thông tin và thao tác.')
            ->assertDontSee('class="person-row"', false);
        $this->withSession(['success' => 'Đã cập nhật thành viên.'])
            ->get('/households/1')
            ->assertOk()
            ->assertSee('app-notification-success', false)
            ->assertSee('Thao tác thành công')
            ->assertSee('Đã cập nhật thành viên.');
        $this->actingAs($user)->get('/households/2')->assertNotFound();
    }

    public function test_household_list_defaults_to_assigned_area_and_current_school_year(): void
    {
        DB::table('provinces')->insert([
            ['code' => 'P1', 'name' => 'Tỉnh một', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'P2', 'name' => 'Tỉnh hai', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('communes')->insert([
            ['code' => 'C1', 'name' => 'Xã một', 'province_code' => 'P1', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('villages')->insert([
            ['code' => 'V1', 'name' => 'Thôn được giao', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'V2', 'name' => 'Thôn khác', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $currentSchoolYear = now()->month >= 8
            ? now()->year . '-' . (now()->year + 1)
            : (now()->year - 1) . '-' . now()->year;
        $previousSchoolYear = ((int) explode('-', $currentSchoolYear)[0] - 1) . '-' . explode('-', $currentSchoolYear)[0];
        DB::table('households')->insert([
            ['household_code' => '01', 'school_year' => $currentSchoolYear, 'province_code' => 'P1', 'commune_code' => 'C1', 'village_code' => 'V1', 'created_at' => now(), 'updated_at' => now()],
            ['household_code' => '02', 'school_year' => $previousSchoolYear, 'province_code' => 'P1', 'commune_code' => 'C1', 'village_code' => 'V1', 'created_at' => now(), 'updated_at' => now()],
            ['household_code' => '03', 'school_year' => $currentSchoolYear, 'province_code' => 'P1', 'commune_code' => 'C1', 'village_code' => 'V2', 'created_at' => now(), 'updated_at' => now()],
            ...array_map(
                static fn(int $code): array => [
                    'household_code' => sprintf('%02d', $code),
                    'school_year' => $currentSchoolYear,
                    'province_code' => 'P1',
                    'commune_code' => 'C1',
                    'village_code' => 'V1',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                range(4, 18)
            ),
        ]);

        $role = Role::create(['name' => 'village-current-year-viewer', 'scope_level' => 'village', 'guard_name' => 'web']);
        $viewPermission = Permission::create([
            'name' => AccessPermission::VIEW_HOUSEHOLDS,
            'guard_name' => 'web',
        ]);
        $role->permissions()->attach($viewPermission);
        $user = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => true,
        ]);
        $user->roles()->attach($role);
        $user->villages()->sync(['V1']);

        $this->actingAs($user)
            ->get('/households')
            ->assertOk()
            ->assertSee('<td class="household-code-cell" title="01">', false)
            ->assertDontSee('<td class="household-code-cell" title="02">', false)
            ->assertDontSee('<td class="household-code-cell" title="03">', false)
            ->assertSee('id="household-page-select"', false)
            ->assertDontSee('member-sticky-household', false)
            ->assertSee('value="2"', false)
            ->assertSee('value="P1" selected', false)
            ->assertSee('data-selected-code="C1"', false)
            ->assertSee('data-selected-code="V1"', false)
            ->assertSee('value="' . $currentSchoolYear . '" selected', false)
            ->assertSee('Vuốt ngang bảng để xem thêm thông tin.')
            ->assertSee('title="Xem chi tiết hộ"', false)
            ->assertDontSee('class="household-row"', false)
            ->assertDontSee('data-url=', false);

        $this->get('/households?page=2')
            ->assertOk()
            ->assertSee('<option value="2" selected>2</option>', false)
            ->assertSee('<td class="household-code-cell" title="10">', false)
            ->assertDontSee('<td class="household-code-cell" title="01">', false);

        $this->get('/households?school_year=all')
            ->assertOk()
            ->assertSee('<td class="household-code-cell" title="01">', false)
            ->assertSee('<td class="household-code-cell" title="02">', false)
            ->assertDontSee('<td class="household-code-cell" title="03">', false)
            ->assertSee('value="all" selected', false);
    }

    public function test_multi_village_account_defaults_to_all_of_its_assigned_villages(): void
    {
        DB::table('provinces')->insert([
            'code' => 'P1',
            'name' => 'Tỉnh một',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('communes')->insert([
            'code' => 'C1',
            'name' => 'Xã một',
            'province_code' => 'P1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('villages')->insert([
            ['code' => 'V1', 'name' => 'Thôn một', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'V2', 'name' => 'Thôn hai', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'V3', 'name' => 'Thôn ngoài phạm vi', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $schoolYear = now()->month >= 8
            ? now()->year . '-' . (now()->year + 1)
            : (now()->year - 1) . '-' . now()->year;
        DB::table('households')->insert([
            ['household_code' => '11', 'school_year' => $schoolYear, 'province_code' => 'P1', 'commune_code' => 'C1', 'village_code' => 'V1', 'created_at' => now(), 'updated_at' => now()],
            ['household_code' => '12', 'school_year' => $schoolYear, 'province_code' => 'P1', 'commune_code' => 'C1', 'village_code' => 'V2', 'created_at' => now(), 'updated_at' => now()],
            ['household_code' => '13', 'school_year' => $schoolYear, 'province_code' => 'P1', 'commune_code' => 'C1', 'village_code' => 'V3', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $role = Role::create(['name' => 'multi-village-default-viewer', 'scope_level' => 'village', 'guard_name' => 'web']);
        $role->permissions()->attach(Permission::create([
            'name' => AccessPermission::VIEW_HOUSEHOLDS,
            'guard_name' => 'web',
        ]));
        $user = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => true,
        ]);
        $user->roles()->attach($role);
        $user->villages()->sync(['V1', 'V2']);

        $this->actingAs($user)
            ->get('/households')
            ->assertOk()
            ->assertSee('<td class="household-code-cell" title="11">', false)
            ->assertSee('<td class="household-code-cell" title="12">', false)
            ->assertDontSee('<td class="household-code-cell" title="13">', false)
            ->assertSee('-- Tất cả thôn được giao --');
    }

    public function test_ajax_filter_without_a_selected_village_returns_results_for_the_assigned_commune(): void
    {
        DB::table('provinces')->insert([
            'code' => 'P1',
            'name' => 'Tỉnh một',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('communes')->insert([
            'code' => 'C1',
            'name' => 'Xã một',
            'province_code' => 'P1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('villages')->insert([
            ['code' => 'V1', 'name' => 'Thôn một', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'V2', 'name' => 'Thôn hai', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('households')->insert([
            ['household_code' => '21', 'school_year' => '2026-2027', 'province_code' => 'P1', 'commune_code' => 'C1', 'village_code' => 'V1', 'created_at' => now(), 'updated_at' => now()],
            ['household_code' => '22', 'school_year' => '2026-2027', 'province_code' => 'P1', 'commune_code' => 'C1', 'village_code' => 'V2', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $role = Role::create(['name' => 'commune-filter-viewer', 'scope_level' => 'commune', 'guard_name' => 'web']);
        $role->permissions()->attach(Permission::create([
            'name' => AccessPermission::VIEW_HOUSEHOLDS,
            'guard_name' => 'web',
        ]));
        $user = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => true,
        ]);
        $user->roles()->attach($role);

        $this->actingAs($user)
            ->get('/households?school_year=2026-2027&province_code=P1&commune_code=C1&village_code=', [
                'HTTP_ACCEPT' => 'text/html',
                'HTTP_X-Requested-With' => 'XMLHttpRequest',
            ])
            ->assertOk()
            ->assertSee('if (exportButton) {', false)
            ->assertSee("exportButton?.addEventListener('click'", false)
            ->assertSee('<td class="household-code-cell" title="21">', false)
            ->assertSee('<td class="household-code-cell" title="22">', false);
    }

    public function test_household_pagination_uses_previous_next_controls_and_a_page_selector(): void
    {
        $role = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        $now = now();

        for ($page = 1; $page <= 255; $page++) {
            DB::table('households')->insert([
                'household_code' => str_pad((string) $page, 3, '0', STR_PAD_LEFT),
                'school_year' => '2026-2027',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->actingAs($user)
            ->get('/households?school_year=all&page=1')
            ->assertOk()
            ->assertSee('data-household-page-select', false)
            ->assertSee('<option value="1" selected>', false)
            ->assertSee('value="37"', false)
            ->assertSee('>37</option>', false)
            ->assertSee('data-household-page="1"', false)
            ->assertSee('<td class="household-code-cell" title="007">', false)
            ->assertDontSee('<td class="household-code-cell" title="008">', false)
            ->assertSee('aria-label="Trang sau"', false)
            ->assertSee('aria-label="Trang trước"', false);

        $this->actingAs($user)
            ->get('/households?school_year=all&page=2')
            ->assertOk()
            ->assertSee('data-household-page="2"', false)
            ->assertSee('<td class="household-code-cell" title="008">', false)
            ->assertDontSee('<td class="household-code-cell" title="007">', false);

        $this->actingAs($user)
            ->get('/households?school_year=all&page=3')
            ->assertOk()
            ->assertSee('data-household-page-select', false)
            ->assertSee('<option value="3" selected>', false)
            ->assertSee('data-household-page="3"', false)
            ->assertSee('rel="prev"', false)
            ->assertSee('rel="next"', false)
            ->assertDontSee('<span class="page-link">...</span>', false);
    }

    public function test_commune_scoped_accounts_are_not_limited_by_legacy_school_codes(): void
    {
        DB::table('provinces')->insert([
            'code' => 'P1',
            'name' => 'Tỉnh một',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('communes')->insert([
            'code' => 'C1',
            'name' => 'Xã một',
            'province_code' => 'P1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('schools')->insert([
            ['code' => 'S1', 'name' => 'Trường một', 'province_code' => 'P1', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'S2', 'name' => 'Trường hai', 'province_code' => 'P1', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('households')->insert([
            ['household_code' => '01', 'school_year' => '2026-2027', 'province_code' => 'P1', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
            ['household_code' => '02', 'school_year' => '2026-2027', 'province_code' => 'P1', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
            ['household_code' => '03', 'school_year' => '2026-2027', 'province_code' => 'P1', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('persons')->insert([
            ['household_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['household_id' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('person_educations')->insert([
            ['person_id' => 1, 'school_code' => 'S1', 'created_at' => now(), 'updated_at' => now()],
            ['person_id' => 2, 'school_code' => 'S2', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $role = Role::create(['name' => 'commune-officer', 'scope_level' => 'commune', 'guard_name' => 'web']);
        $user = User::factory()->create([
            'is_active' => true,
        ]);
        $user->forceFill([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'commune',
        ])->save();
        $user->roles()->attach($role);

        $visibleCodes = DataAccessScope::households(Household::query(), $user)
            ->orderBy('household_code')
            ->pluck('household_code')
            ->all();

        $this->assertSame(['01', '02', '03'], $visibleCodes);
    }

    public function test_village_account_can_access_only_its_assigned_villages(): void
    {
        DB::table('provinces')->insert([
            'code' => 'P1',
            'name' => 'Tỉnh một',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('communes')->insert([
            'code' => 'C1',
            'name' => 'Xã một',
            'province_code' => 'P1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('villages')->insert([
            ['code' => 'V1', 'name' => 'Thôn một', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'V2', 'name' => 'Thôn hai', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'V3', 'name' => 'Thôn ba', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
        ]);
        foreach (['V1', 'V2', 'V3'] as $index => $villageCode) {
            DB::table('households')->insert([
                'household_code' => sprintf('%02d', $index + 1),
                'school_year' => '2026-2027',
                'province_code' => 'P1',
                'commune_code' => 'C1',
                'village_code' => $villageCode,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $role = Role::create(['name' => 'multiple-village-officer', 'scope_level' => 'village', 'guard_name' => 'web']);
        $user = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => true,
        ]);
        $user->roles()->attach($role);
        $user->villages()->sync(['V1', 'V2']);

        $visibleCodes = DataAccessScope::households(Household::query(), $user)
            ->orderBy('household_code')
            ->pluck('household_code')
            ->all();

        $this->assertSame(['01', '02'], $visibleCodes);
        $this->assertTrue(DataAccessScope::canAccessHousehold($user, Household::findOrFail(1)));
        $this->assertFalse(DataAccessScope::canAccessHousehold($user, Household::findOrFail(3)));
    }

    public function test_commune_manager_can_assign_multiple_villages_to_account_in_its_scope(): void
    {
        DB::table('provinces')->insert([
            ['code' => 'P1', 'name' => 'Tỉnh một', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'P2', 'name' => 'Tỉnh hai', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('communes')->insert([
            ['code' => 'C1', 'name' => 'Xã một', 'province_code' => 'P1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'C2', 'name' => 'Xã hai', 'province_code' => 'P2', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('villages')->insert([
            ['code' => 'V1', 'name' => 'Thôn một', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'V2', 'name' => 'Thôn hai', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'V3', 'name' => 'Thôn ngoài xã', 'commune_code' => 'C2', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $managerRole = Role::create(['name' => 'commune-manager', 'scope_level' => 'commune', 'guard_name' => 'web']);
        Permission::insert([
            ['name' => AccessPermission::MANAGE_USER_ACCOUNTS, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()],
            ['name' => AccessPermission::VIEW_HOUSEHOLDS, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $accountPermission = Permission::where('name', AccessPermission::MANAGE_USER_ACCOUNTS)->firstOrFail();
        $viewPermission = Permission::where('name', AccessPermission::VIEW_HOUSEHOLDS)->firstOrFail();
        $managerRole->permissions()->sync([$accountPermission->id, $viewPermission->id]);
        $manager = User::factory()->create([
            'is_active' => true,
        ]);
        $manager->forceFill([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'commune',
        ])->save();
        $manager->roles()->attach($managerRole);
        $villageRole = Role::create([
            'name' => 'village-reader',
            'scope_level' => 'village',
            'guard_name' => 'web',
            'created_by' => $manager->id,
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ]);
        $villageRole->permissions()->attach($viewPermission);

        $this->actingAs($manager)->post('/admin/users', [
            'name' => 'Quản lý nhiều thôn',
            'username' => 'multiple-villages',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
            'role_id' => $villageRole->id,
            'scope_level' => 'village',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'village_codes' => ['V1', 'V2'],
            'is_active' => '1',
        ])->assertRedirectToRoute('admin.users.index');

        $villageUser = User::where('username', 'multiple-villages')->firstOrFail();
        $this->assertEqualsCanonicalizing(['V1', 'V2'], $villageUser->managedVillageCodes());
        $this->assertDatabaseHas('user_villages', ['user_id' => $villageUser->id, 'village_code' => 'V1']);
        $this->assertDatabaseHas('user_villages', ['user_id' => $villageUser->id, 'village_code' => 'V2']);

        $this->from('/admin/users/create')->post('/admin/users', [
            'name' => 'Ngoài xã',
            'username' => 'outside-commune',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
            'role_id' => $villageRole->id,
            'scope_level' => 'village',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'village_codes' => ['V1', 'V3'],
            'is_active' => '1',
        ])->assertSessionHasErrors('village_codes.1');
    }

    public function test_delegated_permission_is_revoked_when_assigner_loses_it(): void
    {
        $permission = Permission::create([
            'name' => AccessPermission::VIEW_HOUSEHOLDS,
            'guard_name' => 'web',
        ]);
        $managerRole = Role::create([
            'name' => 'delegating-manager',
            'scope_level' => 'commune',
            'guard_name' => 'web',
        ]);
        $managerRole->permissions()->attach($permission);
        $manager = User::factory()->create(['is_active' => true]);
        $manager->roles()->attach($managerRole);

        $delegatedRole = Role::create([
            'name' => 'delegated-viewer',
            'scope_level' => 'village',
            'guard_name' => 'web',
            'created_by' => $manager->id,
        ]);
        $delegatedRole->permissions()->attach($permission);
        $villageUser = User::factory()->create(['is_active' => true]);
        $villageUser->roles()->sync([$delegatedRole->id => ['assigned_by' => $manager->id]]);
        $assignedRoleUser = User::factory()->create(['scope_level' => 'village', 'is_active' => true]);
        $assignedRoleUser->roles()->sync([$delegatedRole->id => ['assigned_by' => $manager->id]]);

        $this->assertTrue($manager->hasPermissionTo(AccessPermission::VIEW_HOUSEHOLDS));
        $this->assertTrue($villageUser->hasPermissionTo(AccessPermission::VIEW_HOUSEHOLDS));
        $this->assertTrue($assignedRoleUser->hasPermissionTo(AccessPermission::VIEW_HOUSEHOLDS));

        $managerRole->permissions()->detach($permission);

        $this->assertFalse($manager->fresh()->hasPermissionTo(AccessPermission::VIEW_HOUSEHOLDS));
        $this->assertFalse($villageUser->fresh()->hasPermissionTo(AccessPermission::VIEW_HOUSEHOLDS));
        $this->assertFalse($assignedRoleUser->fresh()->hasPermissionTo(AccessPermission::VIEW_HOUSEHOLDS));
    }

    public function test_system_administrator_keeps_account_counts_for_user_tree_without_standalone_page(): void
    {
        DB::table('provinces')->insert([
            'code' => 'P1',
            'name' => 'Tỉnh một',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('communes')->insert([
            'code' => 'C1',
            'name' => 'Xã một',
            'province_code' => 'P1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('villages')->insert([
            ['code' => 'V1', 'name' => 'Thôn một', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'V2', 'name' => 'Thôn hai', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $adminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach($adminRole);

        $provinceRole = Role::create([
            'name' => 'Quản trị tỉnh một',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $provinceAccount = User::factory()->create([
            'name' => 'Tài khoản tỉnh một',
            'username' => 'tinhmot',
            'province_code' => 'P1',
            'scope_level' => 'province',
            'is_active' => true,
        ]);
        $provinceAccount->roles()->attach($provinceRole);

        $villageRole = Role::create([
            'name' => 'Giáo viên nhập liệu',
            'scope_level' => 'village',
            'guard_name' => 'web',
        ]);
        $villageAccount = User::factory()->create([
            'name' => 'Tài khoản thôn một',
            'username' => 'thonmot',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'village',
            'is_active' => false,
        ]);
        $villageAccount->roles()->attach($villageRole);
        $villageAccount->villages()->sync(['V1']);

        $this->actingAs($admin)
            ->get('/admin/organization-accounts')
            ->assertNotFound();

        $this->get('/api/provinces/P1/communes?include_account_counts=1')
            ->assertOk()
            ->assertJsonFragment(['code' => 'C1', 'name' => 'Xã một', 'account_count' => 1]);

        $this->get('/api/communes/C1/villages?include_account_counts=1')
            ->assertOk()
            ->assertJsonFragment(['code' => 'V1', 'name' => 'Thôn một', 'account_count' => 1])
            ->assertJsonFragment(['code' => 'V2', 'name' => 'Thôn hai', 'account_count' => 0]);
    }
}