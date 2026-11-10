<?php

namespace Tests\Feature;

use App\Jobs\ProcessHouseholdImport;
use App\Models\Household;
use App\Models\ImportLog;
use App\Models\Permission;
use App\Models\Province;
use App\Models\Role;
use App\Models\User;
use App\Services\Admin\AccessControl\RoleManagementService;
use App\Services\Admin\AccessControl\UserAccessControlService;
use App\Services\Households\HouseholdImportProcessingService;
use App\Support\AccessPermission;
use App\Support\DataAccessScope;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SeedLegacyUserPersonalInformationSeeder;
use Database\Seeders\SeedMissingUserPhonesSeeder;
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
            $table->unsignedBigInteger('role_id')->nullable();
            $table->unsignedBigInteger('role_assigned_by')->nullable();
            $table->string('name');
            $table->string('username')->nullable()->unique();
            $table->string('phone', 20)->nullable();
            $table->string('citizen_id', 12)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('email')->nullable();
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
            $table->json('permission_codes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('province_code')->nullable();
            $table->string('commune_code')->nullable();
            $table->boolean('is_system')->default(false);
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
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->primary(['role_id', 'permission_id']);
        });
        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
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
            $table->timestamp('intake_paused_at')->nullable();
            $table->unsignedBigInteger('intake_paused_by')->nullable();
            $table->string('merged_into_code')->nullable();
            $table->timestamp('merged_at')->nullable();
            $table->timestamps();
        });
        Schema::create('user_villages', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->string('village_code');
            $table->primary(['user_id', 'village_code']);
        });
        Schema::create('user_operating_areas', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('province_code', 20);
            $table->string('commune_code', 20)->nullable();
            $table->string('village_code', 20)->nullable();
            $table->unique(
                ['user_id', 'province_code', 'commune_code', 'village_code'],
                'user_operating_areas_assignment_unique',
            );
            $table->index(
                ['province_code', 'commune_code', 'village_code'],
                'user_operating_areas_location_index',
            );
        });
        Schema::create('schools', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->string('type')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('level')->nullable();
            $table->string('province_code')->nullable();
            $table->string('commune_code')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('persons', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('household_id');
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('school_years', function (Blueprint $table): void {
            $table->id();
            $table->date('start_time');
            $table->date('end_time');
            $table->boolean('status');
        });
        Schema::create('person_educations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('person_id');
            $table->unsignedBigInteger('school_year_id')->nullable();
            $table->string('school_code')->nullable();
            $table->timestamps();
        });
        Schema::create('households', function (Blueprint $table): void {
            $table->id();
            $table->string('household_code');
            $table->unsignedBigInteger('school_year_id');
            $table->string('head_first_name')->nullable();
            $table->string('head_last_name')->nullable();
            $table->string('province_code')->nullable();
            $table->string('commune_code')->nullable();
            $table->string('village_code')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        User::saved(function (User $user): void {
            $provinceCode = $user->getRawOriginal('province_code');
            if (! filled($provinceCode) || $user->operatingAreas()->exists()) {
                return;
            }

            $user->operatingAreas()->create([
                'province_code' => $provinceCode,
                'commune_code' => $user->getRawOriginal('commune_code'),
                'village_code' => null,
            ]);
        });
    }

    private function authenticateApiUser(User $user): void
    {
        $this->actingAs($user, 'web');
    }

    private function syncLegacyRoleAssignment(User $user): void
    {
        if ($user->role_id === null) {
            return;
        }

        DB::table('model_has_roles')->insertOrIgnore([
            'role_id' => $user->role_id,
            'model_type' => User::class,
            'model_id' => $user->id,
            'assigned_by' => $user->role_assigned_by,
        ]);
    }

    private function syncLegacyRolePermissions(Role $role): void
    {
        foreach ($role->permission_codes ?? [] as $permissionCode) {
            $permissionId = Permission::query()
                ->where('guard_name', $role->guard_name)
                ->where('name', $permissionCode)
                ->value('id');
            if ($permissionId !== null) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'role_id' => $role->id,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    private function refreshRoleCodesFromLegacyPermissions(Role $role): void
    {
        $role->permission_codes = DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('role_has_permissions.role_id', $role->id)
            ->where('permissions.guard_name', $role->guard_name)
            ->pluck('permissions.name')
            ->all();
        $role->save();
    }

    private function syncUserVillages(User $user, array $villageCodes): void
    {
        $user->operatingAreas()->delete();
        $user->unsetRelation('operatingAreas')->unsetRelation('villages');
        if ($user->hasPermissionTo(AccessPermission::MANAGE_VILLAGES)) {
            $area = DB::table('villages')
                ->join('communes', 'communes.code', '=', 'villages.commune_code')
                ->whereIn('villages.code', $villageCodes)
                ->first(['communes.province_code', 'villages.commune_code']);
            if ($area !== null) {
                $user->operatingAreas()->create([
                    'province_code' => $area->province_code,
                    'commune_code' => $area->commune_code,
                    'village_code' => null,
                ]);
            }

            return;
        }

        $areas = DB::table('villages')
            ->join('communes', 'communes.code', '=', 'villages.commune_code')
            ->whereIn('villages.code', $villageCodes)
            ->get(['villages.code as village_code', 'communes.province_code', 'villages.commune_code'])
            ->map(fn (object $area): array => [
                'province_code' => $area->province_code,
                'commune_code' => $area->commune_code,
                'village_code' => $area->village_code,
            ])
            ->all();

        if ($areas !== []) {
            $user->operatingAreas()->createMany($areas);
        }
    }

    public function test_api_login_is_public_but_session_requires_authentication(): void
    {
        $this->postJson('/api/v1/login', [
            'username' => 'unknown-user',
            'password' => 'incorrect-password',
        ])->assertUnprocessable();
        $this->getJson('/api/v1/session')->assertUnauthorized();
    }

    public function test_role_checks_reuse_the_loaded_roles_for_the_current_user(): void
    {
        $role = Role::create([
            'name' => 'Nhóm kiểm tra cache quyền',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        $user->unsetRelation('role');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertTrue($user->hasRole($role->name));
        $queryCountAfterFirstCheck = count(DB::getQueryLog());
        $this->assertSame(1, $queryCountAfterFirstCheck);
        $this->assertFalse($user->hasRole(Role::SYSTEM_ADMIN));
        $this->assertSame($queryCountAfterFirstCheck, count(DB::getQueryLog()));

        DB::disableQueryLog();
    }

    public function test_role_permissions_are_saved_as_permission_codes(): void
    {
        $permission = Permission::create([
            'name' => AccessPermission::VIEW_HOUSEHOLDS,
            'guard_name' => 'web',
        ]);
        $role = Role::create([
            'name' => 'Nhóm quyền JSON',
            'scope_level' => 'commune',
            'guard_name' => 'web',
        ]);

        $role->syncPermissions([$permission->id]);

        $this->assertSame([AccessPermission::VIEW_HOUSEHOLDS], $role->fresh()->permission_codes);
    }

    public function test_account_index_can_filter_by_group_role(): void
    {
        $adminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole($adminRole);

        $groupA = Role::create([
            'name' => 'Nhóm A',
            'scope_level' => 'province',
            'guard_name' => 'web',
            'created_by' => $admin->id,
        ]);
        $groupB = Role::create([
            'name' => 'Nhóm B',
            'scope_level' => 'province',
            'guard_name' => 'web',
            'created_by' => $admin->id,
        ]);
        foreach ([$groupA, $groupB] as $group) {
            $group->syncPermissions([
                Permission::firstOrCreate([
                    'name' => AccessPermission::MANAGE_SCHOOLS,
                    'guard_name' => 'web',
                ])->id,
                Permission::firstOrCreate([
                    'name' => AccessPermission::MANAGE_USER_ACCOUNTS,
                    'guard_name' => 'web',
                ])->id,
            ]);
        }

        $userA = User::factory()->create([
            'name' => 'Tài khoản thuộc nhóm A',
            'created_by' => $admin->id,
            'scope_level' => 'province',
            'province_code' => 'P1',
            'is_active' => true,
        ]);
        $userB = User::factory()->create([
            'name' => 'Tài khoản thuộc nhóm B',
            'created_by' => $admin->id,
            'scope_level' => 'province',
            'province_code' => 'P1',
            'is_active' => true,
        ]);
        $userA->assignRole($groupA);
        $userB->assignRole($groupB);

        $this->actingAs($admin)
            ->getJson(route('api.users.index', ['role_id' => $groupA->id]))
            ->assertOk()
            ->assertJsonFragment(['name' => 'Tài khoản thuộc nhóm A'])
            ->assertJsonMissing(['name' => 'Tài khoản thuộc nhóm B']);
    }

    public function test_account_index_clear_filter_button_is_disabled_until_a_filter_is_selected(): void
    {
        $adminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole($adminRole);

        $this->actingAs($admin)
            ->getJson(route('api.users.index'))
            ->assertOk()
            ->assertJsonPath('data.selected_filters.role_id', null);

        $this->actingAs($admin)
            ->getJson(route('api.users.index', ['role_id' => 1]))
            ->assertOk()
            ->assertJsonPath('data.selected_filters.role_id', '1');
    }

    public function test_system_admin_can_access_management_apis(): void
    {
        $systemAdminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $systemAdmin = User::factory()->create(['is_active' => true]);
        $systemAdmin->assignRole($systemAdminRole);

        $this->actingAs($systemAdmin)
            ->getJson(route('api.roles.index'))
            ->assertOk();
        $this->getJson(route('api.users.index'))->assertOk();
    }

    public function test_account_creation_accepts_international_phone_numbers_and_rejects_other_characters(): void
    {
        $systemAdminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole($systemAdminRole);
        $role = Role::create([
            'name' => 'Nhóm tài khoản toàn quốc',
            'scope_level' => 'global',
            'guard_name' => 'web',
            'created_by' => $admin->id,
        ]);
        $this->actingAs($admin, 'web');

        $payload = [
            'name' => 'Tài khoản kiểm tra điện thoại',
            'username' => 'phone-test1',
            'phone' => '+84912345678',
            'citizen_id' => '001234567890',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'role_id' => $role->id,
            'scope_level' => 'global',
            'is_active' => true,
        ];

        $this->postJson('/api/v1/users', $payload)
            ->assertCreated();
        $this->assertDatabaseHas('users', [
            'username' => 'phone-test1',
            'phone' => '+84912345678',
        ]);

        $payload['username'] = 'phone-test2';
        $payload['phone'] = '+84 912345678';
        $payload['citizen_id'] = '001234567891';

        $this->postJson('/api/v1/users', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'phone')
            ->assertJsonPath(
                'error.details.0.issue',
                'Số điện thoại chỉ được chứa chữ số hoặc dấu + ở đầu.',
            );
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
        $admin->assignRole($adminRole);
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
            ->deleteJson(route('api.households.persons.destroy', [$household, $headId]))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Hãy xóa các thành viên khác trước khi xóa hồ sơ chủ hộ.');
        $this->assertDatabaseHas('persons', ['id' => $headId]);

        $this->deleteJson(route('api.households.persons.destroy', [$household, $memberId]))
            ->assertOk()
            ->assertJsonPath('data.remaining_count', 1)
            ->assertJsonPath('data.is_household_head', false);
        $this->assertSoftDeleted('persons', ['id' => $memberId]);

        $this->deleteJson(route('api.households.persons.destroy', [$household, $headId]))
            ->assertOk()
            ->assertJsonPath('data.remaining_count', 0)
            ->assertJsonPath('data.is_household_head', true);
        $this->assertSoftDeleted('persons', ['id' => $headId]);

        $bulkIds = collect(['Anh', 'Chị'])->map(fn (string $relationship): int => DB::table('persons')->insertGetId([
            'household_id' => $household->id,
            'relationship_with_head' => $relationship,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->all();
        $this->deleteJson(route('api.households.persons.destroy-many'), ['person_ids' => $bulkIds])
            ->assertOk()
            ->assertJsonPath('data.deleted_ids', $bulkIds);
        foreach ($bulkIds as $personId) {
            $this->assertSoftDeleted('persons', ['id' => $personId]);
        }
    }

    public function test_active_village_cannot_be_deleted_and_inactive_village_delete_returns_json(): void
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
        $role->attachPermissions(Permission::create([
            'name' => AccessPermission::MANAGE_VILLAGES,
            'guard_name' => 'web',
        ]));
        $user = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'commune',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        $household = Household::create([
            'household_code' => 'HK-VILLAGE-DELETE',
            'school_year' => '2026-2027',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'village_code' => 'V1',
        ]);

        $this->authenticateApiUser($user);
        $this
            ->deleteJson('/api/v1/villages/V1')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('status_code', 422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.details.0.field', 'village')
            ->assertJsonPath('error.details.0.issue', 'Không thể xóa thôn/xóm đang hoạt động. Hãy chuyển sang trạng thái không hoạt động trước.');

        $this->assertDatabaseHas('villages', ['code' => 'V1']);
        $this->assertDatabaseHas('households', ['id' => $household->id, 'village_code' => 'V1']);

        DB::table('villages')->where('code', 'V1')->update(['intake_paused_at' => now()]);
        $this->deleteJson('/api/v1/villages/V1')
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'village')
            ->assertJsonPath('error.details.0.issue', 'Không thể xóa thôn/xóm vì đang có hộ điều tra thuộc địa bàn này.');

        $this->assertDatabaseHas('villages', ['code' => 'V1']);
        $this->assertDatabaseHas('households', ['id' => $household->id, 'village_code' => 'V1']);

        $household->delete();
        $this->deleteJson('/api/v1/villages/V1')
            ->assertOk()
            ->assertJsonPath('message', 'Đã xóa thôn/bản thành công!');

        $this->assertDatabaseMissing('villages', ['code' => 'V1']);
    }

    public function test_new_village_codes_follow_commune_and_sequential_number_pattern(): void
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

        $role = Role::create([
            'name' => 'village-manager',
            'scope_level' => 'commune',
            'guard_name' => 'web',
        ]);
        $role->attachPermissions(Permission::create([
            'name' => AccessPermission::MANAGE_VILLAGES,
            'guard_name' => 'web',
        ]));
        $user = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'commune',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        $this->authenticateApiUser($user);
        $this->postJson('/api/v1/villages', [
            'name' => 'Thôn Một',
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ])->assertCreated()
            ->assertJsonPath('data.village.code', 'C1_001')
            ->assertJsonPath('data.village.name', 'Thôn Một');
        $this->putJson('/api/v1/villages/C1_001', [
            'name' => 'Thôn Một đã sửa',
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ])->assertOk()
            ->assertJsonPath('data.village.code', 'C1_001')
            ->assertJsonPath('data.village.name', 'Thôn Một đã sửa');
        $this->postJson('/api/v1/villages', [
            'name' => 'Thôn Hai',
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ])->assertCreated();

        $this->assertDatabaseHas('villages', [
            'code' => 'C1_001',
            'name' => 'Thôn Một đã sửa',
            'commune_code' => 'C1',
        ]);
        $this->assertDatabaseHas('villages', [
            'code' => 'C1_002',
            'name' => 'Thôn Hai',
            'commune_code' => 'C1',
        ]);

        Schema::create('village_merge_histories', function (Blueprint $table): void {
            $table->id();
            $table->string('commune_code');
            $table->json('source_codes');
            $table->string('target_code');
        });
        DB::table('village_merge_histories')->insert([
            'commune_code' => 'C1',
            'source_codes' => json_encode(['C1_010']),
            'target_code' => 'C1_011',
        ]);
        $this->postJson('/api/v1/villages', [
            'name' => 'Thôn Ba',
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ])->assertCreated();
        $this->assertDatabaseHas('villages', [
            'code' => 'C1_012',
            'name' => 'Thôn Ba',
            'commune_code' => 'C1',
        ]);
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
            'phone' => '0912345678',
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
        $role->attachPermissions($permission);
        $user->assignRole($role);

        $this->actingAs($user)
            ->getJson(route('api.account.show'))
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Người dùng thử')
            ->assertJsonPath('data.user.phone', '0912345678');
        $session = $this->getJson(route('api.session'))
            ->assertOk()
            ->assertJsonPath('data.user.role_name', 'Điều tra viên xã')
            ->assertJsonPath('data.user.scope_area', 'Tỉnh một / Xã một')
            ->assertJsonPath('data.user.school_year', $this->currentSchoolYear());
        $this->assertContains(
            AccessPermission::VIEW_HOUSEHOLDS,
            $session->json('data.user.permissions')
        );
    }

    public function test_account_password_can_be_changed_only_after_current_password_is_verified(): void
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'Người dùng đổi mật khẩu',
            'phone' => '0987654321',
            'password' => Hash::make('old-secure-password'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::findOrFail($userId);
        $this->actingAs($user);

        $this->patchJson(route('api.account.password'), [
            'current_password' => 'incorrect-password',
            'password' => 'new-secure-pass',
            'password_confirmation' => 'new-secure-pass',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'current_password');
        $this->assertTrue(Hash::check('old-secure-password', $user->fresh()->password));

        $this->patchJson(route('api.account.password'), [
            'current_password' => 'old-secure-password',
            'password' => 'new-secure-pass',
            'password_confirmation' => 'new-secure-pass',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Đổi mật khẩu thành công.');
        $this->assertTrue(Hash::check('new-secure-pass', $user->fresh()->password));
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

        $this->getJson(route('api.session'))
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Người dùng cần đổi mật khẩu');

        $this->patchJson(route('api.account.password'), [
            'current_password' => 'Huukhang2k4#',
            'password' => 'different-secure1',
            'password_confirmation' => 'different-secure1',
        ])->assertOk();

        $this->assertTrue(Hash::check('different-secure1', $user->fresh()->password));
        $this->getJson(route('api.session'))->assertOk();
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
        $systemAdmin->assignRole($systemAdminRole);

        $provinceAdminRole = Role::create([
            'name' => 'Quản trị tỉnh',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $provinceAdminRole->syncPermissions([
            Permission::firstOrCreate([
                'name' => AccessPermission::MANAGE_SCHOOLS,
                'guard_name' => 'web',
            ])->id,
            Permission::firstOrCreate([
                'name' => AccessPermission::MANAGE_USER_ACCOUNTS,
                'guard_name' => 'web',
            ])->id,
        ]);
        $provinceAdmin = User::factory()->create([
            'name' => 'Quản trị tỉnh một',
            'scope_level' => 'province',
            'province_code' => 'P1',
            'is_active' => true,
        ]);
        $provinceAdmin->assignRole($provinceAdminRole);

        $this->actingAs($systemAdmin)
            ->getJson(route('api.users.create'))
            ->assertOk()
            ->assertJsonPath('data.available_primary_admin_scopes.province', false);
    }

    public function test_system_admin_can_reset_a_managed_account_password_without_blocking_access(): void
    {
        $adminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole($adminRole);

        $provinceRole = Role::create([
            'name' => 'Quản trị tỉnh cần reset',
            'scope_level' => 'province',
            'guard_name' => 'web',
            'created_by' => $admin->id,
        ]);
        $provinceRole->syncPermissions([
            Permission::firstOrCreate([
                'name' => AccessPermission::MANAGE_SCHOOLS,
                'guard_name' => 'web',
            ])->id,
            Permission::firstOrCreate([
                'name' => AccessPermission::MANAGE_USER_ACCOUNTS,
                'guard_name' => 'web',
            ])->id,
        ]);
        $target = User::factory()->create([
            'name' => 'Tài khoản tỉnh cần reset',
            'created_by' => $admin->id,
            'scope_level' => 'province',
            'province_code' => 'P1',
            'is_active' => true,
            'password' => 'old-secure-password',
        ]);
        $target->assignRole($provinceRole);

        $this->actingAs($admin)
            ->getJson(route('api.users.index'))
            ->assertOk()
            ->assertJsonFragment(['id' => $target->id, 'name' => 'Tài khoản tỉnh cần reset']);

        $this->postJson(route('api.users.password-resets.store', $target), [
            'password' => 'NewSecurePass2026!',
            'password_confirmation' => 'DifferentPass2026!',
        ])->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'password');
        $this->assertTrue(Hash::check('old-secure-password', $target->fresh()->password));

        $this->postJson(route('api.users.password-resets.store', $target), [
            'password' => 'NewSecurePass2026!',
            'password_confirmation' => 'NewSecurePass2026!',
        ])->assertOk()
            ->assertJsonPath('message', 'Đã đổi mật khẩu tài khoản thành công.')
            ->assertJsonMissingPath('data.temporary_password');

        $target->refresh();
        $this->assertTrue(Hash::check('NewSecurePass2026!', $target->password));

        $this->postJson(route('api.users.password-resets.store', $admin))->assertForbidden();

        $this->actingAs($target)
            ->getJson(route('api.session'))
            ->assertOk();
    }

    public function test_user_management_actions_are_hidden_for_accounts_without_manage_user_permission(): void
    {
        DB::table('provinces')->insert([
            ['code' => 'P1', 'name' => 'Tỉnh một', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'P2', 'name' => 'Tỉnh hai', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('communes')->insert([
            ['code' => 'C1', 'name' => 'Xã một', 'province_code' => 'P1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'C2', 'name' => 'Xã hai', 'province_code' => 'P2', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $manageUserRole = Role::create([
            'name' => 'Quản trị tỉnh có quyền tài khoản',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $manageUserRole->syncPermissions([
            Permission::firstOrCreate(['name' => AccessPermission::MANAGE_USER_ACCOUNTS, 'guard_name' => 'web'])->id,
            Permission::firstOrCreate(['name' => AccessPermission::VIEW_HOUSEHOLDS, 'guard_name' => 'web'])->id,
        ]);

        $actor = User::factory()->create([
            'name' => 'Quản trị tỉnh một',
            'username' => 'province-user-manager',
            'province_code' => 'P1',
            'scope_level' => 'province',
            'is_active' => true,
        ]);
        $actor->assignRole($manageUserRole);

        $managedUser = User::factory()->create([
            'name' => 'Tài khoản ngoài phạm vi',
            'username' => 'out-of-area-user',
            'province_code' => 'P2',
            'scope_level' => 'province',
            'is_active' => true,
        ]);
        $managedUser->assignRole(Role::create([
            'name' => 'Quản trị tỉnh hai',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]));

        $this->actingAs($actor)
            ->get(route('api.users.index'))
            ->assertOk()
            ->assertDontSee(route('api.users.password-resets.store', $managedUser), false)
            ->assertDontSee(route('api.users.status.update', $managedUser), false)
            ->assertDontSee('fa-lock')
            ->assertDontSee('fa-key');
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
        $provinceRole->syncPermissions([
            $permissions[AccessPermission::MANAGE_USER_ACCOUNTS]->id,
            $permissions[AccessPermission::MANAGE_ACCESS_CONTROL]->id,
            $permissions[AccessPermission::MANAGE_SCHOOLS]->id,
        ]);

        $provinceAdminId = DB::table('users')->insertGetId([
            'name' => 'Quản trị tỉnh một',
            'username' => 'province-admin-one',
            'phone' => '0900000001',
            'password' => Hash::make('secure-password'),
            'is_active' => true,
            'province_code' => 'P1',
            'scope_level' => 'province',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $provinceAdmin = User::findOrFail($provinceAdminId);
        $provinceAdmin->assignRole($provinceRole);
        $this->assertContains(AccessPermission::MANAGE_SCHOOLS, $provinceAdmin->permissionNames());
        $this->actingAs($provinceAdmin)
            ->getJson(route('api.users.index'))
            ->assertOk()
            ->assertJsonPath('data.actor_scope_level', 'province');

        $this->actingAs($provinceAdmin)->postJson(route('api.roles.store'), [
            'name' => 'Nhóm tỉnh hạn chế',
            'scope_level' => 'province',
            'permissions' => [$permissions[AccessPermission::MANAGE_SCHOOLS]->id],
        ])->assertCreated();

        $limitedProvinceRole = Role::where('name', 'Nhóm tỉnh hạn chế')->firstOrFail();
        $this->assertSame($provinceAdmin->id, $limitedProvinceRole->created_by);
        $this->assertSame(
            [AccessPermission::MANAGE_SCHOOLS],
            $limitedProvinceRole->permissions->pluck('name')->all()
        );

        $this->postJson(route('api.roles.store'), [
            'name' => 'Nhóm tỉnh sao chép toàn quyền',
            'scope_level' => 'province',
            'permissions' => [
                $permissions[AccessPermission::MANAGE_USER_ACCOUNTS]->id,
                $permissions[AccessPermission::MANAGE_ACCESS_CONTROL]->id,
                $permissions[AccessPermission::MANAGE_SCHOOLS]->id,
            ],
        ])->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'permissions');

        $this->getJson(route('api.users.create'))
            ->assertOk()
            ->assertJsonFragment(['name' => 'Nhóm tỉnh hạn chế']);

        $this->postJson(route('api.users.store'), [
            'name' => 'Tài khoản tỉnh hạn chế',
            'username' => 'limitprov1',
            'citizen_id' => '001111111115',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role_id' => $limitedProvinceRole->id,
            'scope_level' => 'province',
            'province_code' => 'P1',
            'is_active' => '1',
        ])->assertCreated();

        $limitedProvinceUser = User::where('username', 'limitprov1')->firstOrFail();
        $this->assertSame('P1', $limitedProvinceUser->province_code);
        $this->assertTrue($limitedProvinceUser->can('manage-schools'));
        $this->assertFalse($limitedProvinceUser->can('manage-user-accounts'));
        $this->assertFalse($limitedProvinceUser->can('manage-access-control'));

        $this->actingAs($provinceAdmin)->postJson(route('api.roles.store'), [
            'name' => 'Nhóm quản trị xã',
            'scope_level' => 'commune',
            'permissions' => [
                $permissions[AccessPermission::MANAGE_USER_ACCOUNTS]->id,
                $permissions[AccessPermission::MANAGE_ACCESS_CONTROL]->id,
                $permissions[AccessPermission::VIEW_HOUSEHOLDS]->id,
            ],
        ])->assertCreated();

        $communeRole = Role::where('name', 'Nhóm quản trị xã')->firstOrFail();
        $this->assertSame($provinceAdmin->id, $communeRole->created_by);

        $this->getJson(route('api.users.create'))
            ->assertOk()
            ->assertJsonFragment(['name' => 'Nhóm quản trị xã']);

        $this->postJson(route('api.users.store'), [
            'name' => 'Quản trị xã một',
            'username' => 'commune1',
            'citizen_id' => '001111111116',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role_id' => $communeRole->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => '1',
        ])->assertCreated();

        $communeAdmin = User::where('username', 'commune1')->firstOrFail();
        $this->assertSame('C1', $communeAdmin->commune_code);
        $this->assertSame($provinceAdmin->id, $communeAdmin->created_by);
        $this->assertTrue($communeAdmin->can('manage-access-control'));
        $this->assertTrue($communeAdmin->can('manage-user-accounts'));

        $this->actingAs($communeAdmin)
            ->getJson('/api/v1/users/create')
            ->assertOk()
            ->assertJsonPath('data.account_levels', ['commune'])
            ->assertJsonMissing(['name' => 'Nhóm quản trị xã']);

        $this->getJson('/api/v1/roles')
            ->assertOk()
            ->assertJsonMissing(['name' => 'Nhóm quản trị xã']);

        $this->actingAs($communeAdmin)->patchJson(route('api.account.password'), [
            'current_password' => 'SecurePass123!',
            'password' => 'CommuneNew123!',
            'password_confirmation' => 'CommuneNew123!',
        ])->assertOk();

        $this->actingAs($communeAdmin)
            ->getJson(route('api.users.index'))
            ->assertOk()
            ->assertJsonPath('data.actor_scope_level', 'commune');

        $this->actingAs($communeAdmin)->postJson(route('api.roles.store'), [
            'name' => 'Nhóm xã vượt quyền',
            'scope_level' => 'commune',
            'permissions' => [
                $permissions[AccessPermission::MANAGE_VILLAGES]->id,
            ],
        ])->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'permissions');

        $this->actingAs($communeAdmin)
            ->getJson(route('api.roles.create'))
            ->assertOk()
            ->assertJsonPath('data.scope_levels', ['commune']);

        $this->actingAs($communeAdmin)->postJson(route('api.roles.store'), [
            'name' => 'Nhóm xã hạn chế',
            'scope_level' => 'commune',
            'permissions' => [$permissions[AccessPermission::VIEW_HOUSEHOLDS]->id],
        ])->assertCreated();

        $limitedCommuneRole = Role::where('name', 'Nhóm xã hạn chế')->firstOrFail();
        $this->assertSame($communeAdmin->id, $limitedCommuneRole->created_by);
        $this->getJson('/api/v1/users/create')
            ->assertOk()
            ->assertJsonFragment(['id' => $limitedCommuneRole->id, 'name' => 'Nhóm xã hạn chế'])
            ->assertJsonMissing(['name' => 'Nhóm quản trị xã']);

        $this->postJson(route('api.users.store'), [
            'name' => 'Tài khoản xã hạn chế',
            'username' => 'limitcommune',
            'citizen_id' => '001111111118',
            'citizen_id' => '001111111117',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role_id' => $limitedCommuneRole->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => '1',
        ])->assertCreated();

        $limitedCommuneUser = User::where('username', 'limitcommune')->firstOrFail();
        $this->assertSame('C1', $limitedCommuneUser->commune_code);
        $this->assertTrue($limitedCommuneUser->can('view-households'));
        $this->assertFalse($limitedCommuneUser->can('manage-user-accounts'));
        $this->assertFalse($limitedCommuneUser->can('manage-access-control'));

        $this->actingAs($communeAdmin)->postJson(route('api.roles.store'), [
            'name' => 'Nhóm nhập liệu thôn',
            'scope_level' => 'village',
            'permissions' => [$permissions[AccessPermission::VIEW_HOUSEHOLDS]->id],
        ])->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'scope_level');

        $this->getJson(route('api.users.create'))
            ->assertOk()
            ->assertJsonMissing(['name' => 'Nhóm nhập liệu thôn']);

        $this->actingAs($communeAdmin)->postJson(route('api.users.store'), [
            'name' => 'Cán bộ thôn một',
            'username' => 'village1',
            'citizen_id' => '001111111119',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role_id' => $limitedCommuneRole->id,
            'scope_level' => 'village',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'village_codes' => ['V1'],
            'is_active' => '1',
        ])->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'role_id');

        $this->assertDatabaseMissing('users', ['username' => 'village1']);
    }

    public function test_inactive_accounts_cannot_sign_in_and_active_accounts_can(): void
    {
        $active = User::factory()->create([
            'username' => 'active-account',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->postJson('/api/v1/login', ['username' => $active->username, 'password' => 'password'])
            ->assertOk();
        $this->assertAuthenticatedAs($active);

        $this->postJson('/api/v1/logout')->assertOk();

        $inactive = User::factory()->create([
            'username' => 'inactive-account',
            'password' => Hash::make('password'),
        ]);
        $inactive->forceFill(['is_active' => false])->save();
        $this->postJson('/api/v1/login', ['username' => $inactive->username, 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'username');
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
        $managerRole->attachPermissions([
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
        $manager->assignRole($managerRole);
        $this->actingAs($manager);

        $this->getJson(route('api.users.create'))
            ->assertOk()
            ->assertJsonPath('data.account_levels', ['province', 'commune']);
        $this->getJson(route('api.roles.index'))->assertOk();
        $this->getJson(route('api.roles.create'))
            ->assertOk()
            ->assertJsonPath('data.scope_levels', ['province', 'commune']);
        $this->postJson(route('api.roles.store'), [
            'name' => 'province-limited-viewer',
            'scope_level' => 'province',
            'permissions' => [$viewPermission->id],
        ])->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'permissions');

        $this->postJson(route('api.roles.store'), [
            'name' => 'province-role-above-manager',
            'scope_level' => 'province',
            'permissions' => [Permission::create([
                'name' => AccessPermission::CREATE_HOUSEHOLDS,
                'guard_name' => 'web',
            ])->id],
        ])->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'permissions');

        $this->postJson(route('api.roles.store'), [
            'name' => 'commune-admin-group',
            'description' => 'Nhóm quản lý cấp xã trong tỉnh',
            'scope_level' => 'commune',
            'permissions' => [
                $managerPermission->id,
                $manageGroupsPermission->id,
                $viewPermission->id,
            ],
        ])->assertCreated();
        $localRole = Role::where('name', 'commune-admin-group')->firstOrFail();
        $this->assertSame($manager->id, $localRole->created_by);

        $this->postJson(route('api.roles.store'), [
            'name' => 'village-scope-not-allowed',
            'scope_level' => 'village',
            'permissions' => [$viewPermission->id],
        ])->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'scope_level');

        $this->postJson(route('api.roles.store'), [
            'name' => 'too-powerful-group',
            'scope_level' => 'commune',
            'permissions' => [$managerPermission->id, $viewPermission->id, Permission::create([
                'name' => AccessPermission::CREATE_SCHOOLS,
                'guard_name' => 'web',
            ])->id],
        ])->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'permissions');

        $this->postJson(route('api.roles.store'), [
            'name' => 'commune-data-entry',
            'scope_level' => 'commune',
            'permissions' => [$viewPermission->id],
        ])->assertCreated();

        $this->postJson(route('api.users.store'), [
            'name' => 'Tài khoản tỉnh vượt cấp',
            'username' => 'provhigh1',
            'citizen_id' => '001111111101',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role_id' => $localRole->id,
            'scope_level' => 'province',
            'province_code' => 'P1',
            'is_active' => '1',
        ])->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'role_id');

        $this->postJson(route('api.users.store'), [
            'name' => 'Cán bộ xã',
            'username' => 'commuser1',
            'citizen_id' => '001111111102',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role_id' => $localRole->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => '1',
        ])->assertCreated();

        $this->postJson(route('api.users.store'), [
            'name' => 'Tài khoản xã cùng cấp thứ hai',
            'username' => 'commuser2',
            'citizen_id' => '001111111103',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role_id' => $localRole->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => '1',
        ])->assertCreated();
        $this->assertDatabaseHas('users', [
            'username' => 'commuser2',
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ]);

        $this->postJson(route('api.users.store'), [
            'name' => 'Cán bộ xã khác',
            'username' => 'commother1',
            'citizen_id' => '001111111104',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role_id' => $localRole->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C3',
            'is_active' => '1',
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['username' => 'commuser1', 'province_code' => 'P1', 'commune_code' => 'C1']);
        $peer = User::where('username', 'commuser1')->firstOrFail();
        $this->assertTrue($peer->hasPermissionTo(AccessPermission::VIEW_HOUSEHOLDS));
        $this->assertDatabaseHas('users', [
            'id' => $peer->id,
            'role_id' => $localRole->id,
            'role_assigned_by' => $manager->id,
        ]);

        $this->postJson(route('api.users.store'), [
            'name' => 'Cán bộ thôn không thuộc cấp được giao',
            'username' => 'villdeep1',
            'citizen_id' => '001111111105',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role_id' => Role::create(['name' => 'village-data-entry', 'scope_level' => 'village', 'guard_name' => 'web'])->id,
            'scope_level' => 'village',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'village_codes' => ['V1'],
            'is_active' => '1',
        ])->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'role_id');

        $this->postJson(route('api.users.store'), [
            'name' => 'Ngoài tỉnh',
            'username' => 'outside1',
            'citizen_id' => '001111111106',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role_id' => $localRole->id,
            'scope_level' => 'commune',
            'province_code' => 'P2',
            'commune_code' => 'C2',
            'is_active' => '1',
        ])->assertForbidden();

        $managerRole->detachPermissions([
            $viewPermission->id,
            $manageGroupsPermission->id,
            $managerPermission->id,
        ]);
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
        $user->assignRole($role);
        $inputRole = Role::create([
            'name' => 'Phòng nhập liệu',
            'description' => 'Xem và nhập phiếu điều tra.',
            'scope_level' => 'province',
            'guard_name' => 'web',
            'created_by' => $user->id,
        ]);
        $inputRole->syncPermissions([
            Permission::firstOrCreate([
                'name' => AccessPermission::MANAGE_SCHOOLS,
                'guard_name' => 'web',
            ])->id,
            Permission::firstOrCreate([
                'name' => AccessPermission::MANAGE_USER_ACCOUNTS,
                'guard_name' => 'web',
            ])->id,
        ]);
        $rolesPage = $this->actingAs($user)
            ->getJson(route('api.roles.index'))
            ->assertOk()
            ->assertJsonFragment(['name' => 'Phòng nhập liệu']);
        $this->get('/admin/permissions')->assertNotFound();
        $this->getJson(route('api.roles.create'))
            ->assertOk()
            ->assertJsonPath('data.default_scope_level', 'global');
        $this->getJson(route('api.users.create'))
            ->assertOk()
            ->assertJsonPath('data.actor_scope_level', 'global')
            ->assertJsonFragment(['name' => 'Phòng nhập liệu']);

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
        $roleForm = $this->getJson(route('api.roles.create'));
        $roleForm
            ->assertOk()
            ->assertJsonFragment(['name' => AccessPermission::CREATE_HOUSEHOLDS])
            ->assertJsonFragment(['name' => AccessPermission::MANAGE_SCHOOLS]);

        $this->postJson(route('api.roles.store'), [
            'name' => 'province-household-admin',
            'scope_level' => 'province',
            'permission_groups' => ['households'],
        ])->assertUnprocessable();
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
        $legacyProvinceRole->attachPermissions($viewPermission);
        $scopedUser->assignRole($legacyProvinceRole);
        $this->assertFalse(Gate::forUser($scopedUser)->allows(AccessPermission::VIEW_HOUSEHOLDS));

        $updatePermission = Permission::create([
            'name' => AccessPermission::UPDATE_HOUSEHOLDS,
            'guard_name' => 'web',
        ]);
        $this->postJson(route('api.roles.store'), [
            'name' => 'province-editor',
            'scope_level' => 'province',
            'permissions' => [$updatePermission->id],
        ])->assertUnprocessable();

        $legacyRole = Role::create([
            'name' => 'legacy-province-editor',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $legacyRole->attachPermissions($updatePermission);
        $scopedUser->assignRole($legacyRole);
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
        $admin->assignRole($adminRole);

        $group = Role::create([
            'name' => 'Nhóm quản trị tỉnh',
            'scope_level' => 'province',
            'guard_name' => 'web',
            'created_by' => $admin->id,
        ]);
        $group->syncPermissions([
            Permission::firstOrCreate([
                'name' => AccessPermission::MANAGE_SCHOOLS,
                'guard_name' => 'web',
            ])->id,
            Permission::firstOrCreate([
                'name' => AccessPermission::MANAGE_USER_ACCOUNTS,
                'guard_name' => 'web',
            ])->id,
        ]);
        $activeMember = User::factory()->create([
            'name' => 'Thành viên đang hoạt động',
            'username' => 'active-member',
            'scope_level' => 'province',
            'created_by' => $admin->id,
            'province_code' => 'P1',
            'is_active' => true,
        ]);
        $inactiveMember = User::factory()->create([
            'name' => 'Thành viên đã khóa',
            'username' => 'inactive-member',
            'scope_level' => 'province',
            'created_by' => $admin->id,
            'province_code' => 'P1',
            'is_active' => false,
        ]);
        $notMember = User::factory()->create([
            'name' => 'Không thuộc nhóm',
            'username' => 'not-member',
            'is_active' => true,
        ]);
        $activeMember->assignRole($group);
        $inactiveMember->assignRole($group);

        $this->actingAs($admin)->getJson(route('api.roles.index'))
            ->assertOk()
            ->assertJsonFragment(['id' => $group->id, 'users_count' => 2]);

        $this->getJson(route('api.roles.users.index', $group))
            ->assertOk()
            ->assertJsonFragment(['username' => 'active-member', 'is_active' => true])
            ->assertJsonFragment(['username' => 'inactive-member', 'is_active' => false])
            ->assertJsonMissing(['username' => 'not-member']);
    }

    public function test_local_role_member_counts_include_group_members_within_the_managers_area(): void
    {
        $manageGroups = Permission::create([
            'name' => AccessPermission::MANAGE_ACCESS_CONTROL,
            'guard_name' => 'web',
        ]);
        $managerRole = Role::create([
            'name' => 'Quản lý nhóm tỉnh',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $managerRole->attachPermissions($manageGroups);
        $manager = User::factory()->create([
            'scope_level' => 'province',
            'province_code' => 'P1',
            'is_active' => true,
        ]);
        $manager->assignRole($managerRole);

        $group = Role::create([
            'name' => 'Nhóm quản trị xã do tỉnh tạo',
            'scope_level' => 'commune',
            'guard_name' => 'web',
            'created_by' => $manager->id,
            'province_code' => 'P1',
        ]);
        $createdByManager = User::factory()->create([
            'name' => 'Tài khoản xã do tỉnh tạo',
            'username' => 'province-created-commune',
            'created_by' => $manager->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => true,
        ]);
        $createdByAnotherManager = User::factory()->create([
            'name' => 'Tài khoản xã do quản trị khác tạo',
            'username' => 'other-manager-created-commune',
            'created_by' => User::factory()->create()->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C2',
            'is_active' => true,
        ]);
        $outsideProvince = User::factory()->create([
            'name' => 'Tài khoản ngoài tỉnh',
            'username' => 'outside-province-commune',
            'scope_level' => 'commune',
            'province_code' => 'P2',
            'commune_code' => 'C3',
            'is_active' => true,
        ]);
        $createdByManager->assignRole($group);
        $createdByAnotherManager->assignRole($group);
        $outsideProvince->assignRole($group);

        $this->actingAs($manager)
            ->getJson(route('api.roles.index'))
            ->assertOk()
            ->assertJsonFragment(['id' => $group->id, 'users_count' => 1]);

        $this->getJson(route('api.roles.users.index', $group))
            ->assertOk()
            ->assertJsonFragment(['username' => 'province-created-commune'])
            ->assertJsonMissing(['username' => 'other-manager-created-commune'])
            ->assertJsonMissing(['username' => 'outside-province-commune']);
    }

    public function test_role_index_renders_expandable_extra_permissions(): void
    {
        $adminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole($adminRole);

        $group = Role::create([
            'name' => 'Nhóm nhiều quyền',
            'scope_level' => 'province',
            'guard_name' => 'web',
            'created_by' => $admin->id,
        ]);
        $permissions = collect(range(1, 4))->map(fn (int $index) => Permission::create([
            'name' => "test-permission-{$index}",
            'description' => "Quyền kiểm thử {$index}",
            'guard_name' => 'web',
        ]));
        $group->attachPermissions($permissions->pluck('id')->all());

        $response = $this->actingAs($admin)
            ->getJson(route('api.roles.index'))
            ->assertOk();
        $listedRole = collect($response->json('data'))->firstWhere('id', $group->id);
        $this->assertSame(
            $permissions->pluck('name')->all(),
            $listedRole['permissions']
        );
    }

    public function test_role_index_displays_permissions_for_commune_roles(): void
    {
        $manageAccessPermission = Permission::create([
            'name' => AccessPermission::MANAGE_ACCESS_CONTROL,
            'description' => 'Quản lý nhóm người dùng',
            'guard_name' => 'web',
        ]);
        $managerRole = Role::create([
            'name' => 'Quản trị xã kiểm thử',
            'scope_level' => 'commune',
            'guard_name' => 'web',
        ]);
        $managerRole->attachPermissions($manageAccessPermission);
        $communeManager = User::factory()->create([
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => true,
        ]);
        $communeManager->assignRole($managerRole);

        $villageRole = Role::create([
            'name' => 'Nhóm cán bộ xã',
            'scope_level' => 'commune',
            'guard_name' => 'web',
            'created_by' => $communeManager->id,
            'created_by' => $communeManager->id,
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ]);
        $viewHouseholdsPermission = Permission::create([
            'name' => AccessPermission::VIEW_HOUSEHOLDS,
            'description' => 'Xem phiếu kiểm thử cấp xã',
            'guard_name' => 'web',
        ]);
        $villageRole->attachPermissions($viewHouseholdsPermission);

        $this->actingAs($communeManager)
            ->getJson(route('api.roles.index'))
            ->assertOk()
            ->assertJsonFragment([
                'name' => 'Nhóm cán bộ xã',
                'permissions' => [AccessPermission::VIEW_HOUSEHOLDS],
            ]);
    }

    public function test_commune_manager_only_sees_roles_they_created(): void
    {
        $managerRole = Role::create([
            'name' => 'Quản trị xã kiểm thử',
            'scope_level' => 'commune',
            'guard_name' => 'web',
        ]);
        $managerRole->attachPermissions(Permission::create([
            'name' => AccessPermission::MANAGE_ACCESS_CONTROL,
            'guard_name' => 'web',
        ]));
        $managerRole->attachPermissions(Permission::create([
            'name' => AccessPermission::MANAGE_USER_ACCOUNTS,
            'guard_name' => 'web',
        ]));
        $communeManager = User::factory()->create([
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => true,
        ]);
        $communeManager->assignRole($managerRole);

        $ownRole = Role::create([
            'name' => 'Nhóm cán bộ xã tự tạo',
            'scope_level' => 'commune',
            'guard_name' => 'web',
            'created_by' => $communeManager->id,
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ]);
        $viewPermission = Permission::create([
            'name' => AccessPermission::VIEW_HOUSEHOLDS,
            'guard_name' => 'web',
        ]);
        $managerRole->attachPermissions($viewPermission);
        $ownRole->attachPermissions($viewPermission);

        $otherOwnerRole = Role::create([
            'name' => 'Giao viên nhập liệu',
            'scope_level' => 'commune',
            'guard_name' => 'web',
            'created_by' => User::factory()->create()->id,
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ]);
        $otherOwnerRole->attachPermissions($viewPermission);
        $roleAssignedToManager = Role::create([
            'name' => 'Nhóm được gán trực tiếp cho quản trị xã',
            'scope_level' => 'commune',
            'guard_name' => 'web',
            'created_by' => User::factory()->create()->id,
            'province_code' => 'P1',
            'commune_code' => 'C2',
        ]);
        $roleAssignedToManager->attachPermissions($viewPermission);
        $assignedUser = User::factory()->create([
            'created_by' => $communeManager->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ]);
        $assignedUser->assignRole($ownRole);
        $assignedOtherRoleUser = User::factory()->create([
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ]);
        $assignedOtherRoleUser->assignRole($otherOwnerRole);
        $outsideAreaRole = Role::create([
            'name' => 'Nhóm xã khác',
            'scope_level' => 'commune',
            'guard_name' => 'web',
            'created_by' => User::factory()->create()->id,
            'province_code' => 'P1',
            'commune_code' => 'C2',
        ]);
        $assignedOutsideAreaUser = User::factory()->create([
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C2',
        ]);
        $assignedOutsideAreaUser->assignRole($outsideAreaRole);

        $roles = (new RoleManagementService)->rolesForIndex($communeManager);
        $visibleOwnRole = $roles->getCollection()->firstWhere('id', $ownRole->id);
        $visibleOtherRole = $roles->getCollection()->firstWhere('id', $otherOwnerRole->id);

        self::assertNotNull($visibleOwnRole);
        self::assertSame(1, $visibleOwnRole->users_count);
        self::assertNull($visibleOtherRole);
        self::assertTrue((new RoleManagementService)->canManageRole($communeManager, $visibleOwnRole));
        self::assertFalse((new RoleManagementService)->canViewRole($communeManager, $otherOwnerRole));
        self::assertFalse((new RoleManagementService)->canManageRole($communeManager, $otherOwnerRole));
        self::assertFalse($roles->getCollection()->contains('id', $roleAssignedToManager->id));
        self::assertFalse($roles->getCollection()->contains('id', $outsideAreaRole->id));

        $this->actingAs($communeManager, 'web')
            ->getJson("/api/v1/roles/{$ownRole->id}/edit")
            ->assertOk();
        $this->getJson("/api/v1/roles/{$ownRole->id}/users")
            ->assertOk()
            ->assertJsonPath('data.0.username', $assignedUser->username);
        $this->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonFragment(['username' => $assignedUser->username, 'role_name' => $ownRole->name])
            ->assertJsonMissing(['username' => $assignedOtherRoleUser->username]);
        $this->getJson("/api/v1/roles/{$otherOwnerRole->id}/edit")
            ->assertForbidden();
        $this->putJson("/api/v1/roles/{$ownRole->id}", [
            'name' => 'Nhóm cán bộ xã đã sửa',
            'description' => 'Nhóm quyền do quản trị xã tạo.',
            'scope_level' => 'commune',
            'permissions' => [$viewPermission->id],
        ])->assertOk();
        $this->assertDatabaseHas('roles', ['id' => $ownRole->id, 'name' => 'Nhóm cán bộ xã đã sửa']);
        $assignedUser->removeRole($ownRole);
        $this->deleteJson("/api/v1/roles/{$ownRole->id}")
            ->assertOk();
        $this->assertDatabaseMissing('roles', ['id' => $ownRole->id]);
    }

    public function test_account_and_group_manager_only_manages_records_they_created_in_their_area(): void
    {
        $permissions = collect([
            AccessPermission::MANAGE_USER_ACCOUNTS,
            AccessPermission::MANAGE_ACCESS_CONTROL,
            AccessPermission::VIEW_USERS,
            AccessPermission::VIEW_HOUSEHOLDS,
            AccessPermission::UPDATE_USERS,
            AccessPermission::DELETE_USERS,
        ])->mapWithKeys(fn (string $name): array => [
            $name => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']),
        ]);
        $manager = User::factory()->create([
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => true,
        ]);
        $managerRole = Role::create([
            'name' => 'Quản lý tài khoản và nhóm người dùng',
            'scope_level' => 'commune',
            'guard_name' => 'web',
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ]);
        $managerRole->syncPermissions($permissions->pluck('id')->all());
        $manager->assignRole($managerRole);

        $ownRole = Role::create([
            'name' => 'Nhóm cán bộ do tài khoản hiện tại tạo',
            'scope_level' => 'commune',
            'guard_name' => 'web',
            'created_by' => $manager->id,
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ]);
        $ownRole->attachPermissions($permissions[AccessPermission::VIEW_HOUSEHOLDS]);
        $sharedRole = Role::create([
            'name' => 'Nhóm cán bộ do quản trị khác tạo',
            'scope_level' => 'commune',
            'guard_name' => 'web',
            'created_by' => User::factory()->create()->id,
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ]);
        $sharedRole->attachPermissions($permissions[AccessPermission::VIEW_HOUSEHOLDS]);
        $target = User::factory()->create([
            'created_by' => $manager->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => true,
        ]);
        $target->assignRole($ownRole);
        $foreignTarget = User::factory()->create([
            'created_by' => User::factory()->create()->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ]);
        $foreignTarget->assignRole($sharedRole);

        $ownAccountWithForeignRole = User::factory()->create([
            'created_by' => $manager->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ]);
        $ownAccountWithForeignRole->assignRole($sharedRole);

        $outsideTarget = User::factory()->create([
            'created_by' => $manager->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C2',
            'is_active' => false,
        ]);
        $outsideTarget->assignRole($ownRole);

        $roleService = new RoleManagementService;
        $visibleRole = $roleService->rolesForIndex($manager)->getCollection()->firstWhere('id', $ownRole->id);
        self::assertNotNull($visibleRole);
        self::assertFalse($roleService->rolesForIndex($manager)->getCollection()->contains('id', $sharedRole->id));
        self::assertTrue($roleService->canManageRole($manager, $visibleRole));
        self::assertFalse($roleService->canViewRole($manager, $sharedRole));

        $userService = new UserAccessControlService;
        self::assertTrue($userService->canManageUser($manager, $target));
        self::assertTrue($userService->canResetUserPassword($manager, $target));
        self::assertTrue($userService->canChangeUserStatus($manager, $target, false));
        self::assertFalse($userService->canManageUser($manager, $foreignTarget));
        self::assertFalse($userService->canViewUser($manager, $foreignTarget));
        self::assertFalse($userService->canManageUser($manager, $outsideTarget));

        $this->actingAs($manager, 'web')
            ->getJson("/api/v1/roles/{$ownRole->id}/edit")
            ->assertOk();
        $this->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonFragment(['username' => $target->username, 'role_name' => $ownRole->name])
            ->assertJsonMissing(['username' => $ownAccountWithForeignRole->username]);
        $this->getJson("/api/v1/users?role_id={$sharedRole->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->getJson("/api/v1/roles/{$sharedRole->id}/edit")
            ->assertForbidden();
        $this->getJson("/api/v1/users/{$target->id}/edit")
            ->assertOk();
        $this->putJson("/api/v1/roles/{$ownRole->id}", [
            'name' => 'Nhóm cán bộ đã cập nhật',
            'description' => 'Được quản lý bởi người có đủ quyền.',
            'scope_level' => 'commune',
            'permissions' => [$permissions[AccessPermission::VIEW_HOUSEHOLDS]->id],
        ])->assertOk();
        $this->assertDatabaseHas('roles', ['id' => $ownRole->id, 'name' => 'Nhóm cán bộ đã cập nhật']);

        $this->postJson("/api/v1/users/{$target->id}/password-resets")
            ->assertUnprocessable();
        $this->postJson("/api/v1/users/{$target->id}/password-resets", [
            'password' => 'NewSecurePass2026!',
            'password_confirmation' => 'NewSecurePass2026!',
        ])->assertOk();
        $this->patchJson("/api/v1/users/{$target->id}/status", ['is_active' => false])
            ->assertOk();
        self::assertFalse($target->fresh()->is_active);
        self::assertFalse($outsideTarget->fresh()->is_active);
        $this->deleteJson("/api/v1/roles/{$sharedRole->id}")
            ->assertForbidden();
        $target->removeRole($ownRole);
        $this->deleteJson("/api/v1/roles/{$ownRole->id}")
            ->assertUnprocessable();
        $this->assertDatabaseHas('roles', ['id' => $ownRole->id]);
        $outsideTarget->removeRole($ownRole);
        $this->deleteJson("/api/v1/roles/{$ownRole->id}")
            ->assertOk();
        $this->assertDatabaseMissing('roles', ['id' => $ownRole->id]);
    }

    public function test_system_administrator_only_sees_accounts_and_groups_it_created(): void
    {
        $adminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create([
            'scope_level' => 'global',
            'is_active' => true,
        ]);
        $admin->assignRole($adminRole);

        $permissions = collect([
            AccessPermission::MANAGE_SCHOOLS,
            AccessPermission::MANAGE_USER_ACCOUNTS,
        ])->mapWithKeys(fn (string $name): array => [
            $name => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']),
        ]);
        $ownedRole = Role::create([
            'name' => 'Nhóm quản trị do hệ thống tạo',
            'scope_level' => 'province',
            'guard_name' => 'web',
            'created_by' => $admin->id,
        ]);
        $ownedRole->syncPermissions($permissions->pluck('id')->all());
        $otherOwner = User::factory()->create();
        $otherRole = Role::create([
            'name' => 'Nhóm thuộc tài khoản khác',
            'scope_level' => 'province',
            'guard_name' => 'web',
            'created_by' => $otherOwner->id,
        ]);

        $ownedAccount = User::factory()->create([
            'created_by' => $admin->id,
            'scope_level' => 'province',
            'province_code' => 'P1',
        ]);
        $ownedAccount->assignRole($ownedRole, $admin->id);
        $otherAccount = User::factory()->create([
            'created_by' => $otherOwner->id,
            'scope_level' => 'province',
            'province_code' => 'P2',
        ]);
        $otherAccount->assignRole($ownedRole, $admin->id);

        $accounts = (new UserAccessControlService)->indexData(
            $admin,
            [],
            Province::query()->get(),
            10,
            false,
        );
        $roles = (new RoleManagementService)->rolesForIndex($admin);

        self::assertSame([$ownedAccount->id], $accounts['allUsers']->getCollection()->modelKeys());
        self::assertSame(1, $accounts['totalUserCount']);
        self::assertTrue($roles->getCollection()->contains('id', $ownedRole->id));
        self::assertFalse($roles->getCollection()->contains('id', $otherRole->id));
        self::assertFalse((new UserAccessControlService)->canViewUser($admin, $otherAccount));
        self::assertFalse((new RoleManagementService)->canViewRole($admin, $otherRole));
        $this->actingAs($admin, 'web')
            ->getJson("/api/v1/users/{$ownedAccount->id}")
            ->assertOk()
            ->assertJsonMissing(['name' => $otherRole->name]);
    }

    public function test_province_manager_sees_only_roles_they_created(): void
    {
        $manager = User::factory()->create([
            'scope_level' => 'province',
            'province_code' => 'P1',
            'is_active' => true,
        ]);
        $ownRole = Role::create([
            'name' => 'Nhóm do quản trị tỉnh tạo',
            'scope_level' => 'province',
            'guard_name' => 'web',
            'created_by' => $manager->id,
            'province_code' => 'P1',
        ]);
        $otherRole = Role::create([
            'name' => 'Nhóm do người khác tạo',
            'scope_level' => 'province',
            'guard_name' => 'web',
            'created_by' => User::factory()->create()->id,
            'province_code' => 'P1',
        ]);
        $assignedUser = User::factory()->create([
            'scope_level' => 'province',
            'province_code' => 'P1',
        ]);
        $assignedUser->assignRole($otherRole);

        $roles = (new RoleManagementService)->rolesForIndex($manager);

        self::assertTrue($roles->getCollection()->contains('id', $ownRole->id));
        self::assertFalse($roles->getCollection()->contains('id', $otherRole->id));
    }

    public function test_province_manager_sees_only_accounts_they_created_in_their_scope(): void
    {
        $provinceManagerRole = Role::create([
            'name' => 'Quản trị cấp tỉnh kiểm thử danh sách tài khoản',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $provinceManagerRole->attachPermissions([
            Permission::firstOrCreate([
                'name' => AccessPermission::MANAGE_USER_ACCOUNTS,
                'guard_name' => 'web',
            ])->id,
            Permission::firstOrCreate([
                'name' => AccessPermission::MANAGE_ACCESS_CONTROL,
                'guard_name' => 'web',
            ])->id,
        ]);
        $provinceManager = User::factory()->create([
            'scope_level' => 'province',
            'province_code' => 'P1',
            'is_active' => true,
        ]);
        $provinceManager->assignRole($provinceManagerRole);

        $communeAdminRole = Role::create([
            'name' => 'Quản trị xã đầy đủ quyền',
            'scope_level' => 'commune',
            'guard_name' => 'web',
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ]);
        $communeAdminRole->syncPermissions(collect(AccessPermission::permissionsForScope('commune'))
            ->map(fn (string $name): int => Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ])->id)
            ->all());
        $communeAdmin = User::factory()->create([
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ]);
        $communeAdmin->assignRole($communeAdminRole);

        $staffRole = Role::create([
            'name' => 'Cán bộ nhập liệu xã',
            'scope_level' => 'commune',
            'guard_name' => 'web',
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ]);
        $staffRole->attachPermissions(Permission::firstOrCreate([
            'name' => AccessPermission::VIEW_HOUSEHOLDS,
            'guard_name' => 'web',
        ]));
        $communeStaff = User::factory()->create([
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ]);
        $communeStaff->assignRole($staffRole);
        foreach ([
            ['name' => 'Cán bộ tạo tài khoản cho cấp xã', 'username' => 'province-create-commune'],
            ['name' => 'Thanh tra trường học tỉnh', 'username' => 'province-school-inspector'],
        ] as $index => $provinceAccountData) {
            $provinceRole = Role::create([
                'name' => $provinceAccountData['name'],
                'scope_level' => 'province',
                'guard_name' => 'web',
                'created_by' => $provinceManager->id,
                'province_code' => 'P1',
            ]);
            $provinceRole->attachPermissions(Permission::firstOrCreate([
                'name' => $index === 0
                    ? AccessPermission::MANAGE_USER_ACCOUNTS
                    : AccessPermission::MANAGE_SCHOOLS,
                'guard_name' => 'web',
            ]));
            $provinceAccount = User::factory()->create([
                'username' => $provinceAccountData['username'],
                'scope_level' => 'province',
                'province_code' => 'P1',
                'created_by' => $provinceManager->id,
            ]);
            $provinceAccount->assignRole($provinceRole);
        }

        $this->authenticateApiUser($provinceManager);

        $this->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonMissing(['username' => $communeAdmin->username])
            ->assertJsonMissing(['username' => $communeStaff->username])
            ->assertJsonFragment(['username' => 'province-create-commune'])
            ->assertJsonFragment(['username' => 'province-school-inspector']);
        $this->getJson('/api/v1/roles')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonFragment(['name' => 'Cán bộ tạo tài khoản cho cấp xã', 'users_count' => 1])
            ->assertJsonFragment(['name' => 'Thanh tra trường học tỉnh', 'users_count' => 1]);
        $this->getJson("/api/v1/roles/{$provinceRole->id}/users")
            ->assertOk()
            ->assertJsonPath('data.0.username', $provinceAccount->username);
    }

    public function test_province_account_list_includes_its_commune_administrator_with_seeded_admin_permissions(): void
    {
        $managerRole = Role::create([
            'name' => 'Quản trị cấp tỉnh xem danh sách xã',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $managerRole->syncPermissions(collect([
            AccessPermission::VIEW_USERS,
            AccessPermission::MANAGE_USER_ACCOUNTS,
            AccessPermission::MANAGE_ACCESS_CONTROL,
        ])->map(fn (string $name): int => Permission::firstOrCreate([
            'name' => $name,
            'guard_name' => 'web',
        ])->id)->all());
        $manager = User::factory()->create([
            'scope_level' => 'province',
            'province_code' => 'P1',
            'is_active' => true,
        ]);
        $manager->assignRole($managerRole);

        $communeAdminRole = Role::create([
            'name' => 'Quản trị xã',
            'scope_level' => 'commune',
            'guard_name' => 'web',
            'created_by' => $manager->id,
        ]);
        DB::table('roles')->where('id', $communeAdminRole->id)->update([
            'province_code' => 'P1',
            'commune_code' => null,
        ]);
        $communeAdmin = User::factory()->create([
            'created_by' => $manager->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => true,
        ]);
        $communeAdmin->assignRole($communeAdminRole, $manager->id);

        $communePermissions = collect(AccessPermission::permissionsForScope('commune'))
            ->mapWithKeys(fn (string $name): array => [
                $name => Permission::firstOrCreate([
                    'name' => $name,
                    'guard_name' => 'web',
                ])->id,
            ]);
        foreach ([
            [
                'name' => 'Nhóm giáo viên xã',
                'permissions' => [
                    AccessPermission::VIEW_HOUSEHOLDS,
                    AccessPermission::CREATE_HOUSEHOLDS,
                    AccessPermission::IMPORT_HOUSEHOLDS,
                ],
            ],
            [
                'name' => 'Nhóm ban giám hiệu',
                'permissions' => [
                    AccessPermission::VIEW_HOUSEHOLDS,
                    AccessPermission::EXPORT_HOUSEHOLDS,
                ],
            ],
        ] as $groupDefinition) {
            $group = Role::create([
                'name' => $groupDefinition['name'],
                'scope_level' => 'commune',
                'guard_name' => 'web',
                'created_by' => $communeAdmin->id,
                'province_code' => 'P1',
                'commune_code' => 'C1',
            ]);
            $group->syncPermissions(
                $communePermissions->only($groupDefinition['permissions'])->values()->all()
            );
        }

        collect([
            AccessPermission::MANAGE_VILLAGES,
            AccessPermission::MANAGE_USER_ACCOUNTS,
            AccessPermission::MANAGE_ACCESS_CONTROL,
            AccessPermission::MANAGE_SCHOOLS,
        ])->each(fn (string $name) => Permission::firstOrCreate([
            'name' => $name,
            'guard_name' => 'web',
        ]));

        $this->syncLegacyRoleAssignment($communeAdmin);
        $migration = require database_path('migrations/2026_11_05_000001_repair_seeded_commune_admin_role_permissions.php');
        $migration->up();
        $completeMigration = require database_path('migrations/2026_11_05_000002_complete_commune_admin_seeded_permissions.php');
        $completeMigration->up();
        $this->refreshRoleCodesFromLegacyPermissions($communeAdminRole);

        $this->actingAs($manager, 'web')
            ->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonFragment([
                'username' => $communeAdmin->username,
                'role_name' => $communeAdminRole->name,
            ]);
        self::assertTrue($communeAdmin->fresh()->can('create-users'));
        self::assertTrue($communeAdmin->fresh()->can('view-users'));
        self::assertTrue($communeAdmin->fresh()->can('manage-user-accounts'));
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($communeAdmin, 'web')
            ->getJson('/api/v1/users/create')
            ->assertOk()
            ->assertJsonFragment([
                'name' => 'Nhóm giáo viên xã',
                'assignable_scope_levels' => ['commune'],
            ])
            ->assertJsonFragment([
                'name' => 'Nhóm ban giám hiệu',
                'assignable_scope_levels' => ['commune'],
            ]);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        self::assertLessThan(100, $queryCount);
    }

    public function test_commune_manager_can_revoke_an_account_they_created(): void
    {
        $managerRole = Role::create([
            'name' => 'Quản trị xã kiểm thử',
            'scope_level' => 'commune',
            'guard_name' => 'web',
        ]);
        $managerRole->attachPermissions([
            Permission::create(['name' => AccessPermission::MANAGE_USER_ACCOUNTS, 'guard_name' => 'web'])->id,
            Permission::create(['name' => AccessPermission::MANAGE_ACCESS_CONTROL, 'guard_name' => 'web'])->id,
            Permission::create(['name' => AccessPermission::VIEW_HOUSEHOLDS, 'guard_name' => 'web'])->id,
        ]);
        $communeManager = User::factory()->create([
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => true,
        ]);
        $communeManager->assignRole($managerRole);
        $staffRole = Role::create([
            'name' => 'Nhóm cán bộ xã tự tạo',
            'scope_level' => 'commune',
            'guard_name' => 'web',
            'created_by' => $communeManager->id,
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ]);
        $staffRole->attachPermissions(Permission::create([
            'name' => AccessPermission::VIEW_HOUSEHOLDS,
            'guard_name' => 'web',
        ]));
        $staff = User::factory()->create([
            'created_by' => $communeManager->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => true,
        ]);
        $staff->assignRole($staffRole, $communeManager->id);

        $this->actingAs($communeManager, 'web')
            ->getJson('/api/v1/users')
            ->assertOk()
            ->assertHeader('Server-Timing')
            ->assertJsonFragment([
                'id' => $staff->id,
                'can_change_account_status' => true,
                'can_view_details' => true,
                'can_edit_configuration' => true,
            ]);
        $this->getJson("/api/v1/users/{$staff->id}")
            ->assertOk()
            ->assertJsonPath('data.username', $staff->username);
        $this->getJson("/api/v1/users/{$staff->id}/edit")
            ->assertOk();
        $this->patchJson("/api/v1/users/{$staff->id}/status", ['is_active' => false])
            ->assertOk();
        $this->assertDatabaseHas('users', ['id' => $staff->id, 'is_active' => false]);
    }

    public function test_role_index_hides_member_expansion_controls(): void
    {
        $adminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole($adminRole);

        $group = Role::create([
            'name' => 'Nhóm quản trị tỉnh',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $member = User::factory()->create(['is_active' => true]);
        $member->assignRole($group);

        $this->actingAs($admin)
            ->get(route('api.roles.index'))
            ->assertOk()
            ->assertDontSee('data-role-members-toggle')
            ->assertDontSee('Hiện danh sách thành viên bên dưới')
            ->assertDontSee('role-members-panel');
    }

    public function test_local_account_cannot_view_members_of_another_accounts_group(): void
    {
        $adminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole($adminRole);
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
        $localGroup->attachPermissions($manageGroups);
        $localUser = User::factory()->create([
            'scope_level' => 'province',
            'province_code' => 'P1',
            'is_active' => true,
        ]);
        $localUser->assignRole($localGroup);

        $this->actingAs($localUser)->get(route('api.roles.users.index', $group))
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
        $admin->assignRole($adminRole);

        $schoolManagerRole = Role::create([
            'name' => 'Quản lý trường học',
            'description' => 'Quản lý danh mục trường học.',
            'scope_level' => 'province',
            'guard_name' => 'web',
            'created_by' => $admin->id,
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
        ])->map(fn (array $attributes) => Permission::create($attributes));
        $schoolManagerRole->attachPermissions($provincePermissions);

        $this->actingAs($admin)
            ->getJson(route('api.users.create'))
            ->assertOk()
            ->assertJsonFragment(['name' => 'Quản lý trường học']);

        $this->postJson(route('api.users.store'), [
            'name' => 'Quản lý trường cấp xã',
            'username' => 'schoolwrong1',
            'citizen_id' => '001111111107',
            'phone' => '0900000002',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role_id' => $schoolManagerRole->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => '1',
        ])->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'role_id');

        $this->assertDatabaseMissing('users', ['phone' => '0900000002']);
    }

    public function test_group_cannot_be_deleted_until_inactive_assigned_user_is_detached(): void
    {
        $adminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole($adminRole);

        $group = Role::create([
            'name' => 'Phòng nhập liệu',
            'scope_level' => 'province',
            'guard_name' => 'web',
            'created_by' => $admin->id,
        ]);
        $group->syncPermissions([
            Permission::firstOrCreate([
                'name' => AccessPermission::MANAGE_SCHOOLS,
                'guard_name' => 'web',
            ])->id,
            Permission::firstOrCreate([
                'name' => AccessPermission::MANAGE_USER_ACCOUNTS,
                'guard_name' => 'web',
            ])->id,
        ]);
        $account = User::factory()->create([
            'created_by' => $admin->id,
            'province_code' => 'P1',
            'scope_level' => 'province',
            'is_active' => true,
        ]);
        $account->assignRole($group);

        $this->actingAs($admin)
            ->deleteJson(route('api.roles.destroy', $group))
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'role');
        $this->assertDatabaseHas('roles', ['id' => $group->id]);

        $this->patchJson(route('api.users.status.update', $account), ['is_active' => false])
            ->assertOk();
        $this->deleteJson(route('api.roles.destroy', $group))
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'role');
        $this->assertDatabaseHas('roles', ['id' => $group->id]);
        $account->removeRole($group);
        $this->deleteJson(route('api.roles.destroy', $group))->assertOk();

        $this->assertDatabaseMissing('roles', ['id' => $group->id]);
        $this->assertDatabaseMissing('users', ['id' => $account->id, 'role_id' => $group->id]);
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
        $role->syncPermissions($permissions->pluck('id')->all());
        $user->assignRole($role, null);

        $this->actingAs($user)
            ->getJson(route('api.households.index'))
            ->assertOk()
            ->assertJsonPath('data', []);
        $this->getJson(route('api.households.create'))
            ->assertOk()
            ->assertJsonPath('data.household.province_code', 'P1');
    }

    public function test_household_json_api_requires_permission_and_returns_scoped_pagination(): void
    {
        Schema::table('persons', function (Blueprint $table): void {
            $table->string('relationship_with_head')->nullable();
        });

        $permission = Permission::create([
            'name' => AccessPermission::VIEW_HOUSEHOLDS,
            'guard_name' => 'web',
        ]);
        $role = Role::create([
            'name' => 'household-api-viewer',
            'scope_level' => 'commune',
            'guard_name' => 'web',
        ]);
        $role->attachPermissions($permission);
        $user = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'commune',
            'is_active' => true,
        ]);
        $user->assignRole($role);
        $this->assertTrue($user->fresh()->hasPermissionTo(AccessPermission::VIEW_HOUSEHOLDS));
        $this->assertContains(AccessPermission::VIEW_HOUSEHOLDS, $user->fresh()->permissionNames());

        $this->authenticateApiUser($user);
        $this->getJson('/api/v1/session')
            ->assertOk()
            ->assertJsonFragment(['view-households']);

        $this->getJson('/api/v1/households')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'status_code', 'message', 'data', 'meta'])
            ->assertJsonPath('meta.total', 0);
    }

    public function test_household_json_api_hides_details_outside_the_users_commune(): void
    {
        Schema::table('persons', function (Blueprint $table): void {
            $table->string('relationship_with_head')->nullable();
        });

        $permission = Permission::create([
            'name' => AccessPermission::VIEW_HOUSEHOLDS,
            'guard_name' => 'web',
        ]);
        $role = Role::create([
            'name' => 'household-api-scoped-viewer',
            'scope_level' => 'commune',
            'guard_name' => 'web',
        ]);
        $role->attachPermissions($permission);
        $user = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'commune',
            'is_active' => true,
        ]);
        $user->assignRole($role);
        $household = Household::create([
            'household_code' => 'OUTSIDE',
            'school_year' => '2025-2026',
            'province_code' => 'P1',
            'commune_code' => 'C2',
        ]);

        $this->authenticateApiUser($user);
        $this
            ->getJson("/api/v1/households/{$household->id}")
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('status_code', 404)
            ->assertJsonPath('message', 'Không tìm thấy tài nguyên yêu cầu.')
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_api_returns_a_resource_specific_message_when_village_is_missing(): void
    {
        $adminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole($adminRole);

        $this->authenticateApiUser($admin);
        $this->getJson('/api/v1/villages/form')
            ->assertOk()
            ->assertJsonPath('success', true);
        $this
            ->getJson('/api/v1/villages/NOT-FOUND')
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('status_code', 404)
            ->assertJsonPath('message', 'Không tìm thấy thôn/xóm này.')
            ->assertJsonPath('error.code', 'NOT_FOUND')
            ->assertJsonPath('error.details', []);
    }

    public function test_role_members_api_returns_paginated_member_data_for_authorized_manager(): void
    {
        $adminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole($adminRole);

        $group = Role::create([
            'name' => 'Nhóm API',
            'scope_level' => 'province',
            'guard_name' => 'web',
            'created_by' => $admin->id,
        ]);
        $member = User::factory()->create([
            'name' => 'Thành viên API',
            'username' => 'api-role-member',
            'created_by' => $admin->id,
            'is_active' => true,
        ]);
        $member->assignRole($group);

        $this->authenticateApiUser($admin);
        $this
            ->getJson("/api/v1/roles/{$group->id}/users")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.role.name', 'Nhóm API')
            ->assertJsonPath('data.0.name', 'Thành viên API')
            ->assertJsonPath('data.0.username', 'api-role-member')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_role_creation_requires_permissions_and_returns_created_role_data(): void
    {
        $adminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole($adminRole);
        $permission = Permission::create([
            'name' => AccessPermission::VIEW_HOUSEHOLDS,
            'guard_name' => 'web',
        ]);
        $this->authenticateApiUser($admin);

        $this->postJson('/api/v1/roles', [
            'name' => 'Nhóm chưa chọn quyền',
            'scope_level' => 'global',
            'permissions' => [],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'permissions');

        $this->postJson('/api/v1/roles', [
            'name' => 'Nhóm API mới',
            'scope_level' => 'global',
            'permissions' => [$permission->id],
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Nhóm API mới')
            ->assertJsonPath('data.scope_level', 'global')
            ->assertJsonPath('data.users_count', 0)
            ->assertJsonPath('data.permissions.0', AccessPermission::VIEW_HOUSEHOLDS);

    }

    public function test_role_deletion_requires_no_members_including_inactive_members(): void
    {
        $adminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole($adminRole);

        $singleMemberRole = Role::create([
            'name' => 'Nhóm một tài khoản đã khóa',
            'scope_level' => 'province',
            'guard_name' => 'web',
            'created_by' => $admin->id,
        ]);
        $singleInactiveMember = User::factory()->create([
            'created_by' => $admin->id,
            'is_active' => false,
        ]);
        $singleInactiveMember->assignRole($singleMemberRole);

        $multipleMembersRole = Role::create([
            'name' => 'Nhóm nhiều tài khoản đã khóa',
            'scope_level' => 'province',
            'guard_name' => 'web',
            'created_by' => $admin->id,
        ]);
        $multipleInactiveMembers = User::factory()->count(2)->create([
            'created_by' => $admin->id,
            'is_active' => false,
        ]);
        $multipleInactiveMembers->each(fn (User $member) => $member->assignRole($multipleMembersRole));

        $emptyRole = Role::create([
            'name' => 'Nhóm chưa có thành viên',
            'scope_level' => 'province',
            'guard_name' => 'web',
            'created_by' => $admin->id,
        ]);

        $this->authenticateApiUser($admin);

        $this->getJson('/api/v1/roles')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $singleMemberRole->id,
                'users_count' => 1,
                'total_users_count' => 1,
                'has_members' => true,
                'has_multiple_members' => false,
            ])
            ->assertJsonFragment([
                'id' => $multipleMembersRole->id,
                'users_count' => 2,
                'total_users_count' => 2,
                'has_members' => true,
                'has_multiple_members' => true,
            ])
            ->assertJsonFragment([
                'id' => $emptyRole->id,
                'total_users_count' => 0,
                'has_members' => false,
                'has_multiple_members' => false,
            ]);

        $this->deleteJson("/api/v1/roles/{$singleMemberRole->id}")
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Không thể xóa nhóm vẫn còn thành viên. Hãy gỡ toàn bộ thành viên trước khi xóa.'
            );
        $this->assertDatabaseHas('roles', ['id' => $singleMemberRole->id]);

        $this->deleteJson("/api/v1/roles/{$multipleMembersRole->id}")
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Không thể xóa nhóm vẫn còn thành viên. Hãy gỡ toàn bộ thành viên trước khi xóa.'
            );
        $this->assertDatabaseHas('roles', ['id' => $multipleMembersRole->id]);

        $this->deleteJson("/api/v1/roles/{$emptyRole->id}")->assertOk();
        $this->assertDatabaseMissing('roles', ['id' => $emptyRole->id]);
    }

    public function test_migration_marks_only_default_territorial_admin_roles_as_system_managed(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropColumn('is_system');
        });

        $provinceAdminRole = Role::create([
            'name' => 'Quản trị cấp tỉnh',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $provinceAdminRole->syncPermissions(collect([
            AccessPermission::MANAGE_SCHOOLS,
            AccessPermission::MANAGE_USER_ACCOUNTS,
        ])->map(fn (string $name): int => Permission::create([
            'name' => $name,
            'guard_name' => 'web',
        ])->id)->all());
        $communeAdminRole = Role::create([
            'name' => 'Quản trị xã',
            'scope_level' => 'commune',
            'guard_name' => 'web',
        ]);
        $communeAdminRole->syncPermissions(collect([
            AccessPermission::MANAGE_VILLAGES,
            AccessPermission::MANAGE_USER_ACCOUNTS,
        ])->map(fn (string $name): int => Permission::create([
            'name' => $name,
            'guard_name' => 'web',
        ])->id)->all());
        $customRole = Role::create([
            'name' => 'Nhóm xã nghiệp vụ',
            'scope_level' => 'commune',
            'guard_name' => 'web',
        ]);
        $customRole->attachPermissions(Permission::create([
            'name' => AccessPermission::VIEW_HOUSEHOLDS,
            'guard_name' => 'web',
        ]));
        $this->syncLegacyRolePermissions($provinceAdminRole);
        $this->syncLegacyRolePermissions($communeAdminRole);
        $this->syncLegacyRolePermissions($customRole);

        $migration = require database_path('migrations/2026_11_05_000003_lock_seeded_territorial_admin_roles.php');
        $migration->up();

        $this->assertDatabaseHas('roles', ['id' => $provinceAdminRole->id, 'is_system' => true]);
        $this->assertDatabaseHas('roles', ['id' => $communeAdminRole->id, 'is_system' => true]);
        $this->assertDatabaseHas('roles', ['id' => $customRole->id, 'is_system' => false]);
    }

    public function test_system_managed_territorial_admin_role_is_locked_but_remains_assignable(): void
    {
        $adminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole($adminRole);

        $role = Role::create([
            'name' => 'Quản trị cấp tỉnh',
            'scope_level' => 'province',
            'guard_name' => 'web',
            'created_by' => $admin->id,
        ]);
        $role->syncPermissions(collect([
            AccessPermission::MANAGE_SCHOOLS,
            AccessPermission::MANAGE_USER_ACCOUNTS,
        ])->map(fn (string $name): int => Permission::firstOrCreate([
            'name' => $name,
            'guard_name' => 'web',
        ])->id)->all());
        $role->forceFill(['is_system' => true])->save();

        $this->authenticateApiUser($admin);

        $this->getJson('/api/v1/roles')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $role->id,
                'name' => 'Quản trị cấp tỉnh',
                'is_system' => true,
                'can_manage' => false,
            ]);
        self::assertTrue((new RoleManagementService)->canViewRole($admin, $role));
        $this->getJson("/api/v1/roles/{$role->id}/edit")->assertForbidden();
        $this->putJson("/api/v1/roles/{$role->id}", [
            'name' => 'Nhóm đã sửa',
            'scope_level' => 'province',
            'permissions' => $role->permissions->modelKeys(),
        ])->assertForbidden();
        $this->deleteJson("/api/v1/roles/{$role->id}")->assertForbidden();
        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'name' => 'Quản trị cấp tỉnh',
            'is_system' => true,
        ]);

        $this->getJson('/api/v1/users/create')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $role->id,
                'name' => 'Quản trị cấp tỉnh',
                'assignable_scope_levels' => ['province'],
                'is_primary_admin' => true,
            ]);
    }

    public function test_role_manager_cannot_delegate_user_actions_they_do_not_have(): void
    {
        $permissions = collect([
            AccessPermission::VIEW_ROLES,
            AccessPermission::CREATE_ROLES,
            AccessPermission::VIEW_USERS,
            AccessPermission::UPDATE_USERS,
            AccessPermission::CREATE_USERS,
        ])->mapWithKeys(fn (string $name): array => [
            $name => Permission::create(['name' => $name, 'guard_name' => 'web']),
        ]);
        $managerRole = Role::create([
            'name' => 'role-manager-without-create-users',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $managerRole->syncPermissions([
            $permissions[AccessPermission::VIEW_ROLES]->id,
            $permissions[AccessPermission::CREATE_ROLES]->id,
            $permissions[AccessPermission::VIEW_USERS]->id,
            $permissions[AccessPermission::UPDATE_USERS]->id,
        ]);
        $manager = User::factory()->create([
            'scope_level' => 'province',
            'province_code' => 'P1',
            'is_active' => true,
        ]);
        $manager->assignRole($managerRole);
        $this->authenticateApiUser($manager);

        $this->postJson('/api/v1/roles', [
            'name' => 'role-attempting-user-escalation',
            'scope_level' => 'commune',
            'permissions' => [
                $permissions[AccessPermission::VIEW_USERS]->id,
                $permissions[AccessPermission::CREATE_USERS]->id,
            ],
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('roles', ['name' => 'role-attempting-user-escalation']);
    }

    public function test_commune_administrator_roles_receive_school_management(): void
    {
        $permissions = collect([
            AccessPermission::MANAGE_ACCESS_CONTROL,
            AccessPermission::MANAGE_USER_ACCOUNTS,
            AccessPermission::MANAGE_SCHOOLS,
            AccessPermission::MANAGE_VILLAGES,
        ])->mapWithKeys(fn (string $name): array => [
            $name => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']),
        ]);
        $provinceRole = Role::create([
            'name' => 'Quản trị tỉnh',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $provinceRole->syncPermissions([
            $permissions[AccessPermission::MANAGE_ACCESS_CONTROL]->id,
            $permissions[AccessPermission::MANAGE_USER_ACCOUNTS]->id,
            $permissions[AccessPermission::MANAGE_SCHOOLS]->id,
        ]);
        $provinceAdmin = User::factory()->create([
            'is_active' => true,
            'scope_level' => 'province',
            'province_code' => 'P1',
        ]);
        $provinceAdmin->assignRole($provinceRole);
        $this->authenticateApiUser($provinceAdmin);

        $this->postJson('/api/v1/roles', [
            'name' => 'Quản trị xã mới',
            'scope_level' => 'commune',
            'permissions' => [
                $permissions[AccessPermission::MANAGE_ACCESS_CONTROL]->id,
                $permissions[AccessPermission::MANAGE_USER_ACCOUNTS]->id,
                $permissions[AccessPermission::MANAGE_VILLAGES]->id,
            ],
        ])->assertCreated();

        $communeAdminRole = Role::where('name', 'Quản trị xã mới')->firstOrFail();
        $this->assertTrue($communeAdminRole->permissions->contains(
            'name',
            AccessPermission::MANAGE_SCHOOLS
        ));
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
        $role->attachPermissions($accountPermission);
        $user = User::factory()->create([
            'scope_level' => 'province',
            'is_active' => true,
        ]);
        $user->assignRole($role);

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
        $globalRole->attachPermissions($viewPermission);

        $provinceUser = User::factory()->create([
            'scope_level' => 'province',
            'is_active' => true,
        ]);
        $provinceUser->assignRole($globalRole);
        $this->assertFalse($provinceUser->hasPermissionTo(AccessPermission::VIEW_HOUSEHOLDS));

        $systemRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $systemUser = User::factory()->create(['is_active' => true]);
        $systemUser->assignRole($systemRole);
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
        $editorRole->syncPermissions(collect([
            $permissionIds[AccessPermission::CREATE_HOUSEHOLDS],
            $permissionIds[AccessPermission::UPDATE_HOUSEHOLDS],
            $permissionIds[AccessPermission::DELETE_HOUSEHOLDS],
            $permissionIds[AccessPermission::IMPORT_HOUSEHOLDS],
            $permissionIds[AccessPermission::EXPORT_HOUSEHOLDS],
        ])->all());
        $editor = User::factory()->create(['scope_level' => 'commune', 'is_active' => true]);
        $editor->assignRole($editorRole, null);

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
        $viewerRole->attachPermissions($permissionIds[AccessPermission::VIEW_HOUSEHOLDS]);
        $viewer = User::factory()->create(['scope_level' => 'commune', 'is_active' => true]);
        $viewer->assignRole($viewerRole, null);

        $this->assertTrue($viewer->hasPermissionTo(AccessPermission::VIEW_HOUSEHOLDS));
        $this->assertFalse($viewer->hasPermissionTo(AccessPermission::CREATE_HOUSEHOLDS));
        $this->assertFalse($viewer->hasPermissionTo(AccessPermission::UPDATE_HOUSEHOLDS));
        $this->assertFalse($viewer->hasPermissionTo(AccessPermission::DELETE_HOUSEHOLDS));
        $this->assertFalse($viewer->hasPermissionTo(AccessPermission::IMPORT_HOUSEHOLDS));
        $this->assertFalse($viewer->hasPermissionTo(AccessPermission::EXPORT_HOUSEHOLDS));

        $unassigned = User::factory()->create(['is_active' => true]);
        $this->assertFalse($unassigned->hasPermissionTo(AccessPermission::VIEW_HOUSEHOLDS));
    }

    public function test_import_users_receive_geography_lookups_scoped_to_their_assigned_area(): void
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
        $provinceRole->attachPermissions(Permission::create([
            'name' => AccessPermission::IMPORT_HOUSEHOLDS,
            'guard_name' => 'web',
        ]));
        $provinceUser = User::factory()->create([
            'province_code' => 'P1',
            'scope_level' => 'province',
            'is_active' => true,
        ]);
        $provinceUser->assignRole($provinceRole);

        $this->actingAs($provinceUser)
            ->getJson('/api/v1/provinces')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'P1')
            ->assertJsonCount(1, 'data');

        $communeRole = Role::create(['name' => 'commune-importer', 'scope_level' => 'commune', 'guard_name' => 'web']);
        $communeRole->attachPermissions(Permission::where('name', AccessPermission::IMPORT_HOUSEHOLDS)->firstOrFail());
        $communeUser = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'commune',
            'is_active' => true,
        ]);
        $communeUser->assignRole($communeRole);

        $this->actingAs($communeUser)
            ->getJson('/api/v1/provinces')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'P1')
            ->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/communes/C1/villages')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['code' => 'V1'])
            ->assertJsonFragment(['code' => 'V2']);

        $villageRole = Role::create(['name' => 'commune-importer', 'scope_level' => 'commune', 'guard_name' => 'web']);
        $villageRole->attachPermissions(Permission::create([
            'name' => AccessPermission::IMPORT_HOUSEHOLDS,
            'guard_name' => 'web',
        ]));
        $villageUser = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'commune',
            'is_active' => true,
        ]);
        $villageUser->assignRole($villageRole);
        $this->syncUserVillages($villageUser, ['V1']);

        $this->actingAs($villageUser)
            ->getJson('/api/v1/communes/C1/villages')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'V1')
            ->assertJsonCount(1, 'data');

        $this->syncUserVillages($villageUser, ['V1', 'V2']);
        $this->getJson('/api/v1/communes/C1/villages')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['code' => 'V1'])
            ->assertJsonFragment(['code' => 'V2']);
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
        $admin->assignRole($adminRole);

        User::factory()->create([
            'name' => 'Tài khoản tỉnh',
            'province_code' => '01',
            'scope_level' => 'province',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->getJson(route('api.users.index'))
            ->assertOk()
            ->assertJsonFragment(['code' => '01', 'name' => 'Thành phố Hà Nội']);
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
        $admin->assignRole($adminRole);
        $accountRole = Role::create([
            'name' => 'Nhân viên nhập liệu',
            'scope_level' => 'province',
            'guard_name' => 'web',
            'created_by' => $admin->id,
        ]);
        $accountRole->syncPermissions([
            Permission::firstOrCreate([
                'name' => AccessPermission::MANAGE_SCHOOLS,
                'guard_name' => 'web',
            ])->id,
            Permission::firstOrCreate([
                'name' => AccessPermission::MANAGE_USER_ACCOUNTS,
                'guard_name' => 'web',
            ])->id,
        ]);

        $villageOneUser = User::factory()->create([
            'name' => 'Người dùng thôn một',
            'username' => 'thonmot',
            'created_by' => $admin->id,
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'province',
            'is_active' => true,
        ]);
        $villageOneUser->assignRole($accountRole);
        $this->syncUserVillages($villageOneUser, ['V1', 'V2']);

        $villageTwoUser = User::factory()->create([
            'name' => 'Người dùng thôn hai',
            'username' => 'thonthai',
            'created_by' => $admin->id,
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'province',
            'is_active' => true,
        ]);
        $villageTwoUser->assignRole($accountRole);
        $this->syncUserVillages($villageTwoUser, ['V2']);

        $otherProvinceUser = User::factory()->create([
            'name' => 'Người dùng tỉnh hai',
            'created_by' => $admin->id,
            'province_code' => 'P2',
            'commune_code' => 'C2',
            'scope_level' => 'province',
            'is_active' => true,
        ]);
        $otherProvinceUser->assignRole($accountRole);

        $this->actingAs($admin)
            ->getJson(route('api.users.index', [
                'q' => 'thonmot',
                'province_code' => 'P1',
                'commune_code' => 'C1',
                'village_code' => 'V1',
            ]))
            ->assertOk()
            ->assertJsonFragment(['name' => 'Người dùng thôn một'])
            ->assertJsonMissing(['name' => 'Người dùng thôn hai'])
            ->assertJsonMissing(['name' => 'Người dùng tỉnh hai'])
            ->assertJsonPath('data.allUsers.0.village_names', 'Thôn một, Thôn hai')
            ->assertJsonPath('data.selected_filters.village_code', 'V1');

        $this->actingAs($admin)
            ->getJson(route('api.users.index'))
            ->assertOk()
            ->assertJsonFragment(['name' => 'Người dùng thôn một'])
            ->assertJsonFragment(['name' => 'Người dùng thôn hai'])
            ->assertJsonFragment(['name' => 'Người dùng tỉnh hai']);
    }

    public function test_province_account_list_hides_the_tree_and_shows_commune_and_own_province_accounts(): void
    {
        $manageAccounts = Permission::create([
            'name' => AccessPermission::MANAGE_USER_ACCOUNTS,
            'guard_name' => 'web',
        ]);
        $provinceRole = Role::create([
            'name' => 'Quản lý tài khoản tỉnh',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $provinceRole->attachPermissions($manageAccounts);
        $provinceAdmin = User::factory()->create([
            'scope_level' => 'province',
            'province_code' => 'P1',
            'is_active' => true,
        ]);
        $provinceAdmin->assignRole($provinceRole);

        $communeAccountRole = Role::create([
            'name' => 'Tài khoản cấp xã',
            'scope_level' => 'commune',
            'guard_name' => 'web',
            'created_by' => $provinceAdmin->id,
            'province_code' => 'P1',
        ]);
        $communeAccountRole->syncPermissions([
            Permission::firstOrCreate([
                'name' => AccessPermission::MANAGE_USER_ACCOUNTS,
                'guard_name' => 'web',
            ])->id,
            Permission::firstOrCreate([
                'name' => AccessPermission::MANAGE_VILLAGES,
                'guard_name' => 'web',
            ])->id,
        ]);
        $provinceAccountRole = Role::create([
            'name' => 'Tài khoản cấp tỉnh khác',
            'scope_level' => 'province',
            'guard_name' => 'web',
            'created_by' => $provinceAdmin->id,
            'province_code' => 'P1',
        ]);
        $provinceAccountRole->syncPermissions([
            Permission::firstOrCreate([
                'name' => AccessPermission::MANAGE_USER_ACCOUNTS,
                'guard_name' => 'web',
            ])->id,
            Permission::firstOrCreate([
                'name' => AccessPermission::MANAGE_SCHOOLS,
                'guard_name' => 'web',
            ])->id,
        ]);
        $globalAccountRole = Role::create([
            'name' => 'Tài khoản toàn hệ thống',
            'scope_level' => 'global',
            'guard_name' => 'web',
            'created_by' => $provinceAdmin->id,
            'province_code' => 'P1',
        ]);

        $communeAccount = User::factory()->create([
            'name' => 'Quản trị xã được hiển thị',
            'username' => 'visible-commune-account',
            'created_by' => $provinceAdmin->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => true,
        ]);
        $communeAccount->assignRole($communeAccountRole);

        $provinceAccount = User::factory()->create([
            'name' => 'Tài khoản tỉnh do mình tạo',
            'username' => 'visible-own-province-account',
            'scope_level' => 'province',
            'province_code' => 'P1',
            'created_by' => $provinceAdmin->id,
            'is_active' => true,
        ]);
        $provinceAccount->assignRole($provinceAccountRole);

        $otherProvinceAccount = User::factory()->create([
            'name' => 'Tài khoản tỉnh do người khác tạo',
            'username' => 'hidden-other-province-account',
            'scope_level' => 'province',
            'province_code' => 'P1',
            'created_by' => User::factory()->create()->id,
            'is_active' => true,
        ]);
        $otherProvinceAccount->assignRole($provinceAccountRole);

        $globalAccount = User::factory()->create([
            'name' => 'Tài khoản toàn hệ thống không hiển thị',
            'username' => 'hidden-global-account',
            'scope_level' => 'global',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => true,
        ]);
        $globalAccount->assignRole($globalAccountRole);

        $outsideCommuneAccount = User::factory()->create([
            'name' => 'Tài khoản xã ngoài tỉnh',
            'username' => 'hidden-outside-commune-account',
            'scope_level' => 'commune',
            'province_code' => 'P2',
            'commune_code' => 'C2',
            'is_active' => true,
        ]);
        $outsideCommuneAccount->assignRole(Role::create([
            'name' => 'Tài khoản xã ngoài phạm vi',
            'scope_level' => 'commune',
            'guard_name' => 'web',
        ]));

        $this->actingAs($provinceAdmin)
            ->getJson(route('api.users.index'))
            ->assertOk()
            ->assertJsonFragment(['username' => 'visible-commune-account'])
            ->assertJsonFragment(['username' => 'visible-own-province-account'])
            ->assertJsonMissing(['username' => 'hidden-other-province-account'])
            ->assertJsonMissing(['username' => 'hidden-global-account'])
            ->assertJsonMissing(['username' => 'hidden-outside-commune-account']);
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
        $admin->assignRole($adminRole);
        $accountRole = Role::create([
            'name' => 'Quản trị tỉnh',
            'scope_level' => 'province',
            'guard_name' => 'web',
            'created_by' => $admin->id,
        ]);
        $accountRole->syncPermissions([
            Permission::firstOrCreate([
                'name' => AccessPermission::MANAGE_USER_ACCOUNTS,
                'guard_name' => 'web',
            ])->id,
            Permission::firstOrCreate([
                'name' => AccessPermission::MANAGE_SCHOOLS,
                'guard_name' => 'web',
            ])->id,
        ]);

        foreach (range(0, 30) as $index) {
            $user = User::factory()->create([
                'name' => sprintf('Tài khoản %02d', $index),
                'username' => sprintf('account-%02d', $index),
                'created_by' => $admin->id,
                'province_code' => 'P1',
                'scope_level' => 'province',
                'is_active' => true,
            ]);
            $user->assignRole($accountRole);
        }

        $this->actingAs($admin)
            ->getJson(route('api.users.index'))
            ->assertOk()
            ->assertJsonCount(10, 'data.allUsers')
            ->assertJsonPath('meta.total', 31)
            ->assertJsonFragment(['name' => 'Tài khoản 00'])
            ->assertJsonMissing(['name' => 'Tài khoản 30']);

        $this->actingAs($admin)
            ->getJson(route('api.users.index', ['page' => 4]))
            ->assertOk()
            ->assertJsonPath('meta.current_page', 4)
            ->assertJsonFragment(['name' => 'Tài khoản 30'])
            ->assertJsonMissing(['name' => 'Tài khoản 00']);
    }

    public function test_role_member_json_api_paginates_member_data(): void
    {
        $adminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole($adminRole);
        $group = Role::create([
            'name' => 'Nhóm nhiều thành viên',
            'scope_level' => 'province',
            'guard_name' => 'web',
            'created_by' => $admin->id,
        ]);

        foreach (range(1, 6) as $index) {
            $member = User::factory()->create([
                'name' => "Thành viên {$index}",
                'username' => "member-{$index}",
                'created_by' => $admin->id,
                'scope_level' => 'province',
                'is_active' => true,
            ]);
            $member->assignRole($group);
        }

        $this->authenticateApiUser($admin);
        $this
            ->getJson(route('api.roles.users.index', $group))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.name', 'Thành viên 1')
            ->assertJsonPath('data.4.name', 'Thành viên 5')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson(route('api.roles.users.index', ['role' => $group, 'page' => 2]))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Thành viên 6')
            ->assertJsonPath('meta.current_page', 2);

        $this->getJson(route('api.roles.users.index', ['role' => $group, 'page' => 2, 'per_page' => 3]))
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.name', 'Thành viên 4')
            ->assertJsonPath('meta.per_page', 3);
    }

    public function test_role_and_user_api_lists_filter_and_limit_page_size(): void
    {
        $adminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole($adminRole);

        $schoolPermission = Permission::create(['name' => AccessPermission::MANAGE_SCHOOLS]);
        $accountPermission = Permission::create(['name' => AccessPermission::MANAGE_USER_ACCOUNTS]);
        foreach (range(1, 3) as $index) {
            $role = Role::create([
                'name' => "API paged role {$index}",
                'scope_level' => 'province',
                'guard_name' => 'web',
                'created_by' => $admin->id,
            ]);
            $role->attachPermissions([$schoolPermission->id, $accountPermission->id]);
            $user = User::factory()->create([
                'name' => "API paged user {$index}",
                'username' => "api-paged-user-{$index}",
                'phone' => "090000000{$index}",
                'created_by' => $admin->id,
                'scope_level' => 'province',
                'is_active' => true,
            ]);
            $user->assignRole($role);
        }

        $this->authenticateApiUser($admin);

        $this->getJson('/api/v1/roles?q=API%20paged%20role&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3);

        $this->getJson('/api/v1/roles?q=API%20paged%20role&scope_level=province&per_page=100')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonPath('meta.total', 3);

        $this->getJson('/api/v1/roles?per_page=100')
            ->assertOk()
            ->assertJsonMissing(['name' => Role::SYSTEM_ADMIN]);

        $response = $this->getJson('/api/v1/users?q=API%20paged%20user&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.allUsers')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.allUsers.0.phone', '0900000001')
            ->assertJsonPath('data.account_scope_levels', ['global', 'province'])
            ->assertJsonMissingPath('data.allUsers.0.citizen_id')
            ->assertJsonMissingPath('data.hierarchy')
            ->assertJsonMissingPath('data.roleOptions')
            ->assertJsonStructure([
                'data' => [
                    'allUsers' => [[
                        'id',
                        'name',
                        'username',
                        'phone',
                        'scope_level',
                        'province_name',
                        'is_active',
                        'can_change_account_status',
                        'can_reset_account_password',
                    ]],
                ],
            ]);

        $this->getJson('/api/v1/users?q=API%20paged%20user&per_page=100')
            ->assertOk()
            ->assertJsonCount(3, 'data.allUsers')
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonPath('meta.total', 3);

        $this->getJson('/api/v1/users?scope_level=province&q=API%20paged%20user')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.selected_filters.scope_level', 'province');
        $this->getJson('/api/v1/users?scope_level=commune')
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'scope_level');

        $this->assertLessThan(4000, strlen($response->getContent()));
    }

    public function test_phone_seeder_fills_only_missing_user_phone_numbers(): void
    {
        $withoutPhone = User::factory()->create(['phone' => null]);
        $withPhone = User::factory()->create(['phone' => '0912345678']);

        (new SeedMissingUserPhonesSeeder)->run();

        $this->assertSame('000'.str_pad((string) $withoutPhone->id, 7, '0', STR_PAD_LEFT), $withoutPhone->fresh()->phone);
        $this->assertSame('0912345678', $withPhone->fresh()->phone);
    }

    public function test_legacy_user_personal_information_seeder_fills_only_missing_values(): void
    {
        $withoutInformation = User::factory()->create([
            'citizen_id' => null,
            'date_of_birth' => null,
            'email' => null,
        ]);
        $withInformation = User::factory()->create([
            'citizen_id' => '123456789012',
            'date_of_birth' => '1990-04-15',
            'email' => 'existing@example.test',
        ]);

        (new SeedLegacyUserPersonalInformationSeeder)->run();

        $seededUser = $withoutInformation->fresh();
        $this->assertSame('999'.str_pad((string) $seededUser->id, 9, '0', STR_PAD_LEFT), $seededUser->citizen_id);
        $this->assertSame("pcgd.user{$seededUser->id}@example.test", $seededUser->email);
        $this->assertNotNull($seededUser->date_of_birth);
        $this->assertSame('123456789012', $withInformation->fresh()->citizen_id);
        $this->assertSame('1990-04-15', $withInformation->fresh()->date_of_birth->format('Y-m-d'));
        $this->assertSame('existing@example.test', $withInformation->fresh()->email);
    }

    public function test_system_admin_account_form_follows_srs_for_central_and_provincial_accounts(): void
    {
        DB::table('provinces')->insert([
            'code' => 'P01',
            'name' => 'Tỉnh một',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $systemAdminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole($systemAdminRole);

        $centralStaffRole = Role::create([
            'name' => 'Cán bộ Trung ương',
            'scope_level' => 'global',
            'guard_name' => 'web',
            'created_by' => $admin->id,
        ]);
        $centralStaffRole->attachPermissions(Permission::create([
            'name' => AccessPermission::VIEW_HOUSEHOLDS,
            'guard_name' => 'web',
        ]));

        $provincialAdminRole = Role::create([
            'name' => 'Quản trị cấp tỉnh',
            'scope_level' => 'province',
            'guard_name' => 'web',
            'created_by' => $admin->id,
        ]);
        $provincialAdminRole->attachPermissions([
            Permission::create([
                'name' => AccessPermission::MANAGE_SCHOOLS,
                'guard_name' => 'web',
            ])->id,
            Permission::create([
                'name' => AccessPermission::MANAGE_USER_ACCOUNTS,
                'guard_name' => 'web',
            ])->id,
        ]);
        $existingProvinceAdmin = User::factory()->create([
            'scope_level' => 'province',
            'province_code' => 'P01',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $existingProvinceAdmin->assignRole($provincialAdminRole);
        $provinceStaffRole = Role::create([
            'name' => 'Cán bộ tạo tài khoản cho cấp xã',
            'scope_level' => 'province',
            'guard_name' => 'web',
            'created_by' => $admin->id,
        ]);
        $provinceStaffRole->attachPermissions(Permission::create([
            'name' => AccessPermission::MANAGE_USER_ACCOUNTS,
            'guard_name' => 'web',
        ]));
        $provinceStaff = User::factory()->create([
            'scope_level' => 'province',
            'province_code' => 'P01',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $provinceStaff->assignRole($provinceStaffRole);

        $this->authenticateApiUser($admin);

        $this->postJson('/api/v1/users', [
            'name' => 'Tài khoản thiếu CCCD',
            'username' => 'no-citizen1',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'role_id' => $provincialAdminRole->id,
            'scope_level' => 'province',
            'province_code' => 'P01',
            'is_active' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'citizen_id');

        $this->getJson('/api/v1/users/create')
            ->assertOk()
            ->assertJsonPath('data.account_levels', ['global', 'province'])
            ->assertJsonFragment([
                'name' => 'Cán bộ Trung ương',
                'scope_level' => 'global',
                'assignable_scope_levels' => ['global'],
            ])
            ->assertJsonFragment([
                'name' => 'Quản trị cấp tỉnh',
                'scope_level' => 'province',
                'assignable_scope_levels' => ['province'],
                'is_primary_admin' => true,
            ])
            ->assertJsonPath('data.available_primary_admin_scopes.province', false)
            ->assertJsonMissing([
                'name' => 'Cán bộ tạo tài khoản cho cấp xã',
            ])
            ->assertJsonMissing(['name' => Role::SYSTEM_ADMIN]);

        $this->getJson('/api/v1/users?page=1&per_page=10')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonFragment([
                'username' => $existingProvinceAdmin->username,
                'role_name' => 'Quản trị cấp tỉnh',
            ])
            ->assertJsonFragment([
                'id' => $provincialAdminRole->id,
                'name' => 'Quản trị cấp tỉnh',
            ])
            ->assertJsonMissing([
                'id' => $provinceStaffRole->id,
                'name' => 'Cán bộ tạo tài khoản cho cấp xã',
            ])
            ->assertJsonMissing(['username' => $provinceStaff->username]);

        $this->getJson("/api/v1/users?role_id={$provincialAdminRole->id}")
            ->assertOk()
            ->assertJsonPath('data.selected_filters.role_id', (string) $provincialAdminRole->id)
            ->assertJsonFragment(['username' => $existingProvinceAdmin->username])
            ->assertJsonMissing(['username' => $provinceStaff->username]);

        $this->patchJson("/api/v1/users/{$provinceStaff->id}/status", [
            'is_active' => false,
        ])->assertForbidden();

        $this->postJson('/api/v1/users', [
            'name' => 'Tài khoản không phải admin tỉnh',
            'username' => 'provstaff1',
            'citizen_id' => '001111111111',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'role_id' => $provinceStaffRole->id,
            'scope_level' => 'province',
            'province_code' => 'P01',
            'is_active' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'role_id');

        $this->postJson('/api/v1/users', [
            'name' => 'Tài khoản mật khẩu ngắn',
            'username' => 'shortpass1',
            'citizen_id' => '001111111112',
            'password' => 'short',
            'password_confirmation' => 'short',
            'role_id' => $provincialAdminRole->id,
            'scope_level' => 'province',
            'province_code' => 'P01',
            'is_active' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'password')
            ->assertJsonPath('error.details.0.issue', 'Mật khẩu phải có ít nhất 12 ký tự.');

        $this->postJson('/api/v1/users', [
            'name' => 'Quản trị tỉnh trùng',
            'username' => 'duplicate1',
            'citizen_id' => '001111111113',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'role_id' => $provincialAdminRole->id,
            'scope_level' => 'province',
            'province_code' => 'P01',
            'is_active' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'province_code');

        $existingProvinceAdmin->forceFill(['is_active' => false])->save();

        $this->getJson('/api/v1/users/create')
            ->assertOk()
            ->assertJsonPath('data.available_primary_admin_scopes.province', true)
            ->assertJsonFragment(['code' => 'P01', 'name' => 'Tỉnh một']);

        $this->postJson('/api/v1/users', [
            'name' => 'Quản trị tỉnh thay thế',
            'username' => 'replaceadmin',
            'phone' => '+84912345678',
            'citizen_id' => '001234567890',
            'date_of_birth' => '1990-04-15',
            'email' => 'replacement@example.test',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'role_id' => $provincialAdminRole->id,
            'scope_level' => 'province',
            'province_code' => 'P01',
            'is_active' => true,
        ])->assertCreated();

        $this->patchJson("/api/v1/users/{$existingProvinceAdmin->id}/status", [
            'is_active' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'province_code')
            ->assertJsonPath('error.details.0.issue', 'Tỉnh này đã có tài khoản quản trị đang hoạt động. Mỗi tỉnh chỉ có một quản trị viên.');

        $this->assertDatabaseHas('users', [
            'id' => $existingProvinceAdmin->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('users', [
            'username' => 'replaceadmin',
            'phone' => '+84912345678',
            'citizen_id' => '001234567890',
            'email' => 'replacement@example.test',
        ]);
        self::assertSame(
            '1990-04-15',
            User::where('username', 'replaceadmin')->firstOrFail()->date_of_birth->format('Y-m-d'),
        );
    }

    public function test_api_can_create_account_with_a_role_after_validating_its_scope(): void
    {
        DB::table('provinces')->insert([
            'code' => 'P01',
            'name' => 'Tỉnh một',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $systemRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole($systemRole);

        $provinceRole = Role::create([
            'name' => 'Quản trị cấp tỉnh',
            'scope_level' => 'province',
            'guard_name' => 'web',
            'created_by' => $admin->id,
        ]);
        $provinceRole->syncPermissions(collect([
            AccessPermission::MANAGE_SCHOOLS,
            AccessPermission::MANAGE_USER_ACCOUNTS,
        ])->map(fn (string $name): int => Permission::firstOrCreate([
            'name' => $name,
            'guard_name' => 'web',
        ])->id)->all());

        $this->authenticateApiUser($admin);
        $this->postJson('/api/v1/users', [
            'name' => 'Quản trị tỉnh mới',
            'username' => 'provadminapi',
            'citizen_id' => '001111111114',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'role_id' => $provinceRole->id,
            'scope_level' => 'province',
            'province_code' => 'P01',
            'is_active' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('users', [
            'username' => 'provadminapi',
            'created_by' => $admin->id,
            'province_code' => 'P01',
            'scope_level' => 'province',
        ]);
    }

    public function test_central_user_can_only_use_account_status_and_password_actions(): void
    {
        DB::table('provinces')->insert([
            'code' => 'P1',
            'name' => 'Tỉnh một',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $permission = Permission::create([
            'name' => AccessPermission::MANAGE_USER_ACCOUNTS,
            'guard_name' => 'web',
        ]);
        $centralRole = Role::create([
            'name' => 'Quản trị Trung ương',
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $centralRole->attachPermissions($permission);
        $centralUser = User::factory()->create([
            'scope_level' => 'global',
            'is_active' => true,
        ]);
        $centralUser->assignRole($centralRole);
        $targetRole = Role::create([
            'name' => 'Quản trị cấp tỉnh',
            'scope_level' => 'province',
            'guard_name' => 'web',
            'created_by' => $centralUser->id,
        ]);
        $targetRole->attachPermissions([
            Permission::create([
                'name' => AccessPermission::MANAGE_SCHOOLS,
                'guard_name' => 'web',
            ])->id,
            Permission::create([
                'name' => AccessPermission::MANAGE_USER_ACCOUNTS,
                'guard_name' => 'web',
            ])->id,
        ]);
        $target = User::factory()->create([
            'scope_level' => 'province',
            'province_code' => 'P1',
            'is_active' => true,
            'created_by' => $centralUser->id,
        ]);
        $target->assignRole($targetRole);
        $regularProvinceRole = Role::create([
            'name' => 'Tài khoản tỉnh thông thường',
            'scope_level' => 'province',
            'guard_name' => 'web',
            'created_by' => $centralUser->id,
        ]);
        $regularProvinceRole->attachPermissions(Permission::create([
            'name' => AccessPermission::VIEW_HOUSEHOLDS,
            'guard_name' => 'web',
        ]));
        $regularProvinceUser = User::factory()->create([
            'scope_level' => 'province',
            'province_code' => 'P1',
            'is_active' => true,
            'created_by' => $centralUser->id,
        ]);
        $regularProvinceUser->assignRole($regularProvinceRole);

        $this->authenticateApiUser($centralUser);

        $this->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonPath('data.actor_scope_level', 'global')
            ->assertJsonPath('data.can_edit_account_configurations', false)
            ->assertJsonPath('data.allUsers.0.id', $target->id)
            ->assertJsonCount(1, 'data.allUsers')
            ->assertJsonPath('data.allUsers.0.can_change_account_status', true)
            ->assertJsonPath('data.allUsers.0.can_reset_account_password', true);
        $this->patchJson("/api/v1/users/{$regularProvinceUser->id}/status", [
            'is_active' => false,
        ])->assertForbidden();
        $this->postJson("/api/v1/users/{$regularProvinceUser->id}/password-resets")
            ->assertForbidden();
        $this->getJson('/api/v1/users/create')->assertForbidden();
        $this->getJson("/api/v1/users/{$target->id}/edit")->assertForbidden();
        $this->getJson("/api/v1/users/{$target->id}")->assertOk();
    }

    public function test_management_accounts_show_a_crown_and_appear_before_regular_accounts(): void
    {
        $adminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole($adminRole);

        $manageUsers = Permission::create([
            'name' => AccessPermission::MANAGE_USER_ACCOUNTS,
            'guard_name' => 'web',
        ]);
        $manageSchools = Permission::create([
            'name' => AccessPermission::MANAGE_SCHOOLS,
            'guard_name' => 'web',
        ]);
        $provinceRole = Role::create([
            'name' => 'Quản trị tỉnh',
            'scope_level' => 'province',
            'guard_name' => 'web',
            'created_by' => $admin->id,
        ]);
        $provinceRole->attachPermissions([$manageUsers->id, $manageSchools->id]);
        $regularRole = Role::create([
            'name' => 'Cán bộ Trung ương',
            'scope_level' => 'global',
            'guard_name' => 'web',
            'created_by' => $admin->id,
        ]);
        $regularRole->attachPermissions(Permission::create([
            'name' => AccessPermission::VIEW_HOUSEHOLDS,
            'guard_name' => 'web',
        ]));

        foreach (
            [
                ['name' => 'Tài khoản thường', 'role' => $regularRole, 'scope' => 'global'],
                ['name' => 'Tài khoản quản trị tỉnh', 'role' => $provinceRole, 'scope' => 'province'],
            ] as $account
        ) {
            $user = User::factory()->create([
                'name' => $account['name'],
                'scope_level' => $account['scope'],
                'is_active' => true,
                'created_by' => $admin->id,
            ]);
            $user->assignRole($account['role']);
        }

        $this->actingAs($admin)
            ->getJson(route('api.users.index'))
            ->assertOk()
            ->assertJsonPath('data.allUsers.0.name', 'Tài khoản quản trị tỉnh')
            ->assertJsonPath('data.allUsers.1.name', 'Tài khoản thường');
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
        $role->attachPermissions(Permission::create([
            'name' => AccessPermission::MANAGE_VILLAGES,
            'guard_name' => 'web',
        ]));
        $user = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'commune',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        $this->actingAs($user)
            ->getJson(route('api.villages.create'))
            ->assertOk()
            ->assertJsonPath('data.province_code', 'P1')
            ->assertJsonPath('data.commune_code', 'C1');

        $this->getJson(route('api.villages.index'))
            ->assertOk()
            ->assertJsonPath('data.province_code', 'P1')
            ->assertJsonPath('data.commune_code', 'C1');
    }

    public function test_commune_user_can_select_schools_in_the_household_province(): void
    {
        Schema::create('ethnicities', function (Blueprint $table): void {
            $table->string('code')->primary();
            $table->string('name');
            $table->timestamps();
        });
        DB::table('provinces')->insert(['code' => 'P1', 'name' => 'Tỉnh một', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('provinces')->insert(['code' => 'P2', 'name' => 'Tỉnh hai', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('communes')->insert([
            ['code' => 'C1', 'name' => 'Xã được giao', 'province_code' => 'P1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'C2', 'name' => 'Xã khác', 'province_code' => 'P1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'C3', 'name' => 'Xã tỉnh khác', 'province_code' => 'P2', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('schools')->insert([
            ['code' => 'S1', 'name' => 'Trường được giao', 'province_code' => 'P1', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'S2', 'name' => 'Trường ngoài phạm vi', 'province_code' => 'P1', 'commune_code' => 'C2', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'S3', 'name' => null, 'province_code' => 'P1', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'S4', 'name' => 'Trường tỉnh khác', 'province_code' => 'P2', 'commune_code' => 'C3', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('ethnicities')->insert(['code' => 'KINH', 'name' => 'Kinh', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('households')->insert([
            'household_code' => 'HK1',
            'school_year_id' => \App\Models\SchoolYear::resolveOrCreateFromLabel('2025-2026')->id,
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $role = Role::create(['name' => 'commune-form-editor', 'scope_level' => 'commune', 'guard_name' => 'web']);
        $role->attachPermissions([
            Permission::create(['name' => AccessPermission::CREATE_HOUSEHOLDS, 'guard_name' => 'web'])->id,
            Permission::create(['name' => AccessPermission::VIEW_HOUSEHOLDS, 'guard_name' => 'web'])->id,
            Permission::create(['name' => AccessPermission::MANAGE_SCHOOLS, 'guard_name' => 'web'])->id,
        ]);
        $user = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'commune',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        $this->actingAs($user)
            ->getJson('/api/v1/schools')
            ->assertOk()
            ->assertJsonPath('data.actor_scope_level', 'commune')
            ->assertJsonPath('data.commune_code', 'C1');

        $this->actingAs($user)
            ->getJson(route('api.households.persons.create', ['household' => 1, 'is_head' => 1]))
            ->assertOk()
            ->assertJsonPath('data.schools', []);

        $this->getJson('/api/v1/households/1/persons/schools?q=S1')
            ->assertOk()
            ->assertJsonPath('data.schools.0.code', 'S1');
        $this->getJson('/api/v1/households/1/persons/schools?q=Trường')
            ->assertOk()
            ->assertJsonFragment(['code' => 'S1'])
            ->assertJsonFragment(['code' => 'S2']);
        $this->getJson('/api/v1/households/1/persons/schools?q=S3')
            ->assertOk()
            ->assertJsonPath('data.schools.0.code', 'S3')
            ->assertJsonMissing(['code' => 'S2']);

        $this->getJson('/api/v1/provinces/P1/schools?commune_code=C1')
            ->assertOk()
            ->assertJsonFragment(['code' => 'S3', 'name' => 'Chưa cập nhật tên (S3)'])
            ->assertJsonMissing(['code' => 'S2']);

        $this->postJson(route('api.households.persons.store', ['household' => 1, 'is_head' => 1]), [
            'first_name' => 'An',
            'last_name' => 'Nguyễn',
            'dob' => '2010-01-01',
            'gender' => 'NAM',
            'ethnicity_code' => 'KINH',
            'school_province_code' => 'P2',
            'school_commune_code' => 'C3',
            'education' => [
                'school_year' => '2025-2026',
                'school_code' => 'S4',
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'school_province_code');

        $this->assertSame(0, DB::table('persons')->count());
    }

    public function test_school_activity_status_can_be_created_updated_and_listed(): void
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
        $role = Role::create([
            'name' => 'school-activity-manager',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $role->attachPermissions([
            Permission::create(['name' => AccessPermission::VIEW_SCHOOLS, 'guard_name' => 'web'])->id,
            Permission::create(['name' => AccessPermission::CREATE_SCHOOLS, 'guard_name' => 'web'])->id,
            Permission::create(['name' => AccessPermission::UPDATE_SCHOOLS, 'guard_name' => 'web'])->id,
        ]);
        $user = User::factory()->create([
            'province_code' => 'P1',
            'scope_level' => 'province',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        $this->actingAs($user)
            ->postJson('/api/v1/schools', [
                'name' => 'Trường thử nghiệm',
                'type' => 'single_level',
                'level' => 'TH',
                'province_code' => 'P1',
                'commune_code' => 'C1',
                'is_active' => false,
            ])
            ->assertCreated()
            ->assertJsonPath('data.school.is_active', false);

        $school = DB::table('schools')->where('name', 'Trường thử nghiệm')->first();

        $this->putJson("/api/v1/schools/{$school->id}", [
            'name' => 'Trường thử nghiệm',
            'type' => 'single_level',
            'level' => 'TH',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.school.is_active', true);

        $this->getJson('/api/v1/schools')
            ->assertOk()
            ->assertJsonFragment(['is_active' => true]);
    }

    private function currentSchoolYear(): string
    {
        $year = now()->month >= 8 ? now()->year : now()->year - 1;

        return "{$year}-".($year + 1);
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
        $role->attachPermissions(Permission::create([
            'name' => AccessPermission::IMPORT_HOUSEHOLDS,
            'guard_name' => 'web',
        ]));
        $user = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'commune',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('O2', 'Số phiếu');
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
        $previousSchoolYear = ($currentSchoolYearStart - 1).'-'.$currentSchoolYearStart;

        $this->actingAs($user)
            ->postJson(route('api.household-imports.store'), [
                'file' => $makeImportFile(),
                'school_year' => $this->currentSchoolYear(),
                'province_code' => 'P1',
                'commune_code' => 'C1',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'village_code');

        $this->postJson(route('api.household-imports.store'), [
            'file' => $makeImportFile(),
            'school_year' => $previousSchoolYear,
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'village_code' => 'V1',
        ])->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'school_year');

        $response = $this->postJson(route('api.household-imports.store'), [
            'file' => $makeImportFile(),
            'school_year' => $this->currentSchoolYear(),
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'village_code' => 'V1',
        ])->assertAccepted();

        $importLog = ImportLog::findOrFail($response->json('data.import_id'));
        Queue::assertPushed(ProcessHouseholdImport::class);
        $this->getJson($response->json('data.status_url'))
            ->assertOk()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.progress', 0);
        (new ProcessHouseholdImport($importLog->id))->handle(app(HouseholdImportProcessingService::class));
        $this->assertSame('FAILED', $importLog->fresh()->status);
        $this->assertNotNull($importLog->fresh()->error_file_path);
        $this->assertSame('xlsx', strtolower(pathinfo($importLog->fresh()->error_file_path, PATHINFO_EXTENSION)));
        $annotatedWorkbook = IOFactory::load(Storage::disk('local')->path($importLog->fresh()->error_file_path));
        self::assertSame('A1', $annotatedWorkbook->getActiveSheet()->getSelectedCells());
        $errorMessage = $annotatedWorkbook->getSheet(1)->getCell('A5')->getValue();
        $this->assertStringContainsString('không thuộc xã được chọn', $errorMessage);
        $this->assertStringContainsString('Ngày sinh không được ở tương lai.', $errorMessage);
        $annotatedWorkbook->disconnectWorksheets();
        $originalWorkbook = IOFactory::load(Storage::disk('local')->path($importLog->file_path));
        $this->assertEmpty($originalWorkbook->getSheet(1)->getCell('A5')->getValue());
        $originalWorkbook->disconnectWorksheets();
        $this->getJson($response->json('data.status_url'))
            ->assertOk()
            ->assertJsonPath('data.status', 'FAILED')
            ->assertJsonPath('data.progress', 100)
            ->assertJsonPath('data.has_error_file', true);
        $this->assertSame(0, DB::table('households')->count());

        $otherUser = User::factory()->create();
        ImportLog::create([
            'file_name' => 'file-khac.xls',
            'user_id' => $otherUser->id,
            'status' => 'SUCCESS',
        ]);
        $this->getJson(route('api.households.import-history'))
            ->assertOk()
            ->assertJsonFragment(['file_name' => 'import.xls'])
            ->assertJsonFragment(['uploaded_by_name' => $user->name])
            ->assertJsonMissing(['file_name' => 'file-khac.xls']);
        $this->get(route('api.household-imports.file', $importLog))
            ->assertOk()
            ->assertHeader('content-disposition');
        $this->get(route('api.household-imports.error-file', $importLog))
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=import-loi.xlsx');
        $importLog->update(['error_file_path' => null]);
        $this->get(route('api.household-imports.error-file', $importLog))
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=import-loi.xlsx');
        $this->assertNotNull($importLog->fresh()->error_file_path);
        $this->deleteJson(route('api.household-imports.destroy', $importLog))
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
            'school_year_id' => \App\Models\SchoolYear::resolveOrCreateFromLabel('2025-2026')->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $personId = DB::table('persons')->insertGetId([
            'household_id' => $householdId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('person_educations')->insert([
            'person_id' => $personId,
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
            'school_year_id' => \App\Models\SchoolYear::resolveOrCreateFromLabel('2025-2026')->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson(route('api.household-imports.destroy', $successfulImport))
            ->assertOk()
            ->assertJsonPath('message', 'Đã xóa lịch sử tải file');
        $this->assertDatabaseHas('households', ['id' => $householdId]);
        $this->assertDatabaseHas('persons', ['id' => $personId]);
        $this->assertDatabaseHas('person_educations', ['person_id' => $personId]);
        $this->assertDatabaseHas('person_disabilities', ['person_id' => $personId]);
        $this->assertDatabaseHas('households', ['id' => $otherHouseholdId]);
        $this->assertDatabaseMissing('import_logs', ['id' => $successfulImport->id]);
        $this->deleteJson(route('api.household-imports.destroy', $successfulImport))
            ->assertNotFound();
        $this->getJson(route('api.households.import-history'))
            ->assertOk()
            ->assertJsonFragment(['file_name' => 'other-successful.xls']);

        $sheet->setCellValue('O2', 'Số phiếu');
        $sheet->setCellValue('V5', 'S-UNKNOWN');
        $response = $this->postJson(route('api.household-imports.store'), [
            'file' => $makeImportFile(),
            'school_year' => $this->currentSchoolYear(),
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'village_code' => 'V1',
        ])->assertAccepted();

        $importLog = ImportLog::findOrFail($response->json('data.import_id'));
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
        $villageRole->attachPermissions($villagePermission);
        $villageManager->assignRole($villageRole, null);
        $this->assertTrue(Gate::forUser($villageManager)->allows(AccessPermission::MANAGE_VILLAGES));
        $this->assertFalse(Gate::forUser($villageManager)->allows(AccessPermission::MANAGE_SCHOOLS));
        $this->actingAs($villageManager)
            ->getJson(route('api.villages.index'))
            ->assertOk()
            ->assertJsonStructure(['data']);
        $this->getJson(route('api.schools.index'))->assertForbidden();

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
        $legacyRole->attachPermissions($legacyPermission);
        $legacyManager->assignRole($legacyRole, null);
        $this->assertFalse(Gate::forUser($legacyManager)->allows(AccessPermission::MANAGE_VILLAGES));
        $this->assertFalse(Gate::forUser($legacyManager)->allows(AccessPermission::MANAGE_SCHOOLS));
        $this->assertFalse(Gate::forUser($legacyManager)->allows('manage-catalogs'));
        $this->actingAs($legacyManager)->getJson(route('api.schools.index'))->assertForbidden();

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
        $provinceRole->attachPermissions($schoolPermission);
        $provinceManager->assignRole($provinceRole, null);
        $this->assertTrue(Gate::forUser($provinceManager)->allows(AccessPermission::MANAGE_SCHOOLS));
        $this->actingAs($provinceManager)
            ->getJson(route('api.schools.index'))
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_village_and_school_catalog_apis_are_available_to_managers(): void
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
        $villageRole->attachPermissions($villagePermission);
        $villageManager = User::factory()->create([
            'scope_level' => 'commune',
            'is_active' => true,
        ]);
        $villageManager->assignRole($villageRole, null);

        $this->actingAs($villageManager)
            ->getJson(route('api.villages.index'))
            ->assertOk()
            ->assertJsonStructure(['data']);

        $schoolPermission = Permission::create([
            'name' => AccessPermission::MANAGE_SCHOOLS,
            'guard_name' => 'web',
        ]);
        $schoolRole = Role::create([
            'name' => 'school-pagination-manager',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $schoolRole->attachPermissions($schoolPermission);
        $schoolManager = User::factory()->create([
            'scope_level' => 'province',
            'is_active' => true,
        ]);
        $schoolManager->assignRole($schoolRole, null);

        $this->actingAs($schoolManager)
            ->getJson(route('api.schools.index'))
            ->assertOk()
            ->assertJsonStructure(['data']);
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
        $admin->assignRole($adminRole);
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
        $this->actingAs($admin)->postJson(route('api.roles.store'), [
            'name' => 'Quản lý cấp tỉnh',
            'scope_level' => 'province',
            'description' => 'Quản lý trường học và tạo nhóm, tài khoản cấp dưới.',
            'permissions' => [
                $manageAccountsPermission->id,
                $manageGroupsPermission->id,
                $manageSchoolsPermission->id,
            ],
        ])->assertCreated();
        $provinceRole = Role::where('name', 'Quản lý cấp tỉnh')->firstOrFail();
        $this->assertSame('province', $provinceRole->scope_level);

        $this->postJson(route('api.roles.store'), [
            'name' => 'Nhóm cấp xã do Trung ương tạo',
            'scope_level' => 'commune',
            'permissions' => [$viewHouseholdsPermission->id],
        ])->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'scope_level');
        $unassignedCentralCommuneRole = Role::create([
            'name' => 'Nhóm cấp xã khác',
            'scope_level' => 'commune',
            'guard_name' => 'web',
        ]);
        $unassignedCentralCommuneRole->attachPermissions($viewHouseholdsPermission);

        $legacyGlobalRole = Role::create([
            'name' => 'legacy-global-viewer',
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $legacyGlobalRole->attachPermissions($viewHouseholdsPermission);

        $this->postJson(route('api.roles.store'), [
            'name' => 'Nhóm thôn do trung ương tạo',
            'scope_level' => 'commune',
            'permissions' => [$viewHouseholdsPermission->id],
        ])->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'scope_level');

        $this->actingAs($admin)->postJson(route('api.roles.store'), [
            'name' => 'Vai trò trung ương chỉ xem',
            'scope_level' => 'global',
            'permissions' => [$viewHouseholdsPermission->id],
        ])->assertCreated();
        $limitedCentralRole = Role::where('name', 'Vai trò trung ương chỉ xem')->firstOrFail();

        $this->actingAs($admin)
            ->getJson(route('api.users.create'))
            ->assertOk()
            ->assertJsonPath('data.account_levels', ['global', 'province'])
            ->assertJsonFragment(['name' => 'Quản lý cấp tỉnh'])
            ->assertJsonFragment(['name' => 'Vai trò trung ương chỉ xem'])
            ->assertJsonMissing(['name' => 'Nhóm cấp xã khác']);

        $this->postJson(route('api.users.store'), [
            'name' => 'Tài khoản trung ương giới hạn',
            'username' => 'centrallimit',
            'citizen_id' => '001111111201',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'scope_level' => 'global',
            'role_id' => $limitedCentralRole->id,
            'is_active' => '1',
        ])->assertCreated();

        $centralUser = User::where('name', 'Tài khoản trung ương giới hạn')->firstOrFail();
        $this->assertSame('global', $centralUser->roleScopeLevel());
        $this->assertFalse($centralUser->hasRole(Role::SYSTEM_ADMIN));
        $this->assertTrue($centralUser->hasPermissionTo(AccessPermission::VIEW_HOUSEHOLDS));
        $this->assertFalse($centralUser->hasPermissionTo(AccessPermission::CREATE_HOUSEHOLDS));
        $this->assertFalse($centralUser->hasPermissionTo(AccessPermission::MANAGE_USER_ACCOUNTS));
        $this->actingAs($centralUser)->getJson(route('api.users.create'))->assertForbidden();

        $this->actingAs($admin)
            ->getJson(route('api.users.index'))
            ->assertOk()
            ->assertJsonFragment(['username' => 'centrallimit'])
            ->assertJsonPath('data.can_edit_account_configurations', true);
        $this->postJson(route('api.users.password-resets.store', $centralUser), [
            'password' => 'NewSecurePass2026!',
            'password_confirmation' => 'NewSecurePass2026!',
        ])->assertOk();
        $this->assertTrue(Hash::check('NewSecurePass2026!', $centralUser->fresh()->password));
        $this->patchJson(route('api.users.status.update', $centralUser), ['is_active' => false])
            ->assertOk();
        $this->assertDatabaseHas('users', ['id' => $centralUser->id, 'is_active' => false]);

        $this->actingAs($admin)->postJson(route('api.users.store'), [
            'name' => 'Tài khoản giả quản trị',
            'username' => 'centralesc1',
            'citizen_id' => '001111111202',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'scope_level' => 'global',
            'role_id' => $adminRole->id,
            'is_active' => '1',
        ])->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'role_id');

        $this->postJson(route('api.users.store'), [
            'name' => 'Tài khoản tỉnh',
            'username' => 'provaccount',
            'citizen_id' => '001111111203',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'scope_level' => 'province',
            'role_id' => $provinceRole->id,
            'province_code' => 'P1',
            'is_active' => '1',
        ])->assertCreated();

        $this->postJson(route('api.users.store'), [
            'name' => 'Tài khoản tỉnh trùng',
            'username' => 'provdup1',
            'citizen_id' => '001111111204',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'scope_level' => 'province',
            'role_id' => $provinceRole->id,
            'province_code' => 'P1',
            'is_active' => '1',
        ])->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'province_code');

        $this->postJson(route('api.users.store'), [
            'name' => 'Tài khoản tỉnh khác',
            'username' => 'provother1',
            'citizen_id' => '001111111205',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'scope_level' => 'province',
            'role_id' => $provinceRole->id,
            'province_code' => 'P2',
            'is_active' => '1',
        ])->assertCreated();

        $this->actingAs($admin)
            ->getJson(route('api.users.create'))
            ->assertOk()
            ->assertJsonPath('data.available_primary_admin_scopes.province', false)
            ->assertJsonPath('data.account_levels', ['global', 'province']);

        $this->postJson(route('api.users.store'), [
            'name' => 'Tài khoản trung ương sau khi đủ tỉnh',
            'username' => 'centralfull',
            'citizen_id' => '001111111206',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'scope_level' => 'global',
            'role_id' => $limitedCentralRole->id,
            'is_active' => '1',
        ])->assertCreated();

        $this->postJson(route('api.users.store'), [
            'name' => 'Tài khoản tỉnh dùng nhóm cũ',
            'username' => 'provglobal1',
            'citizen_id' => '001111111207',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'scope_level' => 'province',
            'role_id' => $legacyGlobalRole->id,
            'province_code' => 'P1',
            'is_active' => '1',
        ])->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'role_id');

        $this->postJson(route('api.users.store'), [
            'name' => 'Tài khoản xã vượt cấp',
            'username' => 'commdirect1',
            'citizen_id' => '001111111208',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role_id' => $provinceRole->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => '1',
        ])->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'role_id');

        $provinceUser = User::where('username', 'provaccount')->firstOrFail();
        $this->assertSame('province', $provinceUser->roleScopeLevel());
        $this->assertTrue($provinceUser->hasPermissionTo(AccessPermission::MANAGE_SCHOOLS));
        $this->assertFalse($provinceUser->hasPermissionTo(AccessPermission::CREATE_HOUSEHOLDS));

        $this->actingAs($provinceUser)->postJson(route('api.roles.store'), [
            'name' => 'Nhóm tỉnh chỉ xem',
            'scope_level' => 'province',
            'permissions' => [$viewHouseholdsPermission->id],
        ])->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'permissions');

        $this->actingAs($provinceUser)->getJson(route('api.users.create'))
            ->assertOk()
            ->assertJsonPath('data.account_levels', ['province', 'commune']);

        $this->actingAs($provinceUser)->postJson(route('api.roles.store'), [
            'name' => 'Nhóm quản lý cấp xã',
            'scope_level' => 'commune',
            'description' => 'Quản lý phiếu, địa bàn và tài khoản cấp xã.',
            'permission_groups' => ['households'],
            'permissions' => [
                $createHouseholdsPermission->id,
                $manageAccountsPermission->id,
                $manageGroupsPermission->id,
                $manageVillagesPermission->id,
            ],
        ])->assertCreated();
        $communeRole = Role::where('name', 'Nhóm quản lý cấp xã')->firstOrFail();
        $this->assertSame($provinceUser->id, $communeRole->created_by);
        $this->assertEqualsCanonicalizing(
            [
                AccessPermission::CREATE_HOUSEHOLDS,
                AccessPermission::DELETE_HOUSEHOLDS,
                AccessPermission::EXPORT_HOUSEHOLDS,
                AccessPermission::IMPORT_HOUSEHOLDS,
                AccessPermission::MANAGE_ACCESS_CONTROL,
                AccessPermission::MANAGE_SCHOOLS,
                AccessPermission::MANAGE_USER_ACCOUNTS,
                AccessPermission::MANAGE_VILLAGES,
                AccessPermission::UPDATE_HOUSEHOLDS,
                AccessPermission::VIEW_HOUSEHOLDS,
            ],
            $communeRole->permissions->pluck('name')->all()
        );

        $this->actingAs($provinceUser)
            ->getJson(route('api.roles.index'))
            ->assertOk()
            ->assertJsonFragment(['name' => 'Nhóm quản lý cấp xã'])
            ->assertJsonMissing(['name' => 'Nhóm cấp xã khác'])
            ->assertJsonMissing(['name' => 'Quản lý cấp tỉnh']);

        $this->actingAs($admin)
            ->getJson(route('api.roles.index'))
            ->assertOk()
            ->assertJsonMissing(['name' => 'Nhóm cấp xã khác'])
            ->assertJsonMissing(['name' => 'Nhóm quản lý cấp xã']);
        $this->getJson(route('api.roles.edit', $communeRole))->assertForbidden();

        $this->actingAs($provinceUser)
            ->getJson(route('api.users.create'))
            ->assertOk()
            ->assertJsonFragment(['name' => 'Nhóm quản lý cấp xã'])
            ->assertJsonMissing(['name' => 'Nhóm cấp xã khác']);

        $otherProvinceAccount = User::factory()->create(['province_code' => 'P1']);
        $otherProvinceRole = Role::create([
            'name' => 'Nhóm xã của tài khoản tỉnh khác',
            'scope_level' => 'commune',
            'guard_name' => 'web',
            'created_by' => $otherProvinceAccount->id,
            'province_code' => 'P1',
        ]);
        $otherProvinceRole->attachPermissions($viewHouseholdsPermission);
        $this->actingAs($provinceUser)
            ->getJson(route('api.users.create'))
            ->assertJsonMissing(['name' => 'Nhóm xã của tài khoản tỉnh khác']);

        $this->actingAs($provinceUser)->postJson(route('api.users.store'), [
            'name' => 'Tài khoản xã',
            'username' => 'commuser1',
            'citizen_id' => '001111111209',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role_id' => $communeRole->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => '1',
        ])->assertCreated();

        $this->postJson(route('api.users.store'), [
            'name' => 'Tài khoản xã dùng nhóm tài khoản khác',
            'username' => 'commother1',
            'citizen_id' => '001111111210',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role_id' => $otherProvinceRole->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => '1',
        ])->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'role_id');

        $communeUser = User::where('username', 'commuser1')->firstOrFail();
        $this->assertSame('commune', $communeUser->roleScopeLevel());
        $this->assertTrue($communeUser->hasRole('Nhóm quản lý cấp xã'));
        $this->assertTrue($communeUser->hasPermissionTo(AccessPermission::MANAGE_VILLAGES));
        $this->assertTrue($communeUser->hasPermissionTo(AccessPermission::MANAGE_SCHOOLS));

        $this->actingAs($communeUser)->postJson(route('api.roles.store'), [
            'name' => 'Nhóm xã chỉ xem',
            'scope_level' => 'commune',
            'permissions' => [$viewHouseholdsPermission->id],
        ])->assertCreated();

        $this->postJson(route('api.users.store'), [
            'name' => 'Tài khoản xã trùng',
            'username' => 'commview1',
            'citizen_id' => '001111111211',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role_id' => $communeRole->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => '1',
        ])->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'role_id');

        foreach (AccessPermission::definitions() as $name => $description) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['description' => $description]
            );
        }
        $this->actingAs($communeUser)
            ->getJson(route('api.roles.create'))
            ->assertOk()
            ->assertJsonFragment(['name' => AccessPermission::CREATE_HOUSEHOLDS])
            ->assertJsonFragment(['name' => AccessPermission::VIEW_HOUSEHOLDS]);

        $this->actingAs($communeUser)
            ->getJson(route('api.roles.index'))
            ->assertOk()
            ->assertJsonFragment(['name' => 'Nhóm xã chỉ xem'])
            ->assertJsonMissing(['name' => 'Nhóm quản lý cấp xã']);

        $this->actingAs($admin)
            ->getJson(route('api.users.index'))
            ->assertOk()
            ->assertJsonMissing(['username' => 'commuser1']);
        $this->getJson(route('api.users.edit', $communeUser))->assertForbidden();

        $this->actingAs($communeUser)->postJson(route('api.roles.store'), [
            'name' => 'Nhóm cán bộ xã',
            'scope_level' => 'commune',
            'description' => 'Được xem và tạo phiếu trong các thôn được giao.',
            'permissions' => [$viewHouseholdsPermission->id, $createHouseholdsPermission->id],
        ])->assertCreated();
        $villageRole = Role::where('name', 'Nhóm cán bộ xã')->firstOrFail();
        $this->assertSame($communeUser->id, $villageRole->created_by);
        $this->actingAs($communeUser)
            ->getJson(route('api.roles.index'))
            ->assertOk()
            ->assertJsonFragment(['name' => 'Nhóm xã chỉ xem'])
            ->assertJsonFragment(['name' => 'Nhóm cán bộ xã'])
            ->assertJsonMissing(['name' => 'Nhóm quản lý cấp xã']);

        $this->actingAs($communeUser)->postJson(route('api.users.store'), [
            'name' => 'Cán bộ xã giới hạn thôn',
            'username' => 'villuser1',
            'citizen_id' => '001111111212',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role_id' => $villageRole->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'village_codes' => ['V1'],
            'is_active' => '1',
        ])->assertCreated();

        $villageUser = User::where('username', 'villuser1')->firstOrFail();
        $this->assertSame('commune', $villageUser->roleScopeLevel());
        $this->assertTrue($villageUser->hasRole('Nhóm cán bộ xã'));
        $this->assertTrue($villageUser->hasPermissionTo(AccessPermission::CREATE_HOUSEHOLDS));
        $this->assertTrue($villageUser->hasPermissionTo(AccessPermission::VIEW_HOUSEHOLDS));
        $this->assertFalse($villageUser->hasPermissionTo(AccessPermission::MANAGE_VILLAGES));
        $this->assertFalse($villageUser->hasPermissionTo(AccessPermission::MANAGE_USER_ACCOUNTS));
        $this->assertSame(['V1'], $villageUser->managedVillageCodes());
        $this->assertDatabaseHas('users', [
            'id' => $villageUser->id,
            'role_id' => $villageRole->id,
        ]);

        $this->actingAs($villageUser)
            ->getJson(route('api.households.index'))
            ->assertOk()
            ->assertJsonStructure(['data']);
        $this->getJson(route('api.households.create'))->assertOk();
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
        $managerRole->attachPermissions($manageGroupsPermission);

        $firstManager = User::factory()->create([
            'province_code' => 'P1',
            'scope_level' => 'province',
            'is_active' => true,
        ]);
        $firstManager->assignRole($managerRole);

        $secondManager = User::factory()->create([
            'province_code' => 'P2',
            'scope_level' => 'province',
            'is_active' => true,
        ]);
        $secondManager->assignRole($managerRole);

        foreach ([$firstManager, $secondManager] as $manager) {
            $this->actingAs($manager)->postJson(route('api.roles.store'), [
                'name' => 'Quản trị nhân khẩu',
                'scope_level' => 'commune',
                'permissions' => [$manageGroupsPermission->id],
            ])->assertCreated();
        }

        $this->assertDatabaseHas('roles', [
            'name' => 'Quản trị nhân khẩu',
            'created_by' => $firstManager->id,
        ]);
        $this->assertDatabaseHas('roles', [
            'name' => 'Quản trị nhân khẩu',
            'created_by' => $secondManager->id,
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
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole($adminRole);
        $provinceRole = Role::create([
            'name' => 'province-user',
            'scope_level' => 'province',
            'guard_name' => 'web',
            'created_by' => $admin->id,
        ]);
        $provinceRole->attachPermissions([
            Permission::create(['name' => AccessPermission::MANAGE_SCHOOLS, 'guard_name' => 'web'])->id,
            Permission::create(['name' => AccessPermission::MANAGE_USER_ACCOUNTS, 'guard_name' => 'web'])->id,
        ]);
        $target = User::factory()->create([
            'province_code' => 'P1',
            'scope_level' => 'province',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $target->assignRole($provinceRole);

        $this->actingAs($admin)
            ->getJson(route('api.users.index'))
            ->assertOk()
            ->assertJsonPath('data.allUsers.0.id', $target->id)
            ->assertJsonPath('data.allUsers.0.can_change_account_status', true);

        $this->patchJson(route('api.users.status.update', $target), ['is_active' => false])
            ->assertOk();
        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => false]);

        $this->patchJson(route('api.users.status.update', $target), ['is_active' => true])
            ->assertOk();
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

        $manager = User::factory()->create([
            'province_code' => 'P1',
            'scope_level' => 'province',
            'is_active' => true,
        ]);
        $managerRole = Role::create([
            'name' => 'province-account-manager',
            'scope_level' => 'province',
            'guard_name' => 'web',
        ]);
        $managerRole->attachPermissions(Permission::create([
            'name' => AccessPermission::MANAGE_USER_ACCOUNTS,
            'guard_name' => 'web',
        ]));
        $managerRole->attachPermissions(Permission::create([
            'name' => AccessPermission::VIEW_USERS,
            'guard_name' => 'web',
        ]));
        $manager->assignRole($managerRole);
        $communeRole = Role::create([
            'name' => 'commune-user',
            'scope_level' => 'commune',
            'guard_name' => 'web',
            'created_by' => $manager->id,
            'province_code' => 'P1',
        ]);
        $communeRole->attachPermissions([
            Permission::create([
                'name' => AccessPermission::CREATE_HOUSEHOLDS,
                'guard_name' => 'web',
            ])->id,
            Permission::create([
                'name' => AccessPermission::MANAGE_VILLAGES,
                'guard_name' => 'web',
            ])->id,
            Permission::create([
                'name' => AccessPermission::MANAGE_USER_ACCOUNTS,
                'guard_name' => 'web',
            ])->id,
        ]);
        $target = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'commune',
            'is_active' => true,
            'created_by' => $manager->id,
        ]);
        $target->assignRole($communeRole);
        $sameLevelAccount = User::factory()->create([
            'province_code' => 'P1',
            'scope_level' => 'province',
            'is_active' => true,
            'created_by' => $manager->id,
        ]);
        $sameLevelAccount->assignRole($managerRole);

        $this->actingAs($manager)
            ->getJson(route('api.users.index'))
            ->assertOk()
            ->assertJsonFragment([
                'id' => $target->id,
                'can_change_account_status' => true,
            ]);

        $this->getJson(route('api.users.edit', $target))
            ->assertOk()
            ->assertJsonPath('data.user.id', $target->id);

        $this->patchJson(route('api.users.status.update', $target), ['is_active' => false])
            ->assertOk();
        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => false]);
        $this->patchJson(route('api.users.status.update', $sameLevelAccount), ['is_active' => false])
            ->assertOk();
        $this->assertDatabaseHas('users', ['id' => $sameLevelAccount->id, 'is_active' => false]);
    }

    public function test_commune_account_manager_can_revoke_village_restricted_accounts_only_in_its_commune(): void
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
        $managerRole->attachPermissions($manageUsers);
        $manager = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'commune',
            'is_active' => true,
        ]);
        $manager->assignRole($managerRole);

        $villageRole = Role::create([
            'name' => 'village-user',
            'scope_level' => 'commune',
            'guard_name' => 'web',
            'created_by' => $manager->id,
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ]);
        $villageRole->attachPermissions(Permission::create([
            'name' => AccessPermission::VIEW_HOUSEHOLDS,
            'guard_name' => 'web',
        ]));
        $target = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'commune',
            'is_active' => true,
            'created_by' => $manager->id,
        ]);
        $target->assignRole($villageRole);
        $this->syncUserVillages($target, ['V1']);

        $outsideTarget = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C2',
            'scope_level' => 'commune',
            'is_active' => true,
            'created_by' => $manager->id,
        ]);
        $outsideTarget->assignRole($villageRole);
        $this->syncUserVillages($outsideTarget, ['V2']);

        $this->actingAs($manager)
            ->getJson(route('api.users.index'))
            ->assertOk()
            ->assertJsonFragment(['id' => $target->id])
            ->assertJsonMissing(['id' => $outsideTarget->id]);

        $this->patchJson(route('api.users.status.update', $target), ['is_active' => false])
            ->assertOk();
        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => false]);
        $this->patchJson(route('api.users.status.update', $outsideTarget), ['is_active' => false])
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
        $admin->assignRole($adminRole);

        $target = User::factory()->create([
            'username' => 'unassigned-account',
            'province_code' => null,
            'commune_code' => null,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $provinceRole = Role::create([
            'name' => 'province-officer',
            'scope_level' => 'province',
            'guard_name' => 'web',
            'created_by' => $admin->id,
        ]);
        $provinceRole->attachPermissions([
            Permission::create(['name' => AccessPermission::MANAGE_SCHOOLS, 'guard_name' => 'web'])->id,
            Permission::create(['name' => AccessPermission::MANAGE_USER_ACCOUNTS, 'guard_name' => 'web'])->id,
        ]);

        $this->actingAs($admin)
            ->getJson(route('api.users.index'))
            ->assertOk()
            ->assertJsonFragment(['username' => 'unassigned-account']);
        $this->getJson(route('api.users.edit', $target))->assertOk();
        $this->putJson(route('api.users.update', $target), [
            'name' => $target->name,
            'username' => $target->username,
            'citizen_id' => $target->citizen_id,
            'password' => '',
            'password_confirmation' => '',
            'role_id' => $provinceRole->id,
            'scope_level' => 'province',
            'province_code' => 'P1',
            'is_active' => '1',
        ])->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'province_code' => 'P1',
            'commune_code' => null,
        ]);
        $this->assertTrue($target->fresh()->hasRole('province-officer'));
    }

    public function test_household_access_is_limited_to_assigned_commune(): void
    {
        Schema::table('persons', function (Blueprint $table): void {
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('gender')->nullable();
            $table->string('ethnicity_code')->nullable();
            $table->string('relationship_with_head')->nullable();
        });
        Schema::create('ethnicities', function (Blueprint $table): void {
            $table->string('code')->primary();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('person_disabilities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('person_id');
            $table->timestamps();
        });

        DB::table('provinces')->insert([
            ['code' => 'P1', 'name' => 'Tỉnh một', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'P2', 'name' => 'Tỉnh hai', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('communes')->insert([
            ['code' => 'C1', 'name' => 'Xã một', 'province_code' => 'P1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'C2', 'name' => 'Xã hai', 'province_code' => 'P2', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('households')->insert([
            ['household_code' => '01', 'school_year_id' => \App\Models\SchoolYear::resolveOrCreateFromLabel('2026-2027')->id, 'province_code' => 'P1', 'commune_code' => 'C1', 'head_first_name' => 'Trong vùng', 'created_at' => now(), 'updated_at' => now()],
            ['household_code' => '02', 'school_year_id' => \App\Models\SchoolYear::resolveOrCreateFromLabel('2026-2027')->id, 'province_code' => 'P2', 'commune_code' => 'C2', 'head_first_name' => 'Ngoài vùng', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('persons')->insert(array_map(
            fn (int $index): array => [
                'household_id' => 1,
                'first_name' => 'Tên '.$index,
                'last_name' => 'Họ',
                'relationship_with_head' => $index === 1 ? 'Chủ hộ' : 'Con',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            range(1, 17),
        ));

        $role = Role::create(['name' => 'commune-viewer', 'scope_level' => 'commune', 'guard_name' => 'web']);
        $permission = Permission::create([
            'name' => AccessPermission::VIEW_HOUSEHOLDS,
            'guard_name' => 'web',
        ]);
        $role->attachPermissions($permission);
        $user = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'commune',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        $visibleCodes = DataAccessScope::households(Household::query(), $user)
            ->orderBy('household_code')
            ->pluck('household_code')
            ->all();

        $this->assertSame(['01'], $visibleCodes);
        $this->actingAs($user)->getJson(route('api.households.index'))
            ->assertOk()
            ->assertJsonFragment(['household_code' => '01'])
            ->assertJsonMissing(['household_code' => '02']);
        $this->getJson(route('api.households.show', 1))
            ->assertOk()
            ->assertJsonPath('data.household_code', '01')
            ->assertJsonCount(0, 'data.persons');
        $this->getJson(route('api.households.persons.index', 1).'?page=1&per_page=15')
            ->assertOk()
            ->assertJsonPath('meta.total', 17)
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('data.0.relationship_with_head', 'Chủ hộ');
        $this->getJson(route('api.households.persons.index', 1).'?page=2&per_page=15')
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $this->actingAs($user)->getJson(route('api.households.show', 2))->assertNotFound();
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
            ? now()->year.'-'.(now()->year + 1)
            : (now()->year - 1).'-'.now()->year;
        $previousSchoolYear = ((int) explode('-', $currentSchoolYear)[0] - 1).'-'.explode('-', $currentSchoolYear)[0];
        $currentSchoolYearId = \App\Models\SchoolYear::resolveOrCreateFromLabel($currentSchoolYear)->id;
        $previousSchoolYearId = \App\Models\SchoolYear::resolveOrCreateFromLabel($previousSchoolYear)->id;
        DB::table('households')->insert([
            ['household_code' => '01', 'school_year_id' => $currentSchoolYearId, 'province_code' => 'P1', 'commune_code' => 'C1', 'village_code' => 'V1', 'created_at' => now(), 'updated_at' => now()],
            ['household_code' => '02', 'school_year_id' => $previousSchoolYearId, 'province_code' => 'P1', 'commune_code' => 'C1', 'village_code' => 'V1', 'created_at' => now(), 'updated_at' => now()],
            ['household_code' => '03', 'school_year_id' => $currentSchoolYearId, 'province_code' => 'P1', 'commune_code' => 'C1', 'village_code' => 'V2', 'created_at' => now(), 'updated_at' => now()],
            ...array_map(
                static fn (int $code): array => [
                    'household_code' => sprintf('%02d', $code),
                    'school_year_id' => $currentSchoolYearId,
                    'province_code' => 'P1',
                    'commune_code' => 'C1',
                    'village_code' => 'V1',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                range(4, 18)
            ),
        ]);

        $role = Role::create(['name' => 'commune-current-year-viewer', 'scope_level' => 'commune', 'guard_name' => 'web']);
        $viewPermission = Permission::create([
            'name' => AccessPermission::VIEW_HOUSEHOLDS,
            'guard_name' => 'web',
        ]);
        $role->attachPermissions($viewPermission);
        $user = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => true,
        ]);
        $user->assignRole($role);
        $this->syncUserVillages($user, ['V1']);

        $firstPage = $this->actingAs($user)
            ->getJson(route('api.households.index'))
            ->assertOk()
            ->assertJsonFragment(['household_code' => '01'])
            ->assertJsonMissing(['household_code' => '02'])
            ->assertJsonMissing(['household_code' => '03'])
            ->assertJsonPath('data.0.school_year', $currentSchoolYear)
            ->assertJsonPath('data.0.province_name', 'Tỉnh một');

        $secondPage = $this->getJson(route('api.households.index', ['page' => 2]))
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2);
        $this->assertNotSame(
            $firstPage->json('data.0.id'),
            $secondPage->json('data.0.id')
        );

        $this->getJson(route('api.households.index', ['school_year' => 'all']))
            ->assertOk()
            ->assertJsonFragment(['household_code' => '01'])
            ->assertJsonFragment(['household_code' => '02'])
            ->assertJsonMissing(['household_code' => '03']);
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
            ? now()->year.'-'.(now()->year + 1)
            : (now()->year - 1).'-'.now()->year;
        $schoolYearId = \App\Models\SchoolYear::resolveOrCreateFromLabel($schoolYear)->id;
        DB::table('households')->insert([
            ['household_code' => '11', 'school_year_id' => $schoolYearId, 'province_code' => 'P1', 'commune_code' => 'C1', 'village_code' => 'V1', 'created_at' => now(), 'updated_at' => now()],
            ['household_code' => '12', 'school_year_id' => $schoolYearId, 'province_code' => 'P1', 'commune_code' => 'C1', 'village_code' => 'V2', 'created_at' => now(), 'updated_at' => now()],
            ['household_code' => '13', 'school_year_id' => $schoolYearId, 'province_code' => 'P1', 'commune_code' => 'C1', 'village_code' => 'V3', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $role = Role::create(['name' => 'multi-village-default-viewer', 'scope_level' => 'commune', 'guard_name' => 'web']);
        $role->attachPermissions(Permission::create([
            'name' => AccessPermission::VIEW_HOUSEHOLDS,
            'guard_name' => 'web',
        ]));
        $user = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => true,
        ]);
        $user->assignRole($role);
        $this->syncUserVillages($user, ['V1', 'V2']);

        $this->actingAs($user)
            ->getJson(route('api.households.index'))
            ->assertOk()
            ->assertJsonFragment(['household_code' => '11'])
            ->assertJsonFragment(['household_code' => '12'])
            ->assertJsonMissing(['household_code' => '13']);
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
            ['household_code' => '21', 'school_year_id' => \App\Models\SchoolYear::resolveOrCreateFromLabel('2026-2027')->id, 'province_code' => 'P1', 'commune_code' => 'C1', 'village_code' => 'V1', 'created_at' => now(), 'updated_at' => now()],
            ['household_code' => '22', 'school_year_id' => \App\Models\SchoolYear::resolveOrCreateFromLabel('2026-2027')->id, 'province_code' => 'P1', 'commune_code' => 'C1', 'village_code' => 'V2', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $role = Role::create(['name' => 'commune-filter-viewer', 'scope_level' => 'commune', 'guard_name' => 'web']);
        $role->attachPermissions(Permission::create([
            'name' => AccessPermission::VIEW_HOUSEHOLDS,
            'guard_name' => 'web',
        ]));
        $user = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        $this->actingAs($user)
            ->getJson(route('api.households.index', [
                'school_year' => '2026-2027',
                'province_code' => 'P1',
                'commune_code' => 'C1',
                'village_code' => '',
            ]))
            ->assertOk()
            ->assertJsonFragment(['household_code' => '21'])
            ->assertJsonFragment(['household_code' => '22']);
    }

    public function test_household_pagination_uses_previous_next_controls_and_a_page_selector(): void
    {
        $role = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        $now = now();
        $schoolYearId = \App\Models\SchoolYear::resolveOrCreateFromLabel('2026-2027')->id;

        for ($page = 1; $page <= 255; $page++) {
            DB::table('households')->insert([
                'household_code' => str_pad((string) $page, 3, '0', STR_PAD_LEFT),
                'school_year_id' => $schoolYearId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->actingAs($user)
            ->getJson(route('api.households.index', ['school_year' => 'all', 'page' => 1]))
            ->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 7)
            ->assertJsonPath('meta.total', 255)
            ->assertJsonFragment(['household_code' => '007'])
            ->assertJsonMissing(['household_code' => '008']);

        $this->actingAs($user)
            ->getJson(route('api.households.index', ['school_year' => 'all', 'page' => 2]))
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonFragment(['household_code' => '008'])
            ->assertJsonMissing(['household_code' => '007']);

        $this->actingAs($user)
            ->getJson(route('api.households.index', ['school_year' => 'all', 'page' => 3]))
            ->assertOk()
            ->assertJsonPath('meta.current_page', 3)
            ->assertJsonCount(7, 'data');
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
        $schoolYearId = \App\Models\SchoolYear::resolveOrCreateFromLabel('2026-2027')->id;
        DB::table('households')->insert([
            ['household_code' => '01', 'school_year_id' => $schoolYearId, 'province_code' => 'P1', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
            ['household_code' => '02', 'school_year_id' => $schoolYearId, 'province_code' => 'P1', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
            ['household_code' => '03', 'school_year_id' => $schoolYearId, 'province_code' => 'P1', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
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
        $user->assignRole($role);

        $visibleCodes = DataAccessScope::households(Household::query(), $user)
            ->orderBy('household_code')
            ->pluck('household_code')
            ->all();

        $this->assertSame(['01', '02', '03'], $visibleCodes);
    }

    public function test_commune_account_can_access_only_its_assigned_villages(): void
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
        $schoolYearId = \App\Models\SchoolYear::resolveOrCreateFromLabel('2026-2027')->id;
        foreach (['V1', 'V2', 'V3'] as $index => $villageCode) {
            DB::table('households')->insert([
                'household_code' => sprintf('%02d', $index + 1),
                'school_year_id' => $schoolYearId,
                'province_code' => 'P1',
                'commune_code' => 'C1',
                'village_code' => $villageCode,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $role = Role::create(['name' => 'multiple-village-officer', 'scope_level' => 'commune', 'guard_name' => 'web']);
        $role->attachPermissions(Permission::create([
            'name' => AccessPermission::VIEW_HOUSEHOLDS,
            'guard_name' => 'web',
        ]));
        $user = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => true,
        ]);
        $user->assignRole($role);
        $this->syncUserVillages($user, ['V1', 'V2']);

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
        $managerRole->syncPermissions([$accountPermission->id, $viewPermission->id]);
        $manager = User::factory()->create([
            'is_active' => true,
        ]);
        $manager->forceFill([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'commune',
        ])->save();
        $manager->assignRole($managerRole);
        $villageRole = Role::create([
            'name' => 'village-reader',
            'scope_level' => 'commune',
            'guard_name' => 'web',
            'created_by' => $manager->id,
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ]);
        $villageRole->attachPermissions($viewPermission);

        $this->actingAs($manager)->postJson(route('api.users.store'), [
            'name' => 'Quản lý nhiều thôn',
            'username' => 'multivillage',
            'citizen_id' => '001111111213',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role_id' => $villageRole->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'village_codes' => ['V1', 'V2'],
            'is_active' => '1',
        ])->assertCreated();

        $villageUser = User::where('username', 'multivillage')->firstOrFail();
        $this->assertEqualsCanonicalizing(['V1', 'V2'], $villageUser->managedVillageCodes());
        $this->assertDatabaseHas('user_operating_areas', ['user_id' => $villageUser->id, 'village_code' => 'V1']);
        $this->assertDatabaseHas('user_operating_areas', ['user_id' => $villageUser->id, 'village_code' => 'V2']);

        $this->postJson(route('api.users.store'), [
            'name' => 'Ngoài xã',
            'username' => 'outside1',
            'citizen_id' => '001111111214',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role_id' => $villageRole->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'village_codes' => ['V1', 'V3'],
            'is_active' => '1',
        ])->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'village_codes.1');
    }

    public function test_village_management_permission_grants_all_commune_villages_without_selection(): void
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

        $permissions = collect([
            AccessPermission::MANAGE_USER_ACCOUNTS,
            AccessPermission::MANAGE_VILLAGES,
        ])->mapWithKeys(fn (string $name): array => [
            $name => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']),
        ]);
        $managerRole = Role::create([
            'name' => 'Quản trị thôn kiểm thử',
            'scope_level' => 'commune',
            'guard_name' => 'web',
        ]);
        $managerRole->syncPermissions($permissions->pluck('id')->all());
        $manager = User::factory()->create([
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => true,
        ]);
        $manager->assignRole($managerRole);
        $this->syncUserVillages($manager, ['V1']);

        $staffRole = Role::create([
            'name' => 'Nhóm quản lý toàn bộ thôn xã',
            'scope_level' => 'commune',
            'guard_name' => 'web',
            'created_by' => $manager->id,
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ]);
        $staffRole->attachPermissions($permissions[AccessPermission::MANAGE_VILLAGES]);

        $this->actingAs($manager, 'web')
            ->getJson('/api/v1/users/create')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $staffRole->id,
                'manages_all_villages' => true,
                'uses_village_scope' => false,
            ]);
        self::assertSame([], $manager->managedVillageCodes());

        $this->postJson('/api/v1/users', [
            'name' => 'Cán bộ quản lý toàn bộ thôn',
            'username' => 'villageman1',
            'citizen_id' => '123456789012',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'role_id' => $staffRole->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'village_codes' => ['V1'],
        ])->assertCreated()
            ->assertJsonPath('data.user.username', 'villageman1')
            ->assertJsonPath('data.user.role_name', 'Nhóm quản lý toàn bộ thôn xã')
            ->assertJsonPath('data.user.scope_level', 'commune');

        $staff = User::where('username', 'villageman1')->firstOrFail();
        self::assertSame([], $staff->managedVillageCodes());
        self::assertSame(
            0,
            DB::table('user_operating_areas')
                ->where('user_id', $staff->id)
                ->whereNotNull('village_code')
                ->count(),
        );
        $householdInAnotherVillage = new Household;
        $householdInAnotherVillage->province_code = 'P1';
        $householdInAnotherVillage->commune_code = 'C1';
        $householdInAnotherVillage->village_code = 'V2';
        self::assertTrue(DataAccessScope::canAccessHousehold($staff, $householdInAnotherVillage));

        $this->actingAs($staff, 'web')
            ->getJson('/api/v1/villages/form')
            ->assertOk();

        $accountViewerRole = Role::create([
            'name' => 'Nhóm chỉ xem tài khoản',
            'scope_level' => 'commune',
            'guard_name' => 'web',
            'created_by' => $manager->id,
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ]);
        $accountViewerRole->attachPermissions(Permission::firstOrCreate([
            'name' => AccessPermission::VIEW_USERS,
            'guard_name' => 'web',
        ]));

        $this->actingAs($manager, 'web')
            ->getJson('/api/v1/users/create')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $accountViewerRole->id,
                'uses_village_scope' => false,
            ]);

        $this->postJson('/api/v1/users', [
            'name' => 'Tài khoản toàn xã',
            'username' => 'commviewer1',
            'citizen_id' => '123456789013',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'role_id' => $accountViewerRole->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'village_codes' => ['V1'],
        ])->assertCreated();

        $accountViewer = User::where('username', 'commviewer1')->firstOrFail();
        self::assertSame([], $accountViewer->managedVillageCodes());
        self::assertSame(
            0,
            DB::table('user_operating_areas')
                ->where('user_id', $accountViewer->id)
                ->whereNotNull('village_code')
                ->count(),
        );
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
        $managerRole->attachPermissions($permission);
        $manager = User::factory()->create(['is_active' => true]);
        $manager->assignRole($managerRole);

        $delegatedRole = Role::create([
            'name' => 'delegated-viewer',
            'scope_level' => 'commune',
            'guard_name' => 'web',
            'created_by' => $manager->id,
        ]);
        $delegatedRole->attachPermissions($permission);
        $villageUser = User::factory()->create(['is_active' => true]);
        $villageUser->assignRole($delegatedRole, $manager->id);
        $assignedRoleUser = User::factory()->create(['scope_level' => 'commune', 'is_active' => true]);
        $assignedRoleUser->assignRole($delegatedRole, $manager->id);

        $this->assertTrue($manager->hasPermissionTo(AccessPermission::VIEW_HOUSEHOLDS));
        $this->assertTrue($villageUser->hasPermissionTo(AccessPermission::VIEW_HOUSEHOLDS));
        $this->assertTrue($assignedRoleUser->hasPermissionTo(AccessPermission::VIEW_HOUSEHOLDS));

        $managerRole->detachPermissions($permission);

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
        $admin->assignRole($adminRole);

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
        $provinceAccount->assignRole($provinceRole);

        $villageRole = Role::create([
            'name' => 'Giáo viên nhập liệu',
            'scope_level' => 'commune',
            'guard_name' => 'web',
        ]);
        $villageAccount = User::factory()->create([
            'name' => 'Tài khoản thôn một',
            'username' => 'thonmot',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'commune',
            'is_active' => false,
        ]);
        $villageAccount->assignRole($villageRole);
        $this->syncUserVillages($villageAccount, ['V1']);

        $this->actingAs($admin)
            ->get('/admin/organization-accounts')
            ->assertNotFound();

        $this->getJson('/api/v1/provinces?include_account_counts=1')
            ->assertOk()
            ->assertJsonFragment(['code' => 'P1', 'name' => 'Tỉnh một', 'account_count' => 2]);

        $this->getJson('/api/v1/provinces/P1/communes?include_account_counts=1')
            ->assertOk()
            ->assertJsonFragment(['code' => 'C1', 'name' => 'Xã một', 'account_count' => 1]);

        $this->getJson('/api/v1/communes/C1/villages?include_account_counts=1')
            ->assertOk()
            ->assertJsonFragment(['code' => 'V1', 'name' => 'Thôn một', 'account_count' => 1])
            ->assertJsonFragment(['code' => 'V2', 'name' => 'Thôn hai', 'account_count' => 0]);
    }

    public function test_commune_admin_creates_staff_roles_and_limits_commune_accounts_to_selected_villages(): void
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
        DB::table('communes')->insert([
            'code' => 'C2',
            'name' => 'Xã hai',
            'province_code' => 'P1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('villages')->insert([
            ['code' => 'V1', 'name' => 'Thôn một', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'V2', 'name' => 'Thôn hai', 'commune_code' => 'C1', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'V3', 'name' => 'Thôn khác xã', 'commune_code' => 'C2', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $parent = User::factory()->create(['scope_level' => 'province', 'province_code' => 'P1']);
        $commune = User::factory()->create([
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'is_active' => true,
        ]);

        $permissionIds = collect([
            AccessPermission::MANAGE_USER_ACCOUNTS,
            AccessPermission::MANAGE_ACCESS_CONTROL,
            AccessPermission::MANAGE_VILLAGES,
            AccessPermission::VIEW_HOUSEHOLDS,
        ])->mapWithKeys(function (string $name): array {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);

            return [$name => $permission->id];
        });

        $parentRole = Role::create([
            'name' => 'Quản trị cấp tỉnh P1',
            'scope_level' => 'province',
            'guard_name' => 'web',
            'province_code' => 'P1',
        ]);
        $parentRole->syncPermissions([
            $permissionIds[AccessPermission::MANAGE_USER_ACCOUNTS],
            $permissionIds[AccessPermission::MANAGE_ACCESS_CONTROL],
        ]);
        $parent->assignRole($parentRole);

        $communeAdminRole = Role::create([
            'name' => 'Quản trị xã C1',
            'scope_level' => 'commune',
            'guard_name' => 'web',
            'created_by' => $parent->id,
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ]);
        $communeAdminRole->syncPermissions($permissionIds->values()->all());
        $commune->assignRole($communeAdminRole);

        $provinceRole = Role::create([
            'name' => 'Nhóm xã dùng chung tỉnh P1',
            'scope_level' => 'commune',
            'guard_name' => 'web',
            'created_by' => $parent->id,
            'province_code' => 'P1',
        ]);
        $provinceRole->attachPermissions($permissionIds[AccessPermission::VIEW_HOUSEHOLDS]);

        $staffRole = Role::create([
            'name' => 'Cán bộ nhập liệu',
            'scope_level' => 'commune',
            'guard_name' => 'web',
            'created_by' => $commune->id,
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ]);
        $staffRole->attachPermissions($permissionIds[AccessPermission::VIEW_HOUSEHOLDS]);
        $staffAdminRole = Role::create([
            'name' => 'Nhóm quản trị xã do cán bộ xã tạo',
            'scope_level' => 'commune',
            'guard_name' => 'web',
            'created_by' => $commune->id,
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ]);
        $staffAdminRole->attachPermissions([
            $permissionIds[AccessPermission::MANAGE_USER_ACCOUNTS],
            $permissionIds[AccessPermission::MANAGE_VILLAGES],
        ]);
        $roleWithPermissionBeyondActor = Role::create([
            'name' => 'Nhóm vượt quyền người tạo',
            'scope_level' => 'commune',
            'guard_name' => 'web',
            'created_by' => $commune->id,
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ]);
        $createHouseholdsPermission = Permission::firstOrCreate([
            'name' => AccessPermission::CREATE_HOUSEHOLDS,
            'guard_name' => 'web',
        ]);
        $roleWithPermissionBeyondActor->attachPermissions($createHouseholdsPermission);

        $unassignedCommuneUser = User::factory()->create([
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ]);

        $this->actingAs($commune)
            ->getJson('/api/v1/users/create')
            ->assertOk()
            ->assertJsonPath('data.account_levels', ['commune'])
            ->assertJsonFragment([
                'id' => $staffRole->id,
                'name' => 'Cán bộ nhập liệu',
                'scope_level' => 'commune',
                'assignable_scope_levels' => ['commune'],
                'uses_village_scope' => true,
            ])
            ->assertJsonFragment([
                'id' => $staffAdminRole->id,
                'name' => 'Nhóm quản trị xã do cán bộ xã tạo',
                'scope_level' => 'commune',
                'assignable_scope_levels' => ['commune'],
                'is_primary_admin' => false,
            ])
            ->assertJsonPath('data.available_primary_admin_scopes.commune', false)
            ->assertJsonMissing(['name' => 'Nhóm vượt quyền người tạo'])
            ->assertJsonMissing(['name' => 'Quản trị xã C1'])
            ->assertJsonMissing(['name' => 'Nhóm xã dùng chung tỉnh P1']);

        $this->getJson('/api/v1/roles')
            ->assertOk()
            ->assertJsonFragment(['id' => $staffRole->id, 'name' => 'Cán bộ nhập liệu'])
            ->assertJsonMissing(['name' => 'Quản trị xã C1'])
            ->assertJsonMissing(['name' => 'Nhóm xã dùng chung tỉnh P1']);

        $this->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonMissing([
                'username' => $unassignedCommuneUser->username,
                'role_name' => 'Chưa được gán nhóm',
            ]);

        $this->postJson('/api/v1/users', [
            'name' => 'Cán bộ xã',
            'username' => 'canboc1',
            'citizen_id' => '123456789012',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'role_id' => $staffRole->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'village_codes' => ['V1', 'V2'],
        ])->assertCreated()
            ->assertJsonPath('data.user.can_view_details', true)
            ->assertJsonPath('data.user.can_edit_configuration', true);

        $staff = User::where('username', 'canboc1')->firstOrFail();
        self::assertSame('commune', $staff->scope_level);
        self::assertEqualsCanonicalizing(['V1', 'V2'], $staff->managedVillageCodes());
        $schoolYearId = \App\Models\SchoolYear::resolveOrCreateFromLabel('2025-2026')->id;
        DB::table('households')->insert([
            [
                'household_code' => 'H1',
                'school_year_id' => $schoolYearId,
                'province_code' => 'P1',
                'commune_code' => 'C1',
                'village_code' => 'V1',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'household_code' => 'H4',
                'school_year_id' => $schoolYearId,
                'province_code' => 'P1',
                'commune_code' => 'C1',
                'village_code' => 'V4',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        self::assertSame(
            ['H1'],
            DataAccessScope::households(Household::query(), $staff)->orderBy('household_code')->pluck('household_code')->all(),
        );
        self::assertSame(
            ['H1', 'H4'],
            DataAccessScope::households(Household::query(), $commune)->orderBy('household_code')->pluck('household_code')->all(),
        );
        $insideVillage = new Household;
        $insideVillage->province_code = 'P1';
        $insideVillage->commune_code = 'C1';
        $insideVillage->village_code = 'V1';
        $outsideVillage = new Household;
        $outsideVillage->province_code = 'P1';
        $outsideVillage->commune_code = 'C1';
        $outsideVillage->village_code = 'V4';
        self::assertTrue(DataAccessScope::canAccessHousehold($staff, $insideVillage));
        self::assertFalse(DataAccessScope::canAccessHousehold($staff, $outsideVillage));

        $this->postJson('/api/v1/users', [
            'name' => 'Tài khoản thôn khác xã',
            'username' => 'canboc2',
            'citizen_id' => '987654321098',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'role_id' => $staffRole->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'village_codes' => ['V3'],
        ])->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'village_codes.0');

        $this->postJson('/api/v1/users', [
            'name' => 'Tài khoản cấp thôn không hợp lệ',
            'username' => 'village-scope-not-supported',
            'citizen_id' => '123456789012',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'role_id' => $staffRole->id,
            'scope_level' => 'village',
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'village_codes' => ['V1'],
        ])->assertUnprocessable()
            ->assertJsonFragment(['field' => 'scope_level']);
    }

    public function test_legacy_village_scopes_migrate_to_commune_without_losing_assigned_villages(): void
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
            'name' => 'Tài khoản thôn cũ',
            'scope_level' => 'village',
            'guard_name' => 'web',
        ]);
        $role->attachPermissions(Permission::create([
            'name' => AccessPermission::VIEW_HOUSEHOLDS,
            'guard_name' => 'web',
        ]));
        $user = User::factory()->create([
            'province_code' => 'P1',
            'commune_code' => 'C1',
            'scope_level' => 'village',
        ]);
        $user->assignRole($role);
        $this->syncUserVillages($user, ['V1']);

        $migration = require database_path('migrations/2026_11_03_000000_migrate_village_scopes_to_commune.php');
        $migration->up();

        $this->assertDatabaseHas('roles', ['id' => $role->id, 'scope_level' => 'commune']);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'scope_level' => 'commune']);
        $this->assertSame(['V1'], $user->fresh()->managedVillageCodes());
    }

    public function test_creator_ownership_backfill_uses_existing_assigner_for_legacy_data(): void
    {
        $systemRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $systemAdministrator = User::factory()->create();
        $systemAdministrator->assignRole($systemRole);

        $legacyAccount = User::factory()->create(['created_by' => null]);
        $legacyRole = Role::create([
            'name' => 'Nhóm quyền chưa có người tạo',
            'scope_level' => 'province',
            'guard_name' => 'web',
            'created_by' => null,
        ]);
        $legacyAccount->assignRole($legacyRole, $systemAdministrator->id);
        $this->syncLegacyRoleAssignment($systemAdministrator);
        $this->syncLegacyRoleAssignment($legacyAccount);

        $migration = require database_path('migrations/2026_11_04_000000_backfill_access_control_creator_ownership.php');
        $migration->up();

        $this->assertDatabaseHas('users', [
            'id' => $legacyAccount->id,
            'created_by' => $systemAdministrator->id,
        ]);
        $this->assertDatabaseHas('roles', [
            'id' => $legacyRole->id,
            'created_by' => $systemAdministrator->id,
        ]);
        $this->assertDatabaseHas('roles', [
            'id' => $systemRole->id,
            'created_by' => null,
        ]);
    }

    public function test_territorial_role_seed_repair_keeps_members_in_area_and_corrects_creator(): void
    {
        $systemRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $systemAdministrator = User::factory()->create();
        $systemAdministrator->assignRole($systemRole);

        $provinceManager = User::factory()->create([
            'scope_level' => 'province',
            'province_code' => 'P1',
        ]);
        $communeManager = User::factory()->create([
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ]);
        $provinceRole = Role::create([
            'name' => 'Nhóm cấp xã do tỉnh tạo',
            'scope_level' => 'commune',
            'guard_name' => 'web',
            'created_by' => $provinceManager->id,
        ]);
        DB::table('roles')->where('id', $provinceRole->id)->update([
            'province_code' => 'P1',
            'commune_code' => null,
        ]);
        $communeRole = Role::create([
            'name' => 'Nhóm cán bộ do xã tạo',
            'scope_level' => 'commune',
            'guard_name' => 'web',
            'created_by' => $communeManager->id,
        ]);
        DB::table('roles')->where('id', $communeRole->id)->update([
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ]);

        $provinceMember = User::factory()->create([
            'created_by' => $systemAdministrator->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C2',
        ]);
        $provinceMember->assignRole($provinceRole, $systemAdministrator->id);
        $outsideProvinceMember = User::factory()->create([
            'created_by' => $systemAdministrator->id,
            'scope_level' => 'commune',
            'province_code' => 'P2',
            'commune_code' => 'C3',
        ]);
        $outsideProvinceMember->assignRole($provinceRole, $systemAdministrator->id);

        $communeMember = User::factory()->create([
            'created_by' => $systemAdministrator->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C1',
        ]);
        $communeMember->assignRole($communeRole);
        $outsideCommuneMember = User::factory()->create([
            'created_by' => $systemAdministrator->id,
            'scope_level' => 'commune',
            'province_code' => 'P1',
            'commune_code' => 'C2',
        ]);
        $outsideCommuneMember->assignRole($communeRole, $systemAdministrator->id);
        foreach ([
            $systemAdministrator,
            $provinceMember,
            $outsideProvinceMember,
            $communeMember,
            $outsideCommuneMember,
        ] as $member) {
            $this->syncLegacyRoleAssignment($member);
        }
        foreach ([
            $systemAdministrator,
            $provinceMember,
            $outsideProvinceMember,
            $communeMember,
            $outsideCommuneMember,
        ] as $member) {
            $this->syncLegacyRoleAssignment($member);
        }

        $migration = require database_path('migrations/2026_11_05_000000_repair_territorial_role_seed_ownership.php');
        $migration->up();

        $this->assertDatabaseHas('users', [
            'id' => $provinceMember->id,
            'created_by' => $provinceManager->id,
        ]);
        $this->assertDatabaseHas('model_has_roles', [
            'model_type' => User::class,
            'model_id' => $provinceMember->id,
            'role_id' => $provinceRole->id,
            'assigned_by' => $provinceManager->id,
        ]);
        $this->assertDatabaseMissing('model_has_roles', [
            'model_type' => User::class,
            'model_id' => $outsideProvinceMember->id,
            'role_id' => $provinceRole->id,
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $communeMember->id,
            'created_by' => $communeManager->id,
        ]);
        $this->assertDatabaseHas('model_has_roles', [
            'model_type' => User::class,
            'model_id' => $communeMember->id,
            'role_id' => $communeRole->id,
            'assigned_by' => $communeManager->id,
        ]);
        $this->assertDatabaseMissing('model_has_roles', [
            'model_type' => User::class,
            'model_id' => $outsideCommuneMember->id,
            'role_id' => $communeRole->id,
        ]);
    }
}
