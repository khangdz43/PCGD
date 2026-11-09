<?php

namespace Tests\Feature;

use App\Jobs\ProcessHouseholdImport;
use App\Models\Household;
use App\Models\ImportLog;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Models\Village;
use App\Services\Households\HouseholdImportErrorCollector;
use App\Services\Households\HouseholdImportHistoryService;
use App\Services\Households\HouseholdImportProcessingService;
use App\Support\AccessPermission;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class HouseholdCrudFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
        $this->seedLocations();
        $this->seedSchoolYears();
    }

    public function test_commune_user_can_create_a_household_and_the_code_is_assigned_by_school_year(): void
    {
        $user = $this->userWithPermissions([
            AccessPermission::CREATE_HOUSEHOLDS,
        ]);

        $this->actingAs($user)
            ->getJson(route('api.households.create'))
            ->assertOk()
            ->assertJsonPath('data.household.household_code', '01');

        $this->actingAs($user)
            ->postJson(route('api.households.store'), [
                'school_year' => $this->currentSchoolYear(),
                'head_first_name' => 'An',
                'head_last_name' => 'Nguyễn',
                'province_code' => 'P01',
                'commune_code' => 'C01',
                'village_code' => 'V01',
                'residence_type' => 'THUONG_TRU',
                'address' => 'Số 1',
            ])
            ->assertCreated()
            ->assertJsonPath('data.id', Household::query()->value('id'))
            ->assertJsonPath('data.household_code', '01');

        $this->assertDatabaseHas('households', [
            'household_code' => '01',
            'school_year_id' => \App\Models\SchoolYear::findByLabel($this->currentSchoolYear())->id,
            'province_code' => 'P01',
            'commune_code' => 'C01',
            'village_code' => 'V01',
            'head_first_name' => 'An',
        ]);
    }

    public function test_household_creation_rejects_a_commune_outside_the_users_area(): void
    {
        $user = $this->userWithPermissions([
            AccessPermission::VIEW_HOUSEHOLDS,
            AccessPermission::CREATE_HOUSEHOLDS,
        ]);

        $this->actingAs($user)
            ->postJson(route('api.households.store'), [
                'school_year' => $this->currentSchoolYear(),
                'head_first_name' => 'An',
                'head_last_name' => 'Nguyễn',
                'province_code' => 'P01',
                'commune_code' => 'C02',
                'village_code' => 'V02',
                'residence_type' => 'THUONG_TRU',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'commune_code');

        $this->assertDatabaseCount('households', 0);
    }

    public function test_read_only_permission_cannot_open_household_creation_route(): void
    {
        $user = $this->userWithPermissions([AccessPermission::VIEW_HOUSEHOLDS]);

        $this->actingAs($user)
            ->get(route('api.households.create'))
            ->assertForbidden();
    }

    public function test_household_update_changes_head_fields_but_preserves_school_year(): void
    {
        $user = $this->userWithPermissions([AccessPermission::UPDATE_HOUSEHOLDS]);
        $household = $this->createHousehold(['school_year' => '2025-2026']);

        $this->actingAs($user)
            ->putJson(route('api.households.update', $household), [
                'school_year' => $household->school_year,
                'head_first_name' => 'Bình',
                'head_last_name' => 'Trần',
                'province_code' => 'P01',
                'commune_code' => 'C01',
                'village_code' => 'V01',
                'residence_type' => 'TAM_TRU',
                'address' => null,
                'residence_status' => null,
            ])
            ->assertOk();

        $this->assertDatabaseHas('households', [
            'id' => $household->id,
            'household_code' => $household->household_code,
            'school_year_id' => $this->schoolYearId('2025-2026'),
            'head_first_name' => 'Bình',
            'head_last_name' => 'Trần',
            'residence_type' => 'TAM_TRU',
        ]);
    }

    public function test_household_edit_loads_current_education_and_only_necessary_fallback(): void
    {
        $user = $this->userWithPermissions([AccessPermission::UPDATE_HOUSEHOLDS]);
        $household = $this->createHousehold(['school_year' => '2026-2027']);
        $currentPerson = $this->createPerson($household, [
            'first_name' => 'A',
            'relationship_with_head' => 'Chủ hộ',
        ]);
        $fallbackPerson = $this->createPerson($household, [
            'first_name' => 'B',
            'relationship_with_head' => 'Con',
        ]);
        DB::table('person_educations')->insert([
            [
                'person_id' => $currentPerson->id,
                'school_year_id' => $this->schoolYearId('2024-2025'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'person_id' => $currentPerson->id,
                'school_year_id' => $this->schoolYearId('2026-2027'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'person_id' => $fallbackPerson->id,
                'school_year_id' => $this->schoolYearId('2023-2024'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'person_id' => $fallbackPerson->id,
                'school_year_id' => $this->schoolYearId('2024-2025'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->actingAs($user)
            ->getJson(route('api.households.edit', $household))
            ->assertOk()
            ->assertJsonCount(1, 'data.persons.0.educations')
            ->assertJsonPath('data.persons.0.educations.0.school_year', '2026-2027')
            ->assertJsonCount(1, 'data.persons.1.educations')
            ->assertJsonPath('data.persons.1.educations.0.school_year', '2023-2024');
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
            ->get(route('api.households.show', $household))
            ->assertNotFound();
    }

    public function test_deleting_a_household_requires_permission_and_moves_it_to_trash(): void
    {
        $viewer = $this->userWithPermissions([AccessPermission::VIEW_HOUSEHOLDS]);
        $household = $this->createHousehold();

        $this->actingAs($viewer)
            ->delete(route('api.households.destroy', $household))
            ->assertForbidden();

        $deleter = $this->userWithPermissions([AccessPermission::DELETE_HOUSEHOLDS]);
        $this->actingAs($deleter)
            ->deleteJson(route('api.households.destroy', $household))
            ->assertOk();

        $this->assertSoftDeleted('households', ['id' => $household->id]);
        $this->assertDatabaseHas('households', [
            'id' => $household->id,
            'deleted_by' => $deleter->id,
        ]);
    }

    public function test_household_bulk_delete_validates_ids_and_deletes_accessible_households(): void
    {
        $user = $this->userWithPermissions([AccessPermission::DELETE_HOUSEHOLDS]);
        $first = $this->createHousehold(['household_code' => '01']);
        $second = $this->createHousehold(['household_code' => '02']);

        $this->actingAs($user)
            ->deleteJson(route('api.households.destroy-many'), ['household_ids' => []])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'household_ids');

        $this->deleteJson(route('api.households.destroy-many'), [
            'household_ids' => [$first->id, $second->id],
            'school_year' => '2025-2026',
            'page' => 2,
        ])
            ->assertOk()
            ->assertJsonPath('data.deleted_count', 2);

        $this->assertDatabaseHas('households', ['id' => $first->id]);
        $this->assertDatabaseHas('households', ['id' => $second->id]);
        $this->assertSoftDeleted('households', ['id' => $first->id]);
        $this->assertSoftDeleted('households', ['id' => $second->id]);
    }

    public function test_trash_filters_restores_and_permanently_deletes_households_and_members(): void
    {
        $user = $this->userWithPermissions([AccessPermission::DELETE_HOUSEHOLDS]);
        $household = $this->createHousehold([
            'household_code' => 'TRASH-01',
            'head_first_name' => 'Chủ',
        ]);
        $head = $this->createPerson($household, [
            'first_name' => 'Chủ',
            'relationship_with_head' => 'Chủ hộ',
            'dob' => '1982-03-04',
        ]);
        $member = $this->createPerson($household, [
            'first_name' => 'Thành viên cần khôi phục',
            'relationship_with_head' => 'Con',
        ]);

        $this->actingAs($user)
            ->deleteJson(route('api.households.destroy', $household))
            ->assertOk();

        $this->assertSoftDeleted('households', ['id' => $household->id]);
        $this->assertDatabaseHas('persons', ['id' => $head->id, 'deleted_at' => null]);
        DB::table('person_educations')->insert([
            'person_id' => $member->id,
            'school_year_id' => $this->schoolYearId('2025-2026'),
            'academic_block' => '12',
            'current_class' => '12A1',
            'school_code' => 'SCHOOL-TRASH',
            'graduation_level' => 'THPT',
            'is_complementary' => false,
            'graduation_year' => '2025-2026',
            'vocational_grad_level' => 'Sơ cấp',
            'vocational_grad_year' => '2024-2025',
            'finished_class' => '11',
            'finished_year' => '2024-2025',
            'dropped_class' => '10',
            'dropped_year' => '2023-2024',
            'literacy_current_class' => '3',
            'literacy_completed_class' => '2',
            'literacy_relapse_level' => '1',
            'learning_capacity' => 'Đang học',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->getJson(route('api.trash.index', ['type' => 'household', 'q' => 'TRASH-01']))
            ->assertOk()
            ->assertJsonFragment([
                'household_code' => 'TRASH-01',
                'head_name' => 'Nguyễn Chủ',
            ]);
        $this->getJson(route('api.trash.households.show', $household->id))
            ->assertOk()
            ->assertJsonFragment(['household_code' => 'TRASH-01'])
            ->assertJsonFragment(['first_name' => 'Thành viên cần khôi phục'])
            ->assertJsonFragment(['current_class' => '12A1']);
        $this->getJson(route('api.trash.index', ['type' => 'person']))
            ->assertOk()
            ->assertJsonMissingPath('data.items.0.record.person_name');

        $this->patchJson(route('api.trash.households.restore', $household->id))
            ->assertOk();
        $this->assertDatabaseHas('households', ['id' => $household->id, 'deleted_at' => null, 'deleted_by' => null]);
        $this->assertSame(2, $household->fresh()->persons()->count());

        $this->deleteJson(route('api.households.persons.destroy', [$household, $member->id]))
            ->assertOk();
        $this->assertSoftDeleted('persons', ['id' => $member->id]);
        $this->getJson(route('api.trash.index', ['type' => 'person', 'q' => 'Thành viên cần khôi phục']))
            ->assertOk()
            ->assertJsonPath('data.items.0.record.person_name', 'Nguyễn Thành viên cần khôi phục');
        $this->getJson(route('api.trash.persons.show', $member->id))
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Thành viên cần khôi phục')
            ->assertJsonPath('data.educations.0.current_class', '12A1')
            ->assertJsonPath('data.household.household_code', 'TRASH-01');

        $this->patchJson(route('api.trash.persons.restore', $member->id))
            ->assertOk();
        $this->assertDatabaseHas('persons', ['id' => $member->id, 'deleted_at' => null, 'deleted_by' => null]);

        $this->deleteJson(route('api.households.persons.destroy', [$household, $member->id]))
            ->assertOk();
        $this->deleteJson(route('api.trash.persons.destroy', $member->id))
            ->assertOk();
        $this->assertDatabaseMissing('persons', ['id' => $member->id]);

        $this->deleteJson(route('api.households.destroy', $household))
            ->assertOk();
        $this->deleteJson(route('api.trash.households.destroy', $household->id))
            ->assertOk();
        $this->assertDatabaseMissing('households', ['id' => $household->id]);
        $this->assertDatabaseMissing('persons', ['id' => $head->id]);
    }

    public function test_trash_supports_bulk_restore_and_permanent_deletion_for_mixed_records(): void
    {
        $user = $this->userWithPermissions([AccessPermission::DELETE_HOUSEHOLDS]);
        $household = $this->createHousehold(['household_code' => 'BULK-TRASH']);
        $head = $this->createPerson($household, ['relationship_with_head' => 'Chủ hộ']);
        $member = $this->createPerson($household, ['relationship_with_head' => 'Con']);
        $head->deleted_by = $user->id;
        $head->save();
        $head->delete();
        $this->actingAs($user)->deleteJson(route('api.households.destroy', $household));

        $this->postJson(route('api.trash.batch-operations.store'), [
            'operation' => 'restore',
            'household_ids' => [$household->id],
            'person_ids' => [$head->id],
        ])->assertOk();

        $this->assertDatabaseHas('households', ['id' => $household->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('persons', ['id' => $head->id, 'deleted_at' => null]);

        $head = Person::query()->findOrFail($head->id);
        $head->deleted_by = $user->id;
        $head->save();
        $head->delete();
        $this->deleteJson(route('api.households.destroy', $household));
        $this->postJson(route('api.trash.batch-operations.store'), [
            'operation' => 'force_delete',
            'household_ids' => [$household->id],
            'person_ids' => [$head->id],
        ])->assertOk();

        $this->assertDatabaseMissing('households', ['id' => $household->id]);
        $this->assertDatabaseMissing('persons', ['id' => $head->id]);
        $this->assertDatabaseMissing('persons', ['id' => $member->id]);
    }

    public function test_api_trash_returns_compact_records_and_fifty_items_per_page(): void
    {
        $user = $this->userWithPermissions([AccessPermission::DELETE_HOUSEHOLDS]);
        $household = $this->createHousehold([
            'household_code' => 'COMPACT-TRASH',
            'head_first_name' => 'Lan',
            'head_last_name' => 'Nguyễn',
        ]);
        $person = $this->createPerson($household, [
            'first_name' => 'Minh',
            'last_name' => 'Trần',
        ]);
        $person->deleted_by = $user->id;
        $person->save();
        $person->delete();

        $response = $this->actingAs($user)
            ->getJson(route('api.trash.index'))
            ->assertOk()
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonFragment([
                'household_code' => 'COMPACT-TRASH',
                'person_name' => 'Trần Minh',
            ])
            ->assertJsonMissingPath('data.provinces')
            ->assertJsonMissingPath('data.items.0.record.persons');

        $this->assertLessThan(5000, strlen($response->getContent()));

        $this->actingAs($user)
            ->getJson(route('api.trash.index', ['include_filters' => 1]))
            ->assertOk()
            ->assertJsonStructure(['data' => ['provinces', 'communes', 'villages']]);
    }

    public function test_trash_requires_delete_permission_and_respects_the_users_area(): void
    {
        $viewer = $this->userWithPermissions([AccessPermission::VIEW_HOUSEHOLDS]);
        $insideAreaUser = $this->userWithPermissions([AccessPermission::DELETE_HOUSEHOLDS]);
        $outsideArea = $this->createHousehold([
            'household_code' => 'OUTSIDE-TRASH',
            'province_code' => 'P02',
            'commune_code' => 'C02',
            'village_code' => 'V02',
        ]);
        DB::table('households')->where('id', $outsideArea->id)->update([
            'deleted_at' => now(),
            'deleted_by' => $insideAreaUser->id,
        ]);

        $this->actingAs($viewer)
            ->get(route('api.trash.index'))
            ->assertForbidden();

        $this->actingAs($insideAreaUser)
            ->get(route('api.trash.index'))
            ->assertOk()
            ->assertDontSee('OUTSIDE-TRASH');

        $this->patch(route('api.trash.households.restore', $outsideArea->id))
            ->assertNotFound();
    }

    public function test_member_cannot_be_added_before_the_household_head(): void
    {
        $user = $this->userWithPermissions([AccessPermission::CREATE_HOUSEHOLDS]);
        $household = $this->createHousehold();

        $this->actingAs($user)
            ->postJson(route('api.households.persons.store', $household), $this->personPayload('Con'))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cần nhập hồ sơ chủ hộ trước khi thêm thành viên khác.');

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
            ->postJson(route('api.households.persons.store', $household) . '?is_head=1', $this->personPayload(null))
            ->assertCreated();

        $this->assertDatabaseHas('persons', [
            'household_id' => $household->id,
            'first_name' => 'Bình',
            'last_name' => 'Trần',
            'relationship_with_head' => 'Chủ hộ',
        ]);
        $this->assertDatabaseHas('households', [
            'id' => $household->id,
            'head_first_name' => 'Bình',
            'head_last_name' => 'Trần',
        ]);

        $this->postJson(route('api.households.persons.store', $household) . '?is_head=1', $this->personPayload(null))
            ->assertUnprocessable();

        $this->assertDatabaseCount('persons', 1);
    }

    public function test_academic_block_is_extracted_from_the_entered_class_name(): void
    {
        $user = $this->userWithPermissions([AccessPermission::CREATE_HOUSEHOLDS]);
        $household = $this->createHousehold();
        $this->createPerson($household, ['dob' => '1980-01-01', 'relationship_with_head' => 'Chủ hộ']);
        $payload = $this->personPayload('Con', '2010-01-01');
        $payload['education']['academic_block'] = '2';
        $payload['education']['current_class'] = '12A1';

        $this->actingAs($user)
            ->postJson(route('api.households.persons.store', $household), $payload)
            ->assertCreated();

        $this->assertDatabaseHas('person_educations', [
            'current_class' => '12A1',
            'academic_block' => '12',
            'school_year_id' => $this->schoolYearId($this->currentSchoolYear()),
        ]);
    }

    public function test_general_education_class_fields_reject_grades_above_twelve(): void
    {
        $user = $this->userWithPermissions([AccessPermission::CREATE_HOUSEHOLDS]);
        $household = $this->createHousehold();
        $this->createPerson($household, ['dob' => '1980-01-01', 'relationship_with_head' => 'Chủ hộ']);

        foreach (['current_class', 'finished_class', 'dropped_class'] as $field) {
            $payload = $this->personPayload('Con');
            $payload['education'][$field] = '13A1';

            $this->actingAs($user)
                ->postJson(route('api.households.persons.store', $household), $payload)
                ->assertUnprocessable()
                ->assertJsonPath('error.details.0.field', "education.{$field}");
        }
    }

    public function test_member_phone_accepts_plus_84_but_rejects_text(): void
    {
        $user = $this->userWithPermissions([AccessPermission::CREATE_HOUSEHOLDS]);
        $household = $this->createHousehold();
        $this->createPerson($household, ['relationship_with_head' => 'Chủ hộ']);
        $payload = $this->personPayload('Con');
        $payload['phone'] = '09012abc';

        $this->actingAs($user)
            ->postJson(route('api.households.persons.store', $household), $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'phone');

        $payload['phone'] = '+84912345678';
        $this->postJson(route('api.households.persons.store', $household), $payload)
            ->assertCreated();

        $this->assertDatabaseHas('persons', ['phone' => '+84912345678']);
    }

    public function test_member_update_rejects_non_numeric_phone(): void
    {
        $user = $this->userWithPermissions([AccessPermission::UPDATE_HOUSEHOLDS]);
        $household = $this->createHousehold();
        $person = $this->createPerson($household, ['relationship_with_head' => 'Chủ hộ']);
        $payload = $this->personPayload('Chủ hộ');
        $payload['phone'] = '09012abc';

        $this->actingAs($user)
            ->putJson(route('api.households.persons.update', [$household, $person->id]), $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'phone');
    }

    public function test_household_allows_only_one_spouse_and_one_parent_but_multiple_children(): void
    {
        $user = $this->userWithPermissions([
            AccessPermission::CREATE_HOUSEHOLDS,
            AccessPermission::UPDATE_HOUSEHOLDS,
        ]);
        $household = $this->createHousehold();
        $head = $this->createPerson($household, ['relationship_with_head' => 'Chủ hộ']);
        $this->actingAs($user);

        $this->postJson(route('api.households.persons.store', $household), $this->personPayload('Vợ'))
            ->assertCreated();
        $this->postJson(route('api.households.persons.store', $household), $this->personPayload('Chồng'))
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'relationship_with_head');

        $this->postJson(route('api.households.persons.store', $household), $this->personPayload('Cha', '1960-01-01'))
            ->assertCreated();
        $this->postJson(route('api.households.persons.store', $household), $this->personPayload('Mẹ', '1962-01-01'))
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'relationship_with_head');

        $this->postJson(route('api.households.persons.store', $household), $this->personPayload('Con'))
            ->assertCreated();
        $this->postJson(route('api.households.persons.store', $household), $this->personPayload('Con'))
            ->assertCreated();

        $child = $household->persons()->where('relationship_with_head', 'Con')->firstOrFail();
        $this->putJson(
            route('api.households.persons.update', [$household, $child->id]),
            $this->personPayload('Vợ'),
        )->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'relationship_with_head');

        $this->assertDatabaseHas('persons', ['id' => $head->id, 'relationship_with_head' => 'Chủ hộ']);
    }

    public function test_trashed_spouse_cannot_be_restored_when_the_slot_is_taken(): void
    {
        $user = $this->userWithPermissions([AccessPermission::DELETE_HOUSEHOLDS]);
        $household = $this->createHousehold();
        $this->createPerson($household, ['relationship_with_head' => 'Chủ hộ']);
        $trashedSpouse = $this->createPerson($household, ['relationship_with_head' => 'Vợ']);
        $trashedSpouse->delete();
        $this->createPerson($household, ['relationship_with_head' => 'Chồng']);

        $this->actingAs($user)
            ->patchJson(route('api.trash.persons.restore', $trashedSpouse->id))
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'trash');

        $this->assertSoftDeleted('persons', ['id' => $trashedSpouse->id]);
    }

    public function test_education_year_fields_reject_non_year_text(): void
    {
        $user = $this->userWithPermissions([AccessPermission::CREATE_HOUSEHOLDS]);
        $household = $this->createHousehold();
        $this->createPerson($household, ['relationship_with_head' => 'Chủ hộ']);
        $payload = $this->personPayload('Con');
        $payload['education']['graduation_year'] = 'năm vừa rồi';

        $this->actingAs($user)
            ->postJson(route('api.households.persons.store', $household), $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'education.graduation_year');
    }

    public function test_person_education_validation_uses_vietnamese_field_names(): void
    {
        $user = $this->userWithPermissions([AccessPermission::CREATE_HOUSEHOLDS]);
        $household = $this->createHousehold();
        $this->createPerson($household, ['relationship_with_head' => 'Chủ hộ']);
        $payload = $this->personPayload('Con');
        $payload['education']['school_year'] = 'năm không tồn tại';

        $this->actingAs($user)
            ->postJson(route('api.households.persons.store', $household), $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'education.school_year')
            ->assertJsonPath('error.details.0.issue', 'năm học đã chọn không hợp lệ.');
    }

    public function test_education_year_fields_accept_the_current_year_range_with_next_year(): void
    {
        $user = $this->userWithPermissions([AccessPermission::CREATE_HOUSEHOLDS]);
        $household = $this->createHousehold();
        $this->createPerson($household, ['relationship_with_head' => 'Chủ hộ']);
        $currentYear = now()->year;
        $yearRange = "{$currentYear}-" . ($currentYear + 1);

        foreach ([
            'graduation_year',
            'vocational_grad_year',
            'finished_year',
            'dropped_year',
        ] as $field) {
            $payload = $this->personPayload('Con');
            $payload['education'][$field] = $yearRange;

            $this->actingAs($user)
                ->postJson(route('api.households.persons.store', $household), $payload)
                ->assertCreated();

            $this->assertDatabaseHas('person_educations', [$field => $yearRange]);
        }
    }

    public function test_explicit_academic_block_is_preserved_when_saving_a_person(): void
    {
        $user = $this->userWithPermissions([AccessPermission::CREATE_HOUSEHOLDS]);
        $household = $this->createHousehold();
        $this->createPerson($household, ['dob' => '1980-01-01', 'relationship_with_head' => 'Chủ hộ']);
        $payload = $this->personPayload('Con', '2010-01-01');
        $payload['education']['academic_block'] = '5';
        $payload['education']['current_class'] = 'Lớp Năm';

        $this->actingAs($user)
            ->postJson(route('api.households.persons.store', $household), $payload)
            ->assertCreated();

        $this->assertDatabaseHas('person_educations', [
            'current_class' => 'Lớp Năm',
            'academic_block' => '5',
            'school_year_id' => $this->schoolYearId($this->currentSchoolYear()),
        ]);
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
            ->postJson(route('api.households.persons.store', $household), $this->personPayload('Con', '1979-12-31'))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Người có quan hệ Con phải nhỏ tuổi hơn chủ hộ.');

        $this->assertDatabaseCount('persons', 1);
    }

    public function test_head_cannot_be_deleted_while_other_members_remain(): void
    {
        $user = $this->userWithPermissions([AccessPermission::DELETE_HOUSEHOLDS]);
        $household = $this->createHousehold();
        $head = $this->createPerson($household, ['relationship_with_head' => 'Chủ hộ']);
        $this->createPerson($household, ['relationship_with_head' => 'Con']);

        $this->actingAs($user)
            ->deleteJson(route('api.households.persons.destroy', [$household, $head->id]))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Hãy xóa các thành viên khác trước khi xóa hồ sơ chủ hộ.');

        $this->assertDatabaseHas('persons', ['id' => $head->id]);
        $this->assertDatabaseCount('persons', 2);
    }

    public function test_import_status_shows_processing_progress_and_partial_completion(): void
    {
        Storage::fake('local');
        $user = $this->userWithPermissions([AccessPermission::IMPORT_HOUSEHOLDS]);
        $processing = $this->createImportLog($user, [
            'status' => 'PROCESSING',
            'processed_rows' => 9,
            'total_rows' => 10,
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/household-imports/' . $processing->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'PROCESSING')
            ->assertJsonPath('data.progress', 90)
            ->assertJsonPath('data.processed_rows', 9)
            ->assertJsonPath('data.total_rows', 10)
            ->assertJsonPath('data.has_error_file', false);

        $errorFilePath = 'household-imports/partial/error-source.xlsx';
        Storage::disk('local')->put($errorFilePath, 'errors');
        $processing->update([
            'status' => 'PARTIAL',
            'processed_rows' => 10,
            'error_file_path' => $errorFilePath,
        ]);
        $this->getJson('/api/v1/household-imports/' . $processing->id)
            ->assertOk()
            ->assertJsonPath('data.progress', 100)
            ->assertJsonPath('data.status', 'PARTIAL')
            ->assertJsonPath('data.has_error_file', true);
    }

    public function test_import_statuses_can_be_checked_in_one_request_for_owned_imports_only(): void
    {
        $user = $this->userWithPermissions([AccessPermission::IMPORT_HOUSEHOLDS]);
        $otherUser = $this->userWithPermissions([AccessPermission::IMPORT_HOUSEHOLDS]);
        $processing = $this->createImportLog($user, [
            'status' => 'PROCESSING',
            'processed_rows' => 9,
            'total_rows' => 10,
        ]);
        $completed = $this->createImportLog($user, ['status' => 'SUCCESS']);
        $foreign = $this->createImportLog($otherUser, ['status' => 'PROCESSING']);
        $firstQueued = $this->createImportLog($otherUser, [
            'status' => 'PENDING',
            'created_at' => now()->subMinutes(2),
        ]);
        $queued = $this->createImportLog($user, [
            'status' => 'PENDING',
            'created_at' => now()->subMinute(),
        ]);
        $lastQueued = $this->createImportLog($otherUser, [
            'status' => 'PENDING',
            'created_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson(route('api.households.import.statuses', [
                'ids' => [$processing->id, $completed->id, $foreign->id, $queued->id],
            ]))
            ->assertOk()
            ->assertJsonCount(3, 'data.statuses')
            ->assertJsonPath('data.statuses.' . $processing->id . '.status', 'PROCESSING')
            ->assertJsonPath('data.statuses.' . $processing->id . '.progress', 90)
            ->assertJsonPath('data.statuses.' . $completed->id . '.status', 'SUCCESS')
            ->assertJsonPath('data.statuses.' . $completed->id . '.progress', 100)
            ->assertJsonMissingPath('data.statuses.' . $foreign->id)
            ->assertJsonPath('data.statuses.' . $queued->id . '.queue_position', 2)
            ->assertJsonPath('data.statuses.' . $queued->id . '.queue_total', 3);

        $this->getJson(route('api.households.import-history'))
            ->assertOk()
            ->assertJsonPath('data.queue_total', 3);

        self::assertSame('PENDING', $firstQueued->fresh()->status);
        self::assertSame('PENDING', $lastQueued->fresh()->status);
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
            ->getJson(route('api.households.import.status', $foreign))
            ->assertNotFound();

        $this->get(route('api.household-imports.file', $owned))
            ->assertStatus(409);

        $this->deleteJson(route('api.household-imports.destroy', $owned))
            ->assertStatus(409);
    }

    public function test_missing_error_workbook_download_creates_a_quick_xlsx_report(): void
    {
        Storage::fake('local');
        $user = $this->userWithPermissions([AccessPermission::IMPORT_HOUSEHOLDS]);
        $filePath = 'household-imports/failed/malformed.xls';
        Storage::disk('local')->put($filePath, 'invalid workbook data');
        $importLog = $this->createImportLog($user, [
            'file_name' => 'malformed.xls',
            'file_path' => $filePath,
            'status' => 'FAILED',
        ]);

        $errorFilePath = app(HouseholdImportHistoryService::class)
            ->errorFilePath($importLog);

        self::assertTrue(Storage::disk('local')->exists($errorFilePath));
        self::assertSame('xlsx', strtolower(pathinfo($errorFilePath, PATHINFO_EXTENSION)));
        self::assertSame($errorFilePath, $importLog->fresh()->error_file_path);
        $workbook = IOFactory::load(Storage::disk('local')->path($errorFilePath));
        self::assertStringContainsString(
            'Không thể tạo file lỗi',
            (string) $workbook->getActiveSheet()->getCell('A1')->getValue(),
        );
        $workbook->disconnectWorksheets();
    }

    public function test_error_workbook_download_converts_existing_xls_files_to_xlsx(): void
    {
        Storage::fake('local');
        $user = $this->userWithPermissions([AccessPermission::IMPORT_HOUSEHOLDS]);
        $sourcePath = 'household-imports/failed/source.xls';
        $errorPath = 'household-imports/failed/error-source.xls';
        Storage::disk('local')->put($sourcePath, 'source workbook');
        Storage::disk('local')->makeDirectory(dirname($errorPath));

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'Tiêu đề');
        $spreadsheet->getActiveSheet()->setSelectedCell('AY1063');
        IOFactory::createWriter($spreadsheet, 'Xls')
            ->save(Storage::disk('local')->path($errorPath));
        $spreadsheet->disconnectWorksheets();

        $importLog = $this->createImportLog($user, [
            'file_name' => 'source.xls',
            'file_path' => $sourcePath,
            'error_file_path' => $errorPath,
            'status' => 'FAILED',
        ]);

        $normalizedErrorPath = app(HouseholdImportHistoryService::class)
            ->errorFilePath($importLog);

        self::assertSame('household-imports/failed/error-source.xlsx', $normalizedErrorPath);
        self::assertSame($normalizedErrorPath, $importLog->fresh()->error_file_path);
        Storage::disk('local')->assertMissing($errorPath);
        $workbook = IOFactory::load(Storage::disk('local')->path($normalizedErrorPath));
        self::assertSame('A1', $workbook->getActiveSheet()->getSelectedCells());
        self::assertSame('Tiêu đề', $workbook->getActiveSheet()->getCell('A1')->getValue());
        $workbook->disconnectWorksheets();
    }

    public function test_error_workbook_download_reuses_existing_xlsx_without_rewriting_it(): void
    {
        Storage::fake('local');
        $user = $this->userWithPermissions([AccessPermission::IMPORT_HOUSEHOLDS]);
        $sourcePath = 'household-imports/failed/source.xlsx';
        $errorPath = 'household-imports/failed/error-source.xlsx';
        Storage::disk('local')->put($sourcePath, 'source workbook');
        Storage::disk('local')->makeDirectory(dirname($errorPath));

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'Kết quả lỗi');
        $spreadsheet->getActiveSheet()->setSelectedCell('A5');
        IOFactory::createWriter($spreadsheet, 'Xlsx')
            ->save(Storage::disk('local')->path($errorPath));
        $spreadsheet->disconnectWorksheets();

        $importLog = $this->createImportLog($user, [
            'file_name' => 'source.xlsx',
            'file_path' => $sourcePath,
            'error_file_path' => $errorPath,
            'status' => 'FAILED',
        ]);

        self::assertSame(
            $errorPath,
            app(HouseholdImportHistoryService::class)->errorFilePath($importLog),
        );
        self::assertTrue(Storage::disk('local')->exists($errorPath));
        $workbook = IOFactory::load(Storage::disk('local')->path($errorPath));
        self::assertSame('A5', $workbook->getActiveSheet()->getSelectedCells());
        self::assertSame('Kết quả lỗi', $workbook->getActiveSheet()->getCell('A1')->getValue());
        $workbook->disconnectWorksheets();
    }

    public function test_error_workbook_download_unhides_the_active_sheet_in_legacy_xlsx(): void
    {
        Storage::fake('local');
        $user = $this->userWithPermissions([AccessPermission::IMPORT_HOUSEHOLDS]);
        $sourcePath = 'household-imports/failed/source.xls';
        $errorPath = 'household-imports/failed/error-source.xlsx';
        Storage::disk('local')->put($sourcePath, 'source workbook');
        Storage::disk('local')->makeDirectory(dirname($errorPath));

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle('Danh sách phiếu điều tra');
        $hiddenSheet = $spreadsheet->createSheet(0);
        $hiddenSheet->setTitle('XXXX');
        $hiddenSheet->setSheetState('veryHidden');
        $hiddenSheet->setCellValue('A5', 'Lỗi cần kiểm tra');
        $spreadsheet->setActiveSheetIndex(0);
        IOFactory::createWriter($spreadsheet, 'Xlsx')
            ->save(Storage::disk('local')->path($errorPath));
        $spreadsheet->disconnectWorksheets();

        $importLog = $this->createImportLog($user, [
            'file_name' => 'source.xls',
            'file_path' => $sourcePath,
            'error_file_path' => $errorPath,
            'status' => 'FAILED',
        ]);

        self::assertSame(
            $errorPath,
            app(HouseholdImportHistoryService::class)->errorFilePath($importLog),
        );
        $workbook = IOFactory::load(Storage::disk('local')->path($errorPath));
        self::assertSame('XXXX', $workbook->getActiveSheet()->getTitle());
        self::assertSame('visible', $workbook->getActiveSheet()->getSheetState());
        self::assertSame('Lỗi cần kiểm tra', $workbook->getActiveSheet()->getCell('A5')->getValue());
        self::assertTrue($importLog->fresh()->context['error_file_active_sheet_checked']);
        $workbook->disconnectWorksheets();
    }

    public function test_import_history_can_be_removed_without_deleting_imported_households(): void
    {
        Storage::fake('local');
        $user = $this->userWithPermissions([AccessPermission::IMPORT_HOUSEHOLDS]);
        $successful = $this->createImportLog($user, [
            'status' => 'SUCCESS',
            'file_path' => 'household-imports/success/source.xlsx',
            'error_file_path' => 'household-imports/success/error-source.xlsx',
        ]);
        $failed = $this->createImportLog($user, [
            'status' => 'FAILED',
            'file_path' => 'household-imports/failed/source.xlsx',
        ]);
        $deleted = $this->createImportLog($user, ['status' => 'DELETED']);
        $this->createImportLog($user, ['status' => 'PROCESSING']);

        Storage::disk('local')->put($successful->file_path, 'original');
        Storage::disk('local')->put($successful->error_file_path, 'annotated');
        Storage::disk('local')->put($failed->file_path, 'failed');

        $householdId = DB::table('households')->insertGetId([
            'household_code' => 'KEPT-AFTER-HISTORY-DELETE',
            'school_year_id' => $this->schoolYearId($this->currentSchoolYear()),
            'import_log_id' => $successful->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $page = $this->actingAs($user)->getJson(route('api.households.import-history'));
        $page->assertOk()->assertJsonCount(4, 'data.imports');

        $this->deleteJson(route('api.household-imports.destroy', $successful))
            ->assertOk()
            ->assertJsonPath('message', 'Đã xóa lịch sử tải file');

        $this->assertDatabaseMissing('import_logs', ['id' => $successful->id]);
        $this->assertDatabaseHas('households', ['id' => $householdId]);
        Storage::disk('local')->assertMissing($successful->file_path);
        Storage::disk('local')->assertMissing($successful->error_file_path);

        $this->deleteJson(route('api.household-imports.destroy', $failed))
            ->assertOk();
        $this->assertDatabaseMissing('import_logs', ['id' => $failed->id]);
        Storage::disk('local')->assertMissing($failed->file_path);

        $this->deleteJson(route('api.household-imports.destroy', $deleted))
            ->assertOk();
        $this->assertDatabaseMissing('import_logs', ['id' => $deleted->id]);
    }

    public function test_import_submission_stores_the_file_and_queues_processing(): void
    {
        Queue::fake();
        Storage::fake('local');
        $user = $this->userWithPermissions([AccessPermission::IMPORT_HOUSEHOLDS]);
        $filePath = tempnam(sys_get_temp_dir(), 'pcgd-import-');
        $xlsxPath = $filePath . '.xlsx';
        rename($filePath, $xlsxPath);

        try {
            $spreadsheet = new Spreadsheet;
            $spreadsheet->getActiveSheet()->setCellValue('A1', 'fixture');
            IOFactory::createWriter($spreadsheet, 'Xlsx')->save($xlsxPath);
            $spreadsheet->disconnectWorksheets();

            $response = $this->actingAs($user)->postJson(route('api.household-imports.store'), [
                'file' => new UploadedFile(
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
                ->assertJsonPath('data.status_url', route(
                    'api.households.import.status',
                    ImportLog::query()->first(),
                    false,
                ))
                ->assertJsonPath('data.history_url', route(
                    'api.households.import-history',
                    ['import_id' => ImportLog::query()->first()->id],
                    false,
                ));
            $this->getJson(route('api.households.import-history'))
                ->assertOk()
                ->assertJsonPath('data.imports.0.status', 'PENDING');
            $this->assertDatabaseHas('import_logs', [
                'user_id' => $user->id,
                'file_name' => 'households.xlsx',
                'status' => 'PENDING',
            ]);
            Queue::assertPushed(ProcessHouseholdImport::class, function (ProcessHouseholdImport $job): bool {
                self::assertSame('database', $job->connection);
                self::assertSame('default', $job->queue);
                self::assertSame(2, $job->tries);

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

    public function test_import_submission_accepts_multiple_files_and_queues_each_file(): void
    {
        Queue::fake();
        Storage::fake('local');
        $user = $this->userWithPermissions([AccessPermission::IMPORT_HOUSEHOLDS]);
        $filePath = tempnam(sys_get_temp_dir(), 'pcgd-import-batch-');
        $xlsxPath = $filePath . '.xlsx';
        rename($filePath, $xlsxPath);

        try {
            $spreadsheet = new Spreadsheet;
            $spreadsheet->getActiveSheet()->setCellValue('A1', 'fixture');
            IOFactory::createWriter($spreadsheet, 'Xlsx')->save($xlsxPath);
            $spreadsheet->disconnectWorksheets();

            $response = $this->actingAs($user)->postJson(route('api.household-imports.store'), [
                'files' => [
                    new UploadedFile(
                        $xlsxPath,
                        'households-one.xlsx',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        null,
                        true,
                    ),
                    new UploadedFile(
                        $xlsxPath,
                        'households-two.xlsx',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        null,
                        true,
                    ),
                ],
                'school_year' => $this->currentSchoolYear(),
                'province_code' => 'P01',
                'commune_code' => 'C01',
                'village_code' => 'V01',
            ]);

            $response->assertAccepted()
                ->assertJsonCount(2, 'data.import_ids')
                ->assertJsonPath('data.imports.0.file_name', 'households-one.xlsx')
                ->assertJsonPath('data.imports.1.file_name', 'households-two.xlsx');
            $this->assertDatabaseCount('import_logs', 2);
            Queue::assertPushed(ProcessHouseholdImport::class, 2);

            foreach (ImportLog::query()->get() as $importLog) {
                $this->assertTrue(Storage::disk('local')->exists($importLog->file_path));
                $this->assertSame('PENDING', $importLog->status);
            }
        } finally {
            if (is_file($xlsxPath)) {
                unlink($xlsxPath);
            }
        }
    }

    public function test_import_reports_a_clear_message_for_an_excel_file_with_the_wrong_template(): void
    {
        Storage::fake('local');
        $user = $this->userWithPermissions([AccessPermission::IMPORT_HOUSEHOLDS]);
        $temporaryPath = tempnam(sys_get_temp_dir(), 'pcgd-invalid-import-');
        $xlsxPath = $temporaryPath . '.xlsx';
        rename($temporaryPath, $xlsxPath);

        try {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Sheet1');
            $sheet->setCellValue('O2', 'Mã hộ');
            $sheet->setCellValue('AY2', 'Ghi chú');
            $sheet->setCellValue('C5', 'Nguyễn');
            $sheet->setCellValue('D5', 'An');
            $sheet->setCellValue('O5', 'HK-INVALID');
            IOFactory::createWriter($spreadsheet, 'Xlsx')->save($xlsxPath);
            $spreadsheet->disconnectWorksheets();

            $storedPath = 'household-imports/invalid/source.xlsx';
            Storage::disk('local')->put($storedPath, file_get_contents($xlsxPath));
            $importLog = $this->createImportLog($user, [
                'file_name' => 'source.xlsx',
                'file_path' => $storedPath,
                'context' => [
                    'school_year' => $this->currentSchoolYear(),
                    'province_code' => 'P01',
                    'commune_code' => 'C01',
                    'village_code' => 'V01',
                ],
            ]);

            $response = app(HouseholdImportProcessingService::class)->process(
                $importLog,
                $user,
                new UploadedFile($xlsxPath, 'source.xlsx', null, null, true),
            );

            self::assertSame(422, $response->getStatusCode());
            self::assertSame('FAILED', $importLog->fresh()->status);
            $this->assertDatabaseCount('households', 0);
            self::assertStringContainsString(
                'không đúng biểu mẫu phiếu điều tra',
                $importLog->fresh()->context['processing_error'],
            );
            $errorPath = app(HouseholdImportHistoryService::class)->errorFilePath($importLog->fresh());
            $errorWorkbook = IOFactory::load(Storage::disk('local')->path($errorPath));
            self::assertStringContainsString(
                'không đúng biểu mẫu phiếu điều tra',
                strtolower((string) $errorWorkbook->getActiveSheet()->getCell('A2')->getValue()),
            );
            $errorWorkbook->disconnectWorksheets();
            $this->actingAs($user)
                ->getJson(route('api.households.import.status', $importLog))
                ->assertOk()
                ->assertJsonPath(
                    'data.error_message',
                    $importLog->fresh()->context['processing_error'],
                );
        } finally {
            if (is_file($xlsxPath)) {
                unlink($xlsxPath);
            }
        }
    }

    public function test_import_keeps_valid_rows_and_exports_only_invalid_rows(): void
    {
        Storage::fake('local');
        $user = $this->userWithPermissions([AccessPermission::IMPORT_HOUSEHOLDS]);
        $filePath = tempnam(sys_get_temp_dir(), 'pcgd-partial-import-');
        $xlsxPath = $filePath . '.xlsx';
        rename($filePath, $xlsxPath);

        try {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Danh sách phiếu điều tra');
            $sheet->setCellValue('O2', 'Số phiếu');
            $sheet->setCellValue('AY2', 'Ghi chú');
            foreach ([5 => ['GOOD', 'Kinh'], 6 => ['BAD', '']] as $row => [$code, $ethnicity]) {
                $sheet->setCellValue("C{$row}", 'Nguyễn');
                $sheet->setCellValue("D{$row}", 'An');
                $sheet->setCellValue("E{$row}", 1);
                $sheet->setCellValue("F{$row}", 1);
                $sheet->setCellValue("G{$row}", 2010);
                $sheet->setCellValue("I{$row}", $ethnicity);
                $sheet->setCellValue("L{$row}", 'Nguyễn');
                $sheet->setCellValue("M{$row}", 'An');
                $sheet->setCellValue("O{$row}", $code);
                $sheet->setCellValue("P{$row}", 'Thường trú');
                $sheet->setCellValue("AV{$row}", 'Chủ hộ');
            }
            $sheet->getStyle('O6')->getNumberFormat()->setFormatCode('00000');
            $hiddenSheet = $spreadsheet->createSheet(0);
            $hiddenSheet->setTitle('XXXX');
            $hiddenSheet->setSheetState('veryHidden');
            $hiddenSheet->setCellValue('O2', 'Số phiếu');
            $hiddenSheet->setCellValue('AY2', 'Ghi chú');
            IOFactory::createWriter($spreadsheet, 'Xlsx')->save($xlsxPath);
            $spreadsheet->disconnectWorksheets();

            $storedPath = 'household-imports/partial/source.xlsx';
            Storage::disk('local')->put($storedPath, file_get_contents($xlsxPath));
            $importLog = $this->createImportLog($user, [
                'file_name' => 'source.xlsx',
                'file_path' => $storedPath,
                'context' => [
                    'school_year' => $this->currentSchoolYear(),
                    'province_code' => 'P01',
                    'commune_code' => 'C01',
                    'village_code' => 'V01',
                ],
            ]);

            app(HouseholdImportProcessingService::class)->process(
                $importLog,
                $user,
                new UploadedFile($xlsxPath, 'source.xlsx', null, null, true),
            );

            self::assertSame('PARTIAL', $importLog->fresh()->status);
            $this->assertDatabaseHas('households', ['household_code' => 'GOOD']);
            $this->assertDatabaseMissing('households', ['household_code' => 'BAD']);
            $this->assertDatabaseHas('import_logs', [
                'id' => $importLog->id,
                'status' => 'PARTIAL',
                'total_rows' => 2,
                'success_rows' => 1,
                'error_rows' => 1,
            ]);
            self::assertNotNull($importLog->fresh()->error_file_path);

            $errorWorkbook = IOFactory::load(Storage::disk('local')->path($importLog->fresh()->error_file_path));
            $errorSheet = $errorWorkbook->getActiveSheet();
            self::assertSame('Danh sách phiếu điều tra', $errorSheet->getTitle());
            self::assertSame('BAD', $errorSheet->getCell('O5')->getValue());
            self::assertSame(5, $errorSheet->getHighestDataRow());
            self::assertSame(
                '00000',
                $errorSheet->getStyle('O5')->getNumberFormat()->getFormatCode(),
            );
            self::assertStringContainsString('dân tộc', strtolower((string) $errorSheet->getCell('A5')->getValue()));
            $errorWorkbook->disconnectWorksheets();
        } finally {
            if (is_file($xlsxPath)) {
                unlink($xlsxPath);
            }
        }
    }

    public function test_import_processes_one_thousand_valid_rows(): void
    {
        Storage::fake('local');
        $user = $this->userWithPermissions([AccessPermission::IMPORT_HOUSEHOLDS]);
        $temporaryPath = tempnam(sys_get_temp_dir(), 'pcgd-large-import-');
        $xlsxPath = $temporaryPath . '.xlsx';
        rename($temporaryPath, $xlsxPath);

        try {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Danh sách phiếu điều tra');
            $sheet->setCellValue('O2', 'Số phiếu');
            $sheet->setCellValue('AY2', 'Ghi chú');
            for ($row = 5; $row < 1005; $row++) {
                $number = $row - 4;
                $headNumber = $number === 2 ? 1 : $number;
                $sheet->setCellValue("C{$row}", 'Nguyễn');
                $sheet->setCellValue("D{$row}", "Tên{$number}");
                $sheet->setCellValue("E{$row}", 1);
                $sheet->setCellValue("F{$row}", 1);
                $sheet->setCellValue("G{$row}", $number === 1 ? 1980 : 2010);
                $sheet->setCellValue("I{$row}", 'Kinh');
                $sheet->setCellValue("L{$row}", 'Nguyễn');
                $sheet->setCellValue("M{$row}", "Tên{$headNumber}");
                $sheet->setCellValue("O{$row}", sprintf('HH-%05d', $headNumber));
                $sheet->setCellValue("AV{$row}", $number === 2 ? 'Con' : 'Chủ hộ');
                if ($number === 2) {
                    $sheet->setCellValue("S{$row}", 'Lớp 1');
                    $sheet->setCellValue("AJ{$row}", 'x');
                }
            }
            IOFactory::createWriter($spreadsheet, 'Xlsx')->save($xlsxPath);
            $spreadsheet->disconnectWorksheets();

            $storedPath = 'household-imports/large/source.xlsx';
            Storage::disk('local')->put($storedPath, file_get_contents($xlsxPath));
            $importLog = $this->createImportLog($user, [
                'file_name' => 'source.xlsx',
                'file_path' => $storedPath,
                'context' => [
                    'school_year' => $this->currentSchoolYear(),
                    'province_code' => 'P01',
                    'commune_code' => 'C01',
                    'village_code' => 'V01',
                ],
            ]);

            app(HouseholdImportProcessingService::class)->process(
                $importLog,
                $user,
                new UploadedFile($xlsxPath, 'source.xlsx', null, null, true),
            );

            $this->assertDatabaseCount('households', 999);
            $this->assertDatabaseCount('persons', 1000);
            $personId = DB::table('persons')
                ->where('first_name', 'Tên2')
                ->value('id');
            $this->assertDatabaseHas('person_educations', [
                'person_id' => $personId,
                'current_class' => 'Lớp 1',
            ]);
            $this->assertDatabaseHas('person_disabilities', [
                'person_id' => $personId,
                'mobility_disability' => true,
            ]);
            $this->assertDatabaseHas('import_logs', [
                'id' => $importLog->id,
                'status' => 'SUCCESS',
                'total_rows' => 1000,
                'success_rows' => 1000,
                'error_rows' => 0,
            ]);
        } finally {
            if (is_file($xlsxPath)) {
                unlink($xlsxPath);
            }
        }
    }

    public function test_import_form_route_is_not_captured_by_the_import_status_route(): void
    {
        $user = $this->userWithPermissions([AccessPermission::CREATE_IMPORTS]);

        $this->actingAs($user)
            ->getJson('/api/v1/household-imports/form')
            ->assertOk()
            ->assertJsonPath('data.provinces.0.code', 'P01')
            ->assertJsonPath('data.province_code', 'P01')
            ->assertJsonPath('data.commune_code', 'C01');
    }

    public function test_completed_import_jobs_are_not_processed_again_when_retried(): void
    {
        Storage::fake('local');
        $user = $this->userWithPermissions([AccessPermission::IMPORT_HOUSEHOLDS]);
        $importLog = $this->createImportLog($user, [
            'status' => 'SUCCESS',
            'file_path' => 'household-imports/completed/source.xlsx',
        ]);

        (new ProcessHouseholdImport($importLog->id))->handle(
            app(HouseholdImportProcessingService::class)
        );

        $this->assertDatabaseHas('import_logs', [
            'id' => $importLog->id,
            'status' => 'SUCCESS',
        ]);
    }

    public function test_import_error_details_are_spooled_and_counted_per_source_row(): void
    {
        $errors = new HouseholdImportErrorCollector(5000);
        for ($row = 5; $row <= 5000; $row++) {
            $errors->add($row, 'Thiếu thông tin bắt buộc.');
        }
        for ($row = 5; $row <= 104; $row++) {
            $errors->add($row, 'Ngày sinh không hợp lệ.');
        }

        self::assertSame(5096, $errors->errorCount());
        self::assertSame(4996, $errors->errorRowCount());
        self::assertStringContainsString('Thiếu thông tin bắt buộc.', $errors->summary());
        self::assertStringContainsString('Ngày sinh không hợp lệ.', $errors->summary());

        $firstErrors = iterator_to_array($errors->errors());
        self::assertCount(5096, $firstErrors);
        self::assertTrue($firstErrors[0]['first_for_row']);
        self::assertFalse($firstErrors[4996]['first_for_row']);
    }

    public function test_commune_manager_can_create_and_update_villages_only_in_their_commune(): void
    {
        $user = $this->userWithPermissions([AccessPermission::MANAGE_VILLAGES]);

        $this->actingAs($user)
            ->getJson(route('api.villages.create'))
            ->assertOk()
            ->assertJsonPath('data.province_code', 'P01')
            ->assertJsonPath('data.commune_code', 'C01');

        $this->actingAs($user)
            ->postJson(route('api.villages.store'), [
                'name' => 'Thôn Ba',
                'province_code' => 'P01',
                'commune_code' => 'C01',
            ])
            ->assertCreated();

        $village = DB::table('villages')->where('name', 'Thôn Ba')->first();
        $this->assertNotNull($village);
        $this->assertDatabaseHas('villages', [
            'code' => 'C01_001',
            'name' => 'Thôn Ba',
            'commune_code' => 'C01',
        ]);

        $this->putJson(route('api.villages.update', $village->code), [
            'name' => 'Thôn Ba đã sửa',
            'province_code' => 'P01',
            'commune_code' => 'C01',
        ])
            ->assertOk();

        $this->assertDatabaseHas('villages', ['code' => 'C01_001', 'name' => 'Thôn Ba đã sửa']);

        $this->postJson(route('api.villages.store'), [
            'name' => 'Thôn ngoài vùng',
            'province_code' => 'P02',
            'commune_code' => 'C02',
        ])
            ->assertForbidden();

        $this->assertDatabaseMissing('villages', ['name' => 'Thôn ngoài vùng']);
    }

    public function test_village_status_can_be_changed_from_its_edit_form(): void
    {
        $user = $this->userWithPermissions([AccessPermission::MANAGE_VILLAGES]);
        $this->createImportLog($user, [
            'context' => ['village_code' => 'V01'],
            'status' => 'PROCESSING',
        ]);
        $payload = [
            'name' => 'Thôn Một',
            'province_code' => 'P01',
            'commune_code' => 'C01',
            'operational_status' => 'paused',
        ];

        $this->actingAs($user)
            ->putJson('/api/v1/villages/V01', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'operational_status');
        $this->assertDatabaseHas('villages', [
            'code' => 'V01',
            'intake_paused_at' => null,
        ]);

        ImportLog::query()->update(['status' => 'SUCCESS']);
        $this->putJson('/api/v1/villages/V01', $payload)->assertOk();
        $this->assertDatabaseHas('villages', [
            'code' => 'V01',
            'intake_paused_by' => $user->id,
        ]);

        $this->putJson('/api/v1/villages/V01', [
            ...$payload,
            'operational_status' => 'active',
        ])->assertOk();
        $this->assertDatabaseHas('villages', [
            'code' => 'V01',
            'intake_paused_at' => null,
            'intake_paused_by' => null,
        ]);
        $this->getJson('/api/v1/communes/C01/villages')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'V01')
            ->assertJsonPath('data.0.name', 'Thôn Một')
            ->assertJsonPath('data.0.operational_status', 'active')
            ->assertJsonCount(1, 'data');
    }

    public function test_village_name_must_be_unique_in_a_commune_and_generated_codes_use_sequential_numbers(): void
    {
        $user = $this->userWithPermissions([AccessPermission::MANAGE_VILLAGES]);

        $this->actingAs($user)
            ->postJson(route('api.villages.store'), [
                'name' => 'Thôn 1',
                'province_code' => 'P01',
                'commune_code' => 'C01',
            ])
            ->assertCreated();

        $this->postJson(route('api.villages.store'), [
            'name' => 'Thôn 1',
            'province_code' => 'P01',
            'commune_code' => 'C01',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'name');

        $this->postJson(route('api.villages.store'), [
            'name' => 'Thôn-1',
            'province_code' => 'P01',
            'commune_code' => 'C01',
        ])
            ->assertCreated();

        $this->assertDatabaseHas('villages', ['code' => 'C01_001', 'name' => 'Thôn 1']);
        $this->assertDatabaseHas('villages', ['code' => 'C01_002', 'name' => 'Thôn-1']);
    }

    public function test_village_merge_is_limited_to_commune_managers_and_their_commune(): void
    {
        DB::table('villages')->insert([
            'code' => 'V03',
            'name' => 'Thôn Ba',
            'commune_code' => 'C01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $communeManager = $this->userWithPermissions([AccessPermission::VIEW_VILLAGE_MERGES]);

        $this->actingAs($communeManager)
            ->getJson('/api/v1/village-merges')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Thôn Một'])
            ->assertJsonFragment(['name' => 'Thôn Ba'])
            ->assertJsonMissing(['name' => 'Thôn Hai']);

        $systemAdmin = $this->userWithPermissions([], 'global');
        $systemAdminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'guard_name' => 'web',
            'scope_level' => 'global',
        ]);
        $systemAdmin->roles()->attach($systemAdminRole);

        $this->actingAs($systemAdmin)
            ->getJson('/api/v1/village-merges')
            ->assertForbidden();

        $this->actingAs($systemAdmin)
            ->getJson('/api/v1/school-merges')
            ->assertForbidden();
    }

    public function test_global_accounts_cannot_view_or_create_merges_even_if_assigned_merge_permissions(): void
    {
        $user = $this->userWithPermissions([
            AccessPermission::VIEW_VILLAGE_MERGES,
            AccessPermission::CREATE_VILLAGE_MERGES,
            AccessPermission::VIEW_SCHOOL_MERGES,
            AccessPermission::CREATE_SCHOOL_MERGES,
        ], 'global');

        foreach (AccessPermission::mergePermissions() as $permission) {
            $this->assertFalse($user->hasPermissionTo($permission));
            $this->assertNotContains($permission, $user->permissionNames());
        }

        $this->actingAs($user)
            ->getJson('/api/v1/village-merges')
            ->assertForbidden();
        $this->actingAs($user)
            ->postJson('/api/v1/village-merges', [])
            ->assertForbidden();
        $this->actingAs($user)
            ->getJson('/api/v1/school-merges')
            ->assertForbidden();
        $this->actingAs($user)
            ->postJson('/api/v1/school-merges', [])
            ->assertForbidden();
    }
// test admin có được merge thôn trường k
    public function test_global_system_admin_session_omits_school_and_merge_permissions(): void
    {
        $user = User::factory()->create([
            'scope_level' => 'global',
            'is_active' => true,
        ]);
        $systemAdminRole = Role::create([
            'name' => Role::SYSTEM_ADMIN,
            'scope_level' => 'global',
            'guard_name' => 'web',
        ]);
        $systemAdminRole->permissions()->sync([
            Permission::firstOrCreate([
                'name' => AccessPermission::MANAGE_SCHOOLS,
                'guard_name' => 'web',
            ])->id,
            Permission::firstOrCreate([
                'name' => AccessPermission::MANAGE_VILLAGES,
                'guard_name' => 'web',
            ])->id,
        ]);
        $user->roles()->attach($systemAdminRole);

        $permissions = $this->actingAs($user)
            ->getJson('/api/v1/session')
            ->assertOk()
            ->json('data.user.permissions');

        $this->assertContains(AccessPermission::MANAGE_VILLAGES, $permissions);
        foreach ([
            AccessPermission::MANAGE_SCHOOLS,
            AccessPermission::VIEW_SCHOOLS,
            AccessPermission::CREATE_SCHOOLS,
            AccessPermission::UPDATE_SCHOOLS,
            AccessPermission::DELETE_SCHOOLS,
            ...AccessPermission::mergePermissions(),
        ] as $permission) {
            $this->assertNotContains($permission, $permissions);
        }
    }

    public function test_commune_manager_can_merge_villages_and_preserve_households_and_assigned_village_access(): void
    {
        DB::table('villages')->insert([
            'code' => 'V03',
            'name' => 'Thôn Ba',
            'commune_code' => 'C01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = $this->userWithPermissions([
            AccessPermission::VIEW_VILLAGE_MERGES,
            AccessPermission::CREATE_VILLAGE_MERGES,
        ]);
        $activeHousehold = $this->createHousehold([
            'household_code' => '02',
            'village_code' => 'V03',
        ]);
        $person = $this->createPerson($activeHousehold);
        $deletedHousehold = $this->createHousehold([
            'household_code' => '03',
            'village_code' => 'V03',
        ]);
        $deletedHousehold->delete();

        DB::table('user_villages')->insert([
            ['user_id' => $user->id, 'village_code' => 'V03'],
            ['user_id' => $user->id, 'village_code' => 'V01'],
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/village-merges/pause', [
                'source_codes' => ['V03'],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Đã tạm dừng nhận phiếu tại 1 thôn nguồn.');

        $this->postJson('/api/v1/village-merges', [
            'source_codes' => ['V03'],
            'target_code' => 'V01',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Đã gộp 1 thôn nguồn vào thôn đích, xóa thôn nguồn và chuyển 2 hộ cùng toàn bộ nhân khẩu.');

        $this->assertDatabaseHas('villages', ['code' => 'V01']);
        $this->assertDatabaseMissing('villages', ['code' => 'V03']);
        $mergeHistory = DB::table('village_merge_histories')->first();
        self::assertNotNull($mergeHistory);
        self::assertSame(['V03'], json_decode($mergeHistory->source_codes, true));
        self::assertSame('V01', $mergeHistory->target_code);
        $this->assertDatabaseHas('households', [
            'id' => $activeHousehold->id,
            'village_code' => 'V01',
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('households', [
            'id' => $deletedHousehold->id,
            'village_code' => 'V01',
        ]);
        $this->assertDatabaseHas('persons', [
            'id' => $person->id,
            'household_id' => $activeHousehold->id,
        ]);
        $this->assertDatabaseHas('user_villages', [
            'user_id' => $user->id,
            'village_code' => 'V01',
        ]);
        $this->assertDatabaseMissing('user_villages', [
            'user_id' => $user->id,
            'village_code' => 'V03',
        ]);
        $this->assertSame(
            1,
            DB::table('user_villages')
                ->where('user_id', $user->id)
                ->where('village_code', 'V01')
                ->count()
        );
    }

    public function test_village_merge_rejects_the_target_as_a_source_and_villages_outside_the_commune(): void
    {
        DB::table('villages')->insert([
            'code' => 'V03',
            'name' => 'Thôn Ba',
            'commune_code' => 'C01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = $this->userWithPermissions([AccessPermission::CREATE_VILLAGE_MERGES]);

        $this->actingAs($user)
            ->postJson('/api/v1/village-merges', [
                'source_codes' => ['V01'],
                'target_code' => 'V01',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'source_codes.0');

        $this->postJson('/api/v1/village-merges', [
            'source_codes' => ['V02'],
            'target_code' => 'V01',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'source_codes.0');

        $this->assertDatabaseHas('villages', ['code' => 'V01']);
        $this->assertDatabaseHas('villages', ['code' => 'V02']);
        $this->assertDatabaseHas('villages', ['code' => 'V03']);
    }

    public function test_merged_village_can_be_deleted_from_the_village_catalog(): void
    {
        DB::table('villages')->insert([
            'code' => 'V03',
            'name' => 'Thôn đã gộp',
            'commune_code' => 'C01',
            'merged_into_code' => 'V01',
            'merged_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        self::assertSame('paused', Village::query()->findOrFail('V03')->operational_status);
        $user = $this->userWithPermissions([AccessPermission::DELETE_VILLAGES]);

        $this->actingAs($user)
            ->deleteJson('/api/v1/villages/V03')
            ->assertOk()
            ->assertJsonPath('message', 'Đã xóa thôn/bản thành công!');

        $this->assertDatabaseMissing('villages', ['code' => 'V03']);
        $this->assertDatabaseHas('villages', ['code' => 'V01']);
    }

    public function test_active_village_cannot_be_deleted_until_it_is_marked_inactive(): void
    {
        $user = $this->userWithPermissions([AccessPermission::DELETE_VILLAGES]);

        $this->actingAs($user)
            ->deleteJson('/api/v1/villages/V01')
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'village')
            ->assertJsonPath('error.details.0.issue', 'Không thể xóa thôn/xóm đang hoạt động. Hãy chuyển sang trạng thái không hoạt động trước.');

        $this->assertDatabaseHas('villages', ['code' => 'V01']);

        DB::table('villages')->where('code', 'V01')->update(['intake_paused_at' => now()]);
        $this->deleteJson('/api/v1/villages/V01')
            ->assertOk()
            ->assertJsonPath('message', 'Đã xóa thôn/bản thành công!');
        $this->assertDatabaseMissing('villages', ['code' => 'V01']);
    }

    public function test_merge_target_can_be_deleted_with_legacy_sources_while_merge_history_is_preserved(): void
    {
        DB::table('villages')->insert([
            'code' => 'V03',
            'name' => 'Thôn đã gộp',
            'commune_code' => 'C01',
            'merged_into_code' => 'V01',
            'merged_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('villages')->where('code', 'V01')->update(['intake_paused_at' => now()]);
        $historyId = DB::table('village_merge_histories')->insertGetId([
            'commune_code' => 'C01',
            'source_codes' => json_encode(['V03']),
            'source_names' => json_encode(['Thôn đã gộp']),
            'target_code' => 'V01',
            'target_name' => 'Thôn Một',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = $this->userWithPermissions([AccessPermission::DELETE_VILLAGES]);

        $this->actingAs($user)
            ->deleteJson('/api/v1/villages/V01')
            ->assertOk()
            ->assertJsonPath('message', 'Đã xóa thôn/bản thành công!');

        $this->assertDatabaseMissing('villages', ['code' => 'V01']);
        $this->assertDatabaseMissing('villages', ['code' => 'V03']);
        $this->assertDatabaseHas('village_merge_histories', [
            'id' => $historyId,
            'target_code' => 'V01',
            'source_codes' => json_encode(['V03']),
        ]);
    }

    public function test_village_merge_waits_for_active_imports_and_paused_villages_reject_new_households(): void
    {
        DB::table('villages')->insert([
            'code' => 'V03',
            'name' => 'Thôn Ba',
            'commune_code' => 'C01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = $this->userWithPermissions([
            AccessPermission::CREATE_VILLAGE_MERGES,
            AccessPermission::CREATE_HOUSEHOLDS,
        ]);
        $importLog = $this->createImportLog($user, [
            'context' => [
                'school_year' => $this->currentSchoolYear(),
                'province_code' => 'P01',
                'commune_code' => 'C01',
                'village_code' => 'V03',
            ],
            'status' => 'PROCESSING',
        ]);

        $pauseResponse = $this->actingAs($user)
            ->postJson('/api/v1/village-merges/pause', [
                'source_codes' => ['V03'],
            ]);
        $pauseResponse->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'source_codes');
        $this->assertDatabaseHas('villages', [
            'code' => 'V03',
            'intake_paused_at' => null,
        ]);

        $importLog->update(['status' => 'SUCCESS']);
        $this->postJson('/api/v1/village-merges/pause', [
            'source_codes' => ['V03'],
        ])->assertOk();

        $this->postJson('/api/v1/households', [
            'school_year' => $this->currentSchoolYear(),
            'head_first_name' => 'Bình',
            'head_last_name' => 'Trần',
            'province_code' => 'P01',
            'commune_code' => 'C01',
            'village_code' => 'V03',
            'residence_type' => 'THUONG_TRU',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'village_code');

        $this->assertDatabaseCount('households', 0);
    }

    public function test_paused_village_blocks_household_and_person_changes_until_resumed(): void
    {
        DB::table('villages')->insert([
            'code' => 'V03',
            'name' => 'Thôn Ba',
            'commune_code' => 'C01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $household = $this->createHousehold(['village_code' => 'V03']);
        $person = $this->createPerson($household);
        $user = $this->userWithPermissions([
            AccessPermission::CREATE_VILLAGE_MERGES,
            AccessPermission::UPDATE_HOUSEHOLDS,
            AccessPermission::DELETE_HOUSEHOLDS,
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/village-merges/pause', [
                'source_codes' => ['V03'],
            ])
            ->assertOk();

        $updatePayload = [
            'household_code' => $household->household_code,
            'school_year' => $household->school_year,
            'head_first_name' => 'Bình',
            'head_last_name' => 'Trần',
            'province_code' => 'P01',
            'commune_code' => 'C01',
            'village_code' => 'V01',
            'address' => 'Số 2',
            'residence_type' => 'THUONG_TRU',
            'residence_status' => null,
        ];
        $this->putJson("/api/v1/households/{$household->id}", $updatePayload)
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'village_code');

        $this->deleteJson("/api/v1/households/{$household->id}/persons/{$person->id}")
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'village_code');

        $this->assertDatabaseHas('households', [
            'id' => $household->id,
            'village_code' => 'V03',
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('persons', [
            'id' => $person->id,
            'deleted_at' => null,
        ]);

        $this->postJson('/api/v1/village-merges/resume', [
            'source_codes' => ['V03'],
        ])->assertOk();

        $this->putJson("/api/v1/households/{$household->id}", $updatePayload)->assertOk();
        $this->deleteJson("/api/v1/households/{$household->id}/persons/{$person->id}")->assertOk();

        $this->assertDatabaseHas('households', [
            'id' => $household->id,
            'village_code' => 'V01',
            'deleted_at' => null,
        ]);
        $this->assertSoftDeleted('persons', ['id' => $person->id]);
    }

    public function test_province_manager_can_create_and_update_schools_only_in_their_province(): void
    {
        $user = $this->userWithPermissions([AccessPermission::MANAGE_SCHOOLS], 'province');

        $this->actingAs($user)
            ->getJson(route('api.schools.create'))
            ->assertOk()
            ->assertJsonPath('data.provinces.0.code', 'P01');

        $this->actingAs($user)
            ->postJson(route('api.schools.store'), [
                'code' => '',
                'name' => 'Trường Một',
                'type' => 'single_level',
                'level' => 'TH',
                'is_active' => true,
                'province_code' => 'P01',
                'commune_code' => 'C01',
            ])
            ->assertCreated();

        $school = DB::table('schools')->where('name', 'Trường Một')->first();
        $this->assertNotNull($school);
        $this->assertSame('P01_C01M', $school->code);
        $this->assertSame('P01', $school->province_code);

        $this->putJson(route('api.schools.update', $school->id), [
            'name' => 'Trường Một đã sửa',
            'type' => 'single_level',
            'level' => 'THCS',
            'is_active' => true,
            'province_code' => 'P01',
            'commune_code' => 'C01',
        ])
            ->assertOk();

        $this->assertDatabaseHas('schools', [
            'id' => $school->id,
            'name' => 'Trường Một đã sửa',
            'level' => 'THCS',
        ]);

        $this->postJson(route('api.schools.store'), [
            'code' => 'P01_C01M',
            'name' => 'Trường Một đã sửa',
            'type' => 'single_level',
            'level' => 'THCS',
            'is_active' => true,
            'province_code' => 'P01',
            'commune_code' => 'C01',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'name');

        $this->postJson(route('api.schools.store'), [
            'code' => 'P01_C01M',
            'name' => 'Trường Một đã sửa',
            'type' => 'single_level',
            'level' => 'TH',
            'is_active' => true,
            'province_code' => 'P01',
            'commune_code' => 'C01',
        ])
            ->assertCreated();

        $this->assertDatabaseHas('schools', [
            'code' => 'P01_C01MDS',
            'name' => 'Trường Một đã sửa',
            'level' => 'TH',
        ]);

        $this->postJson(route('api.schools.store'), [
            'name' => 'Trường Tự Động',
            'type' => 'single_level',
            'level' => 'TH',
            'is_active' => true,
            'province_code' => 'P01',
            'commune_code' => 'C01',
        ])
            ->assertCreated();

        $this->assertDatabaseHas('schools', [
            'code' => 'P01_C01TD',
            'name' => 'Trường Tự Động',
            'level' => 'TH',
        ]);

        $this->postJson(route('api.schools.store'), [
            'code' => 'P02_C02NT',
            'name' => 'Trường ngoài tỉnh',
            'type' => 'single_level',
            'level' => 'TH',
            'is_active' => true,
            'province_code' => 'P02',
            'commune_code' => 'C02',
        ])->assertForbidden();

        $this->assertDatabaseMissing('schools', ['code' => 'S04']);
    }

    public function test_multi_level_schools_can_only_use_thcs_or_thpt(): void
    {
        $user = $this->userWithPermissions([AccessPermission::MANAGE_SCHOOLS], 'province');

        $response = $this->actingAs($user)
            ->postJson('/api/v1/schools', [
                'name' => 'Trường liên cấp tiểu học',
                'type' => 'multi_level',
                'level' => 'TH',
                'is_active' => true,
                'province_code' => 'P01',
                'commune_code' => 'C01',
            ]);
        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'level')
            ->assertJsonPath('error.details.0.issue', 'Trường liên cấp chỉ được chọn cấp học THCS hoặc THPT.');

        $this->actingAs($user)
            ->postJson('/api/v1/schools', [
                'name' => 'Trường liên cấp THCS',
                'type' => 'multi_level',
                'level' => 'THCS',
                'is_active' => true,
                'province_code' => 'P01',
                'commune_code' => 'C01',
            ])
            ->assertCreated();

        $school = DB::table('schools')->where('name', 'Trường liên cấp THCS')->first();
        $this->assertNotNull($school);

        $this->putJson("/api/v1/schools/{$school->id}", [
            'name' => 'Trường liên cấp THCS',
            'type' => 'multi_level',
            'level' => 'TH',
            'is_active' => true,
            'province_code' => 'P01',
            'commune_code' => 'C01',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'level')
            ->assertJsonPath('error.details.0.issue', 'Trường liên cấp chỉ được chọn cấp học THCS hoặc THPT.');

        $this->assertDatabaseHas('schools', [
            'id' => $school->id,
            'level' => 'THCS',
        ]);
    }

    public function test_school_cannot_be_deleted_while_person_education_uses_it(): void
    {
        $user = $this->userWithPermissions([AccessPermission::MANAGE_SCHOOLS], 'province');
        $schoolId = DB::table('schools')->insertGetId([
            'code' => 'SCHOOL-IN-USE',
            'name' => 'Trường đang sử dụng',
            'is_active' => true,
            'level' => 'TH',
            'province_code' => 'P01',
            'commune_code' => 'C01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($user)
            ->deleteJson(route('api.schools.destroy', $schoolId))
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'school')
            ->assertJsonPath('error.details.0.issue', 'Không thể xóa trường đang hoạt động. Hãy chuyển trường sang trạng thái không hoạt động trước.');
        $this->assertDatabaseHas('schools', ['id' => $schoolId]);

        DB::table('schools')->where('id', $schoolId)->update(['is_active' => false]);
        $household = $this->createHousehold();
        $person = $this->createPerson($household);
        $educationId = DB::table('person_educations')->insertGetId([
            'person_id' => $person->id,
            'school_year_id' => $this->schoolYearId($this->currentSchoolYear()),
            'school_code' => 'SCHOOL-IN-USE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->deleteJson(route('api.schools.destroy', $schoolId))
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'school');
        $this->assertDatabaseHas('schools', ['id' => $schoolId]);
        $this->assertDatabaseHas('person_educations', [
            'id' => $educationId,
            'school_code' => 'SCHOOL-IN-USE',
        ]);

        DB::table('person_educations')->where('id', $educationId)->delete();
        $this->actingAs($user)
            ->deleteJson(route('api.schools.destroy', $schoolId))
            ->assertOk();
        $this->assertSoftDeleted('schools', ['id' => $schoolId]);
    }

    public function test_geography_and_school_lookups_are_limited_to_the_users_area(): void
    {
        DB::table('schools')->insert([
            [
                'code' => 'S01',
                'name' => 'Trường trong xã',
                'type' => 'single_level',
                'level' => 'TH',
                'province_code' => 'P01',
                'commune_code' => 'C01',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'S02',
                'name' => 'Trường ngoài địa bàn',
                'type' => 'single_level',
                'level' => 'TH',
                'province_code' => 'P02',
                'commune_code' => 'C02',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        $user = $this->userWithPermissions([AccessPermission::VIEW_HOUSEHOLDS]);

        $this->actingAs($user)
            ->getJson('/api/v1/provinces/P01/communes')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'C01')
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/provinces/P01/schools')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'S01')
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/provinces/P02/schools')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/communes/C02/villages')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_person_school_search_is_scoped_and_available_to_household_creators(): void
    {
        DB::table('schools')->insert([
            [
                'code' => 'S01',
                'name' => 'Trường trong xã',
                'type' => 'single_level',
                'level' => 'TH',
                'province_code' => 'P01',
                'commune_code' => 'C01',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'S02',
                'name' => 'Trường ngoài địa bàn',
                'type' => 'single_level',
                'level' => 'TH',
                'province_code' => 'P02',
                'commune_code' => 'C02',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'S03',
                'name' => 'Trường THCS cùng tỉnh',
                'type' => 'single_level',
                'level' => 'THCS',
                'province_code' => 'P01',
                'commune_code' => 'C01',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'S04',
                'name' => 'Trường THPT liên cấp cùng tỉnh',
                'type' => 'multi_level',
                'level' => 'THPT',
                'province_code' => 'P01',
                'commune_code' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'S05',
                'name' => 'Trường THPT đơn cấp cùng tỉnh',
                'type' => 'single_level',
                'level' => 'THPT',
                'province_code' => 'P01',
                'commune_code' => 'C01',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'S06',
                'name' => 'Trường Mầm non cùng tỉnh',
                'type' => 'single_level',
                'level' => 'MN',
                'province_code' => 'P01',
                'commune_code' => 'C01',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'S07',
                'name' => 'Trường Mầm non ngoài tỉnh',
                'type' => 'single_level',
                'level' => 'MN',
                'province_code' => 'P02',
                'commune_code' => 'C02',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        $user = $this->userWithPermissions([AccessPermission::CREATE_HOUSEHOLDS]);
        $household = $this->createHousehold();

        $this->actingAs($user)
            ->getJson("/api/v1/households/{$household->id}/persons/schools?q=Trường")
            ->assertOk()
            ->assertJsonCount(5, 'data.schools')
            ->assertJsonFragment(['code' => 'S01']);

        $this->getJson("/api/v1/households/{$household->id}/persons/form?is_head=1")
            ->assertOk()
            ->assertJsonCount(0, 'data.schools');

        $this->getJson("/api/v1/households/{$household->id}/persons/schools?academic_block=8")
            ->assertOk()
            ->assertJsonCount(2, 'data.schools')
            ->assertJsonFragment(['code' => 'S03'])
            ->assertJsonFragment(['code' => 'S04'])
            ->assertJsonMissing(['code' => 'S01'])
            ->assertJsonMissing(['code' => 'S05'])
            ->assertJsonMissing(['code' => 'S02']);

        $this->getJson("/api/v1/households/{$household->id}/persons/schools?school_level=MN")
            ->assertOk()
            ->assertJsonCount(1, 'data.schools')
            ->assertJsonFragment(['code' => 'S06'])
            ->assertJsonMissing(['code' => 'S07']);

        $this->createPerson($household, [
            'dob' => '1980-01-01',
            'relationship_with_head' => 'Chủ hộ',
        ]);
        $payload = $this->personPayload('Con', '2010-01-01');
        $payload['school_province_code'] = 'P01';
        $payload['school_commune_code'] = 'C01';
        $payload['education']['current_class'] = '8A1';
        $payload['education']['academic_block'] = '8';
        $payload['education']['school_code'] = 'S01';

        $this->actingAs($user)
            ->postJson(route('api.households.persons.store', $household), $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'education.school_code');

        $preschoolPayload = $this->personPayload('Con', '2020-01-01');
        $preschoolPayload['school_province_code'] = 'P01';
        $preschoolPayload['school_commune_code'] = 'C01';
        $preschoolPayload['education']['current_class'] = 'MN4A1';
        $preschoolPayload['education']['academic_block'] = '4-5 tuổi';
        $preschoolPayload['education']['school_code'] = 'S01';

        $this->postJson(route('api.households.persons.store', $household), $preschoolPayload)
            ->assertUnprocessable()
            ->assertJsonPath('error.details.0.field', 'education.school_code');

        $preschoolPayload['education']['school_code'] = 'S06';
        $this->postJson(route('api.households.persons.store', $household), $preschoolPayload)
            ->assertCreated();
        $this->assertDatabaseHas('person_educations', [
            'current_class' => 'MN4A1',
            'academic_block' => '4-5 tuổi',
            'school_code' => 'S06',
        ]);
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
            'context' => ['processing_error' => 'Tác vụ gặp lỗi khi đọc file.'],
        ]);
        $partial = $this->createImportLog($user, [
            'status' => 'PARTIAL',
            'success_rows' => 8,
            'error_rows' => 2,
        ]);
        $pending = $this->createImportLog($user, ['status' => 'PROCESSING']);
        $alreadyRead = $this->createImportLog($user, [
            'status' => 'SUCCESS',
            'notification_read_at' => now(),
        ]);
        $foreign = $this->createImportLog($otherUser, ['status' => 'FAILED']);

        $this->actingAs($user)
            ->getJson('/api/v1/household-import-notifications')
            ->assertOk()
            ->assertJsonCount(3, 'data.notifications')
            ->assertJsonFragment([
                'id' => $successful->id,
                'status' => 'SUCCESS',
                'action_label' => 'Xem',
            ])
            ->assertJsonFragment([
                'id' => $failed->id,
                'status' => 'FAILED',
                'action_label' => 'Xem',
                'message' => 'Không thể xử lý file "households.xlsx". Tác vụ gặp lỗi khi đọc file.',
            ])
            ->assertJsonFragment([
                'id' => $partial->id,
                'status' => 'PARTIAL',
                'message' => 'File "households.xlsx" đã xử lý xong: 8 dòng thành công, 2 dòng lỗi.',
            ]);

        $this->patchJson('/api/v1/household-import-notifications/read', [
            'ids' => [$successful->id, $failed->id, $partial->id, $pending->id, $foreign->id],
        ])->assertOk()->assertJsonPath('data.acknowledged', true);

        self::assertNotNull($successful->fresh()->notification_read_at);
        self::assertNotNull($failed->fresh()->notification_read_at);
        self::assertNotNull($partial->fresh()->notification_read_at);
        self::assertNull($pending->fresh()->notification_read_at);
        self::assertNotNull($alreadyRead->fresh()->notification_read_at);
        self::assertNull($foreign->fresh()->notification_read_at);

        $this->getJson('/api/v1/household-import-notifications')
            ->assertOk()
            ->assertJsonPath('data.notifications', []);
    }

    public function test_failed_import_job_sets_a_terminal_status_and_notifies_the_owner(): void
    {
        $user = $this->userWithPermissions([AccessPermission::IMPORT_HOUSEHOLDS]);
        $importLog = $this->createImportLog($user, ['status' => 'PROCESSING']);

        (new ProcessHouseholdImport($importLog->id))->failed(new \RuntimeException('Internal queue failure.'));

        $this->assertDatabaseHas('import_logs', [
            'id' => $importLog->id,
            'status' => 'FAILED',
        ]);
        self::assertSame(
            'Tác vụ nhập dữ liệu kết thúc ngoài dự kiến. Hãy xem nhật ký hệ thống để biết thêm chi tiết.',
            $importLog->fresh()->context['processing_error'],
        );

        $this->actingAs($user)
            ->getJson('/api/v1/household-import-notifications')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $importLog->id,
                'status' => 'FAILED',
                'message' => 'Không thể xử lý file "households.xlsx". Tác vụ nhập dữ liệu kết thúc ngoài dự kiến. Hãy xem nhật ký hệ thống để biết thêm chi tiết.',
            ]);
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('name');
            $table->string('username')->unique();
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
            $table->timestamp('intake_paused_at')->nullable();
            $table->unsignedBigInteger('intake_paused_by')->nullable();
            $table->string('merged_into_code')->nullable();
            $table->timestamp('merged_at')->nullable();
            $table->timestamps();
            $table->unique(['commune_code', 'name'], 'uq_villages_commune_name');
        });
        Schema::create('village_merge_histories', function (Blueprint $table): void {
            $table->id();
            $table->string('commune_code');
            $table->json('source_codes');
            $table->json('source_names');
            $table->string('target_code');
            $table->string('target_name');
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('merged_by')->nullable();
            $table->timestamps();
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
        Schema::create('ethnicities', function (Blueprint $table): void {
            $table->string('code')->primary();
            $table->string('name');
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
            $table->text('address')->nullable();
            $table->string('residence_type')->default('THUONG_TRU');
            $table->string('residence_status')->nullable();
            $table->unsignedBigInteger('import_log_id')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->softDeletes();
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
            $table->unsignedBigInteger('import_log_id')->nullable();
            $table->unsignedBigInteger('school_year_id')->nullable();
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

    private function seedSchoolYears(): void
    {
        foreach (range(2020, 2035) as $year) {
            DB::table('school_years')->insert([
                'start_time' => "{$year}-08-01",
                'end_time' => ($year + 1) . '-07-31',
                'status' => false,
            ]);
        }
    }

    private function schoolYearId(string $label): int
    {
        [$startYear, $endYear] = array_map('intval', explode('-', $label));

        return (int) DB::table('school_years')
            ->whereYear('start_time', $startYear)
            ->whereYear('end_time', $endYear)
            ->value('id');
    }

    private function userWithPermissions(array $permissionNames, string $scopeLevel = 'commune'): User
    {
        $role = Role::create([
            'name' => 'commune-' . uniqid(),
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
            'username' => 'test-' . uniqid(),
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

        return "{$year}-" . ($year + 1);
    }
}
