<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\ImportLog;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Jobs\ProcessHouseholdImport;
use App\Support\AccessPermission;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HouseholdCrudFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
        $this->seedLocations();
    }

    public function test_commune_user_can_create_a_household_and_the_code_is_assigned_by_school_year(): void
    {
        $user = $this->userWithPermissions([
            AccessPermission::CREATE_HOUSEHOLDS,
        ]);

        $this->actingAs($user)
            ->post(route('households.store'), [
                'school_year' => $this->currentSchoolYear(),
                'head_first_name' => 'An',
                'head_last_name' => 'Nguyễn',
                'province_code' => 'P01',
                'commune_code' => 'C01',
                'village_code' => 'V01',
                'residence_type' => 'THUONG_TRU',
                'address' => 'Số 1',
            ])
            ->assertRedirect(route('households.members.create', [
                Household::query()->first(),
                'is_head' => 1,
            ]))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('households', [
            'household_code' => '01',
            'school_year' => $this->currentSchoolYear(),
            'province_code' => 'P01',
            'commune_code' => 'C01',
            'village_code' => 'V01',
            'head_first_name' => 'An',
        ]);
    }

    public function test_household_creation_rejects_a_commune_outside_the_users_area(): void
    {
        $user = $this->userWithPermissions([AccessPermission::CREATE_HOUSEHOLDS]);

        $this->actingAs($user)
            ->from(route('households.create'))
            ->post(route('households.store'), [
                'school_year' => $this->currentSchoolYear(),
                'head_first_name' => 'An',
                'head_last_name' => 'Nguyễn',
                'province_code' => 'P01',
                'commune_code' => 'C02',
                'village_code' => 'V02',
                'residence_type' => 'THUONG_TRU',
            ])
            ->assertRedirect(route('households.create'))
            ->assertSessionHasErrors('commune_code');

        $this->assertDatabaseCount('households', 0);
    }

    public function test_read_only_permission_cannot_open_household_creation_route(): void
    {
        $user = $this->userWithPermissions([AccessPermission::VIEW_HOUSEHOLDS]);

        $this->actingAs($user)
            ->get(route('households.create'))
            ->assertForbidden();
    }

    public function test_household_update_changes_head_fields_but_preserves_school_year(): void
    {
        $user = $this->userWithPermissions([AccessPermission::UPDATE_HOUSEHOLDS]);
        $household = $this->createHousehold(['school_year' => '2025-2026']);

        $this->actingAs($user)
            ->put(route('households.update', $household), [
                'household_code' => $household->household_code,
                'school_year' => $this->currentSchoolYear(),
                'head_first_name' => 'Bình',
                'head_last_name' => 'Trần',
                'province_code' => 'P01',
                'commune_code' => 'C01',
                'village_code' => 'V01',
                'residence_type' => 'TAM_TRU',
            ])
            ->assertRedirect(route('households.show', $household))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('households', [
            'id' => $household->id,
            'school_year' => '2025-2026',
            'head_first_name' => 'Bình',
            'head_last_name' => 'Trần',
            'residence_type' => 'TAM_TRU',
        ]);
    }

    public function test_user_cannot_open_a_household_outside_their_commune_even_with_view_permission(): void
    {
        $user = $this->userWithPermissions([AccessPermission::VIEW_HOUSEHOLDS]);
        $household = $this->createHousehold([
            'province_code' => 'P02',
            'commune_code' => 'C02',
            'village_code' => 'V02',
        ]);

        $this->actingAs($user)
            ->get(route('households.show', $household))
            ->assertNotFound();
    }

    public function test_deleting_a_household_requires_delete_permission_and_removes_the_record(): void
    {
        $viewer = $this->userWithPermissions([AccessPermission::VIEW_HOUSEHOLDS]);
        $household = $this->createHousehold();

        $this->actingAs($viewer)
            ->delete(route('households.destroy', $household))
            ->assertForbidden();

        $deleter = $this->userWithPermissions([AccessPermission::DELETE_HOUSEHOLDS]);
        $this->actingAs($deleter)
            ->delete(route('households.destroy', $household))
            ->assertRedirect(route('households.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('households', ['id' => $household->id]);
    }

    public function test_household_bulk_delete_validates_ids_and_deletes_accessible_households(): void
    {
        $user = $this->userWithPermissions([AccessPermission::DELETE_HOUSEHOLDS]);
        $first = $this->createHousehold(['household_code' => '01']);
        $second = $this->createHousehold(['household_code' => '02']);

        $this->actingAs($user)
            ->delete(route('households.bulk-destroy'), ['household_ids' => []])
            ->assertSessionHasErrors('household_ids');

        $this->delete(route('households.bulk-destroy'), [
            'household_ids' => [$first->id, $second->id],
            'school_year' => '2025-2026',
            'page' => 2,
        ])
            ->assertRedirect(route('households.index', ['school_year' => '2025-2026', 'page' => 2]))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('households', 0);
    }

    public function test_member_cannot_be_added_before_the_household_head(): void
    {
        $user = $this->userWithPermissions([AccessPermission::CREATE_HOUSEHOLDS]);
        $household = $this->createHousehold();

        $this->actingAs($user)
            ->post(route('households.members.store', $household), $this->personPayload('Con'))
            ->assertRedirect(route('households.members.create', [
                $household,
                'is_head' => 1,
            ]))
            ->assertSessionHas('warning');

        $this->assertDatabaseCount('persons', 0);
    }

    public function test_household_head_can_be_created_once_and_member_names_follow_the_household_form(): void
    {
        $user = $this->userWithPermissions([AccessPermission::CREATE_HOUSEHOLDS]);
        $household = $this->createHousehold([
            'head_first_name' => 'An',
            'head_last_name' => 'Nguyễn',
        ]);

        $this->actingAs($user)
            ->post(route('households.members.store', $household).'?is_head=1', $this->personPayload(null))
            ->assertRedirect(route('households.show', $household))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('persons', [
            'household_id' => $household->id,
            'first_name' => 'An',
            'last_name' => 'Nguyễn',
            'relationship_with_head' => 'Chủ hộ',
        ]);

        $this->post(route('households.members.store', $household).'?is_head=1', $this->personPayload(null))
            ->assertRedirect(route('households.show', $household))
            ->assertSessionHas('warning');

        $this->assertDatabaseCount('persons', 1);
    }

    public function test_non_head_member_must_be_younger_than_household_head(): void
    {
        $user = $this->userWithPermissions([AccessPermission::CREATE_HOUSEHOLDS]);
        $household = $this->createHousehold();
        $this->createPerson($household, [
            'first_name' => 'An',
            'last_name' => 'Nguyễn',
            'dob' => '1980-01-01',
            'relationship_with_head' => 'Chủ hộ',
        ]);

        $this->actingAs($user)
            ->from(route('households.members.create', $household))
            ->post(route('households.members.store', $household), $this->personPayload('Con', '1979-12-31'))
            ->assertRedirect(route('households.members.create', $household))
            ->assertSessionHasErrors([
                'relationship_with_head' => 'Người có quan hệ Con phải nhỏ tuổi hơn chủ hộ.',
            ]);

        $this->assertDatabaseCount('persons', 1);
    }

    public function test_head_cannot_be_deleted_while_other_members_remain(): void
    {
        $user = $this->userWithPermissions([AccessPermission::DELETE_HOUSEHOLDS]);
        $household = $this->createHousehold();
        $head = $this->createPerson($household, ['relationship_with_head' => 'Chủ hộ']);
        $this->createPerson($household, ['relationship_with_head' => 'Con']);

        $this->actingAs($user)
            ->deleteJson(route('households.members.destroy', [$household, $head->id]))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Hãy xóa các thành viên khác trước khi xóa hồ sơ chủ hộ.');

        $this->assertDatabaseHas('persons', ['id' => $head->id]);
        $this->assertDatabaseCount('persons', 2);
    }

    public function test_import_status_shows_capped_processing_progress_and_full_success(): void
    {
        $user = $this->userWithPermissions([AccessPermission::IMPORT_HOUSEHOLDS]);
        $processing = $this->createImportLog($user, [
            'status' => 'PROCESSING',
            'processed_rows' => 9,
            'total_rows' => 10,
        ]);

        $this->actingAs($user)
            ->getJson(route('households.import.status', $processing))
            ->assertOk()
            ->assertExactJson([
                'status' => 'PROCESSING',
                'progress' => 81,
                'processed_rows' => 9,
                'total_rows' => 10,
                'success_rows' => 0,
                'error_rows' => 0,
                'has_error_file' => false,
            ]);

        $processing->update(['status' => 'SUCCESS', 'processed_rows' => 10]);
        $this->getJson(route('households.import.status', $processing))
            ->assertOk()
            ->assertJsonPath('progress', 100)
            ->assertJsonPath('status', 'SUCCESS');
    }

    public function test_import_history_hides_other_users_records_and_blocks_pending_downloads(): void
    {
        Storage::fake('local');
        $user = $this->userWithPermissions([AccessPermission::IMPORT_HOUSEHOLDS]);
        $otherUser = $this->userWithPermissions([AccessPermission::IMPORT_HOUSEHOLDS]);
        $owned = $this->createImportLog($user, [
            'status' => 'PENDING',
            'file_path' => 'household-imports/pending.xlsx',
        ]);
        $foreign = $this->createImportLog($otherUser, ['status' => 'FAILED']);

        $this->actingAs($user)
            ->getJson(route('households.import.status', $foreign))
            ->assertNotFound();

        $this->get(route('households.import.download', $owned))
            ->assertStatus(409);

        $this->deleteJson(route('households.import.destroy', $owned))
            ->assertStatus(409);
    }

    public function test_import_submission_stores_the_file_and_queues_processing(): void
    {
        Queue::fake();
        Storage::fake('local');
        $user = $this->userWithPermissions([AccessPermission::IMPORT_HOUSEHOLDS]);
        $filePath = tempnam(sys_get_temp_dir(), 'pcgd-import-');
        $xlsxPath = $filePath.'.xlsx';
        rename($filePath, $xlsxPath);

        try {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
            $spreadsheet->getActiveSheet()->setCellValue('A1', 'fixture');
            \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx')->save($xlsxPath);
            $spreadsheet->disconnectWorksheets();

            $response = $this->actingAs($user)->postJson(route('households.import.store'), [
                'file' => new \Illuminate\Http\UploadedFile(
                    $xlsxPath,
                    'households.xlsx',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    null,
                    true,
                ),
                'school_year' => $this->currentSchoolYear(),
                'province_code' => 'P01',
                'commune_code' => 'C01',
                'village_code' => 'V01',
            ]);

            $response->assertAccepted()
                ->assertJsonPath('status_url', route(
                    'households.import.status',
                    ImportLog::query()->first(),
                    false,
                ));
            $this->assertDatabaseHas('import_logs', [
                'user_id' => $user->id,
                'file_name' => 'households.xlsx',
                'status' => 'PENDING',
            ]);
            Queue::assertPushed(ProcessHouseholdImport::class, function (ProcessHouseholdImport $job): bool {
                self::assertSame('database', $job->connection);
                self::assertSame('default', $job->queue);

                return true;
            });

            $importLog = ImportLog::query()->firstOrFail();
            $this->assertTrue(Storage::disk('local')->exists($importLog->file_path));
        } finally {
            if (is_file($xlsxPath)) {
                unlink($xlsxPath);
            }
        }
    }

    public function test_commune_manager_can_create_and_update_villages_only_in_their_commune(): void
    {
        $user = $this->userWithPermissions([AccessPermission::MANAGE_VILLAGES]);

        $this->actingAs($user)
            ->post(route('villages.store'), [
                'code' => 'V03',
                'name' => 'Thôn Ba',
                'province_code' => 'P01',
                'commune_code' => 'C01',
            ])
            ->assertRedirect(route('villages.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('villages', [
            'code' => 'V03',
            'name' => 'Thôn Ba',
            'commune_code' => 'C01',
        ]);

        $this->patch(route('villages.update', 'V03'), [
            'name' => 'Thôn Ba đã sửa',
            'province_code' => 'P01',
            'commune_code' => 'C01',
        ])
            ->assertRedirect(route('villages.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('villages', ['code' => 'V03', 'name' => 'Thôn Ba đã sửa']);

        $this->post(route('villages.store'), [
                'code' => 'V04',
                'name' => 'Thôn ngoài vùng',
                'province_code' => 'P02',
                'commune_code' => 'C02',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('villages', ['code' => 'V04']);
    }

    public function test_province_manager_can_create_and_update_schools_only_in_their_province(): void
    {
        $user = $this->userWithPermissions([AccessPermission::MANAGE_SCHOOLS], 'province');

        $this->actingAs($user)
            ->post(route('schools.store'), [
                'code' => 'S01',
                'name' => 'Trường Một',
                'level' => 'TH',
                'province_code' => 'P01',
                'commune_code' => 'C01',
            ])
            ->assertRedirect(route('schools.index'))
            ->assertSessionHas('success');

        $school = DB::table('schools')->where('code', 'S01')->first();
        $this->assertNotNull($school);
        $this->assertSame('P01', $school->province_code);

        $this->put(route('schools.update', $school->id), [
            'name' => 'Trường Một đã sửa',
            'level' => 'THCS',
            'province_code' => 'P01',
            'commune_code' => 'C01',
        ])
            ->assertRedirect(route('schools.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('schools', [
            'id' => $school->id,
            'name' => 'Trường Một đã sửa',
            'level' => 'THCS',
        ]);

        $this->post(route('schools.store'), [
            'code' => 'S02',
            'name' => 'Trường ngoài tỉnh',
            'level' => 'TH',
            'province_code' => 'P02',
            'commune_code' => 'C02',
        ])->assertForbidden();

        $this->assertDatabaseMissing('schools', ['code' => 'S02']);
    }

    public function test_geography_and_school_lookups_are_limited_to_the_users_area(): void
    {
        DB::table('schools')->insert([
            [
                'code' => 'S01',
                'name' => 'Trường trong xã',
                'level' => 'TH',
                'province_code' => 'P01',
                'commune_code' => 'C01',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'S02',
                'name' => 'Trường ngoài địa bàn',
                'level' => 'TH',
                'province_code' => 'P02',
                'commune_code' => 'C02',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        $user = $this->userWithPermissions([AccessPermission::VIEW_HOUSEHOLDS]);

        $this->actingAs($user)
            ->getJson('/api/provinces/P01/communes')
            ->assertOk()
            ->assertExactJson([['code' => 'C01', 'name' => 'Xã Một']]);

        $this->getJson('/api/provinces/P01/schools')
            ->assertOk()
            ->assertExactJson([['code' => 'S01', 'name' => 'Trường trong xã']]);

        $this->getJson('/api/provinces/P02/schools')
            ->assertOk()
            ->assertExactJson([]);

        $this->getJson('/api/communes/C02/villages')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_import_completion_notifications_are_persistent_and_scoped_to_the_owner(): void
    {
        $user = $this->userWithPermissions([AccessPermission::IMPORT_HOUSEHOLDS]);
        $otherUser = $this->userWithPermissions([AccessPermission::IMPORT_HOUSEHOLDS]);
        $successful = $this->createImportLog($user, [
            'status' => 'SUCCESS',
            'success_rows' => 12,
            'file_path' => 'household-imports/success/source.xlsx',
        ]);
        $failed = $this->createImportLog($user, [
            'status' => 'FAILED',
            'success_rows' => 3,
            'error_rows' => 2,
            'file_path' => 'household-imports/failed/source.xlsx',
        ]);
        $pending = $this->createImportLog($user, ['status' => 'PROCESSING']);
        $alreadyRead = $this->createImportLog($user, [
            'status' => 'SUCCESS',
            'notification_read_at' => now(),
        ]);
        $foreign = $this->createImportLog($otherUser, ['status' => 'FAILED']);

        $this->actingAs($user)
            ->getJson(route('households.import-notifications'))
            ->assertOk()
            ->assertJsonCount(2, 'notifications')
            ->assertJsonFragment([
                'id' => $successful->id,
                'status' => 'SUCCESS',
                'action_label' => 'Xem lịch sử tải lên',
            ])
            ->assertJsonFragment([
                'id' => $failed->id,
                'status' => 'FAILED',
                'action_label' => 'Tải file có đánh dấu lỗi',
            ]);

        $this->postJson(route('households.import-notifications.acknowledge'), [
            'ids' => [$successful->id, $failed->id, $pending->id, $foreign->id],
        ])->assertOk()->assertJsonPath('acknowledged', true);

        self::assertNotNull($successful->fresh()->notification_read_at);
        self::assertNotNull($failed->fresh()->notification_read_at);
        self::assertNull($pending->fresh()->notification_read_at);
        self::assertNotNull($alreadyRead->fresh()->notification_read_at);
        self::assertNull($foreign->fresh()->notification_read_at);

        $this->getJson(route('households.import-notifications'))
            ->assertOk()
            ->assertExactJson(['notifications' => []]);
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('name');
            $table->string('username')->unique();
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
        Schema::create('user_villages', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->string('village_code');
            $table->primary(['user_id', 'village_code']);
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
        Schema::create('schools', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->string('level')->nullable();
            $table->string('province_code')->nullable();
            $table->string('commune_code')->nullable();
            $table->timestamps();
        });
        Schema::create('ethnicities', function (Blueprint $table): void {
            $table->string('code')->primary();
            $table->string('name');
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
            $table->text('address')->nullable();
            $table->string('residence_type')->default('THUONG_TRU');
            $table->string('residence_status')->nullable();
            $table->unsignedBigInteger('import_log_id')->nullable();
            $table->timestamps();
        });
        Schema::create('import_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('file_name');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('file_path')->nullable();
            $table->string('error_file_path')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('notification_read_at')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('success_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->string('status')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('persons', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('household_id');
            $table->unsignedBigInteger('import_log_id')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->date('dob')->nullable();
            $table->string('dob_str')->nullable();
            $table->string('gender')->default('NAM');
            $table->string('ethnicity_code')->nullable();
            $table->string('religion')->nullable();
            $table->string('priority_type')->nullable();
            $table->string('relationship_with_head')->nullable();
            $table->string('parent_name')->nullable();
            $table->string('phone')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
        Schema::create('person_educations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('person_id');
            $table->unsignedBigInteger('import_log_id')->nullable();
            $table->string('school_year');
            $table->string('academic_block')->nullable();
            $table->string('current_class')->nullable();
            $table->string('school_code')->nullable();
            $table->string('graduation_level')->nullable();
            $table->boolean('is_complementary')->default(false);
            $table->string('graduation_year')->nullable();
            $table->string('vocational_grad_level')->nullable();
            $table->string('vocational_grad_year')->nullable();
            $table->string('finished_class')->nullable();
            $table->string('finished_year')->nullable();
            $table->string('dropped_class')->nullable();
            $table->string('dropped_year')->nullable();
            $table->string('literacy_current_class')->nullable();
            $table->string('literacy_completed_class')->nullable();
            $table->string('literacy_relapse_level')->nullable();
            $table->string('learning_capacity')->nullable();
            $table->timestamps();
        });
        Schema::create('person_disabilities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('person_id')->unique();
            $table->boolean('mobility_disability')->default(false);
            $table->boolean('hearing_speech_disability')->default(false);
            $table->boolean('visual_disability')->default(false);
            $table->boolean('mental_disability')->default(false);
            $table->boolean('intellectual_disability')->default(false);
            $table->boolean('learning_disability')->default(false);
            $table->boolean('autism')->default(false);
            $table->boolean('other_disability')->default(false);
            $table->boolean('has_disability_cert')->default(false);
            $table->boolean('can_study')->default(false);
            $table->string('special_circumstance')->nullable();
            $table->text('special_circumstance_detail')->nullable();
            $table->timestamps();
        });
    }

    private function seedLocations(): void
    {
        DB::table('provinces')->insert([
            ['code' => 'P01', 'name' => 'Tỉnh Một', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'P02', 'name' => 'Tỉnh Hai', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('communes')->insert([
            ['code' => 'C01', 'name' => 'Xã Một', 'province_code' => 'P01', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'C02', 'name' => 'Xã Hai', 'province_code' => 'P02', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('villages')->insert([
            ['code' => 'V01', 'name' => 'Thôn Một', 'commune_code' => 'C01', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'V02', 'name' => 'Thôn Hai', 'commune_code' => 'C02', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('ethnicities')->insert(['code' => 'KINH', 'name' => 'Kinh', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function userWithPermissions(array $permissionNames, string $scopeLevel = 'commune'): User
    {
        $role = Role::create([
            'name' => 'commune-'.uniqid(),
            'guard_name' => 'web',
            'scope_level' => $scopeLevel,
        ]);

        foreach ($permissionNames as $permissionName) {
            $permission = Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]);
            $role->permissions()->attach($permission);
        }

        $user = User::create([
            'name' => 'Người dùng thử',
            'username' => 'test-'.uniqid(),
            'password' => Hash::make('password'),
        ]);
        $user->forceFill([
            'is_active' => true,
            'province_code' => 'P01',
            'commune_code' => 'C01',
            'scope_level' => $scopeLevel,
        ])->save();
        $user->roles()->attach($role);

        return $user;
    }

    private function createHousehold(array $attributes = []): Household
    {
        return Household::create(array_merge([
            'household_code' => '01',
            'school_year' => $this->currentSchoolYear(),
            'head_first_name' => 'An',
            'head_last_name' => 'Nguyễn',
            'province_code' => 'P01',
            'commune_code' => 'C01',
            'village_code' => 'V01',
            'residence_type' => 'THUONG_TRU',
        ], $attributes));
    }

    private function createImportLog(User $user, array $attributes = []): ImportLog
    {
        return ImportLog::query()->create(array_merge([
            'file_name' => 'households.xlsx',
            'user_id' => $user->id,
            'file_path' => null,
            'context' => [],
            'total_rows' => 0,
            'processed_rows' => 0,
            'success_rows' => 0,
            'error_rows' => 0,
            'status' => 'PENDING',
        ], $attributes));
    }

    private function createPerson(Household $household, array $attributes = []): Person
    {
        $personId = DB::table('persons')->insertGetId(array_merge([
            'household_id' => $household->id,
            'first_name' => 'An',
            'last_name' => 'Nguyễn',
            'dob' => '1980-01-01',
            'gender' => 'NAM',
            'ethnicity_code' => 'KINH',
            'relationship_with_head' => 'Chủ hộ',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        return Person::query()->findOrFail($personId);
    }

    private function personPayload(?string $relationship, string $dob = '2010-01-01'): array
    {
        return [
            'first_name' => 'Bình',
            'last_name' => 'Trần',
            'dob' => $dob,
            'gender' => 'NAM',
            'ethnicity_code' => 'KINH',
            'relationship_with_head' => $relationship,
            'education' => ['school_year' => $this->currentSchoolYear()],
        ];
    }

    private function currentSchoolYear(): string
    {
        $year = now()->month >= 8 ? now()->year : now()->year - 1;

        return "{$year}-".($year + 1);
    }
}
