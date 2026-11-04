<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\Education\SchoolMergeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SchoolMergeFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'school_merge_histories',
            'person_educations',
            'import_logs',
            'schools',
            'villages',
            'communes',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('communes', function (Blueprint $table): void {
            $table->string('code')->primary();
            $table->string('province_code');
        });
        Schema::create('villages', function (Blueprint $table): void {
            $table->string('code')->primary();
            $table->string('commune_code');
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
        Schema::create('import_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('status');
            $table->json('context')->nullable();
        });
        Schema::create('person_educations', function (Blueprint $table): void {
            $table->id();
            $table->string('school_code')->nullable();
            $table->string('current_class', 50)->nullable();
            $table->unsignedBigInteger('import_log_id')->nullable();
        });
        Schema::create('school_merge_histories', function (Blueprint $table): void {
            $table->id();
            $table->string('province_code');
            $table->string('commune_code')->nullable();
            $table->json('source_codes');
            $table->json('source_names');
            $table->string('target_code');
            $table->string('target_name');
            $table->string('qd_sap_nhap')->nullable();
            $table->unsignedBigInteger('merged_by')->nullable();
            $table->timestamps();
        });
    }

    public function test_merge_moves_education_renames_all_classes_and_retires_source_schools(): void
    {
        $this->createSchool('TARGET', 'Trường đích');
        $this->createSchool('SRC1', 'Trường nguồn 1', isActive: false);
        $this->createSchool('SRC2', 'Trường nguồn 2', isActive: false);
        $this->createEducation('TARGET', '1A');
        $this->createEducation('SRC1', '1A');
        $this->createEducation('SRC1', '2A');
        $this->createEducation('SRC2', '1A');

        $count = app(SchoolMergeService::class)->merge(
            $this->provinceUser(),
            ['SRC1', 'SRC2'],
            'TARGET',
            '123/QĐ-UBND',
        );

        $this->assertSame(3, $count);
        $this->assertEqualsCanonicalizing(
            ['1A', '1A_SRC1', '2A_SRC1', '1A_SRC2'],
            DB::table('person_educations')->where('school_code', 'TARGET')->pluck('current_class')->all(),
        );
        $this->assertSame(0, DB::table('person_educations')->whereIn('school_code', ['SRC1', 'SRC2'])->count());
        $this->assertFalse(School::withTrashed()->where('code', 'SRC1')->firstOrFail()->trashed());
        $this->assertFalse(School::withTrashed()->where('code', 'SRC2')->firstOrFail()->trashed());
        $this->assertFalse(School::where('code', 'SRC1')->firstOrFail()->is_active);
        $this->assertFalse(School::where('code', 'SRC2')->firstOrFail()->is_active);
        $this->assertDatabaseHas('school_merge_histories', [
            'target_code' => 'TARGET',
            'qd_sap_nhap' => '123/QĐ-UBND',
        ]);
    }

    public function test_merge_is_blocked_while_a_file_import_is_pending_in_a_selected_school_area(): void
    {
        $this->createSchool('TARGET', 'Trường đích', 'TH', 'C01', 'multi_level');
        $this->createSchool('SOURCE', 'Trường nguồn', isActive: false);
        DB::table('villages')->insert(['code' => 'V01', 'commune_code' => 'C01']);
        DB::table('import_logs')->insert([
            'status' => 'PENDING',
            'context' => json_encode([
                'commune_code' => 'C01',
                'village_code' => 'V01',
            ], JSON_THROW_ON_ERROR),
        ]);

        try {
            app(SchoolMergeService::class)->merge($this->provinceUser(), ['SOURCE'], 'TARGET');
            $this->fail('Gộp trường phải bị chặn khi import còn chờ xử lý.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('source_truong_ids', $exception->errors());
        }

        $this->assertDatabaseHas('schools', ['code' => 'SOURCE', 'deleted_at' => null]);
    }

    public function test_active_source_school_must_be_deactivated_before_merge(): void
    {
        $this->createSchool('TARGET', 'Trường đích');
        $this->createSchool('SOURCE', 'Trường nguồn');

        try {
            app(SchoolMergeService::class)->merge($this->provinceUser(), ['SOURCE'], 'TARGET');
            $this->fail('Không được gộp trường nguồn đang hoạt động.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'ngừng hoạt động',
                $exception->errors()['source_truong_ids'][0],
            );
        }

        $this->assertSame(0, DB::table('school_merge_histories')->count());
        $this->assertDatabaseHas('schools', ['code' => 'SOURCE', 'is_active' => true]);
    }

    public function test_mn_th_and_thcs_merges_must_stay_in_the_same_commune(): void
    {
        $this->createSchool('TARGET', 'Trường đích', 'THCS', 'C01', 'multi_level');
        $this->createSchool('SOURCE', 'Trường nguồn', 'THCS', 'C02', isActive: false);

        try {
            app(SchoolMergeService::class)->merge($this->provinceUser(), ['SOURCE'], 'TARGET');
            $this->fail('Không được gộp trường THCS khác xã.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('cùng một xã', $exception->errors()['source_truong_ids'][0]);
        }
    }

    public function test_non_thpt_merge_rejects_schools_without_commune_codes(): void
    {
        $this->createSchool('TARGET', 'Trường đích', 'THCS', null, 'multi_level');
        $this->createSchool('SOURCE', 'Trường nguồn', 'THCS', null, isActive: false);

        try {
            app(SchoolMergeService::class)->merge($this->provinceUser(), ['SOURCE'], 'TARGET');
            $this->fail('Không được gộp trường chưa được gán xã.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('cùng một xã', $exception->errors()['source_truong_ids'][0]);
        }
    }

    public function test_thpt_schools_can_merge_across_communes_within_the_same_province(): void
    {
        $this->createSchool('TARGET', 'Trường đích', 'THPT', 'C01', 'multi_level');
        $this->createSchool('SOURCE', 'Trường nguồn', 'THPT', 'C02', isActive: false);
        $this->createEducation('SOURCE', '10A');

        app(SchoolMergeService::class)->merge($this->provinceUser(), ['SOURCE'], 'TARGET');

        $this->assertDatabaseHas('person_educations', [
            'school_code' => 'TARGET',
            'current_class' => '10A_SOURCE',
        ]);
    }

    public function test_multi_level_target_can_merge_different_general_education_levels(): void
    {
        $this->createSchool('TARGET', 'Trường liên cấp', 'THCS', 'C01', 'multi_level');
        $this->createSchool('SOURCE', 'Trường Tiểu học', 'TH', 'C01', isActive: false);
        $this->createEducation('TARGET', '1A');
        $this->createEducation('SOURCE', '1A');

        app(SchoolMergeService::class)->merge($this->provinceUser(), ['SOURCE'], 'TARGET');

        $this->assertDatabaseHas('person_educations', [
            'school_code' => 'TARGET',
            'current_class' => '1A_SOURCE',
        ]);
    }

    public function test_preschool_cannot_be_merged_into_a_general_education_school(): void
    {
        $this->createSchool('TARGET', 'Trường liên cấp', 'THCS', 'C01', 'multi_level');
        $this->createSchool('SOURCE', 'Trường Mầm non', 'MN', 'C01', isActive: false);

        try {
            app(SchoolMergeService::class)->merge($this->provinceUser(), ['SOURCE'], 'TARGET');
            $this->fail('Không được gộp trường Mầm non vào trường phổ thông.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Mầm non', $exception->errors()['source_truong_ids'][0]);
        }
    }

    public function test_multi_level_preschool_target_can_merge_only_preschool_in_the_same_commune(): void
    {
        $this->createSchool('TARGET', 'Trường Mầm non liên cấp', 'MN', 'C01', 'multi_level');
        $this->createSchool('SOURCE', 'Trường Mầm non nguồn', 'MN', 'C01', isActive: false);
        $this->createEducation('SOURCE', 'Lớp 5 tuổi');

        app(SchoolMergeService::class)->merge($this->provinceUser(), ['SOURCE'], 'TARGET');

        $this->assertDatabaseHas('person_educations', [
            'school_code' => 'TARGET',
            'current_class' => 'Lớp 5 tuổi_SOURCE',
        ]);
    }

    public function test_merge_allows_a_single_level_target(): void
    {
        $this->createSchool('TARGET', 'Trường một cấp', 'TH');
        $this->createSchool('SOURCE', 'Trường nguồn', 'TH', isActive: false);
        $this->createEducation('SOURCE', '1A');

        app(SchoolMergeService::class)->merge($this->provinceUser(), ['SOURCE'], 'TARGET');

        $this->assertDatabaseHas('person_educations', [
            'school_code' => 'TARGET',
            'current_class' => '1A_SOURCE',
        ]);
    }

    public function test_single_level_target_rejects_source_from_a_different_level(): void
    {
        $this->createSchool('TARGET', 'Trường Tiểu học đích', 'TH');
        $this->createSchool('SOURCE', 'Trường THCS nguồn', 'THCS', isActive: false);

        try {
            app(SchoolMergeService::class)->merge($this->provinceUser(), ['SOURCE'], 'TARGET');
            $this->fail('Trường đích một cấp chỉ nhận trường nguồn cùng cấp.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('cùng cấp', $exception->errors()['source_truong_ids'][0]);
        }
    }

    public function test_single_level_target_rejects_multi_level_source_at_the_same_level(): void
    {
        $this->createSchool('TARGET', 'Trường một cấp đích', 'TH');
        $this->createSchool('SOURCE', 'Trường liên cấp nguồn', 'TH', 'C01', 'multi_level', false);

        try {
            app(SchoolMergeService::class)->merge($this->provinceUser(), ['SOURCE'], 'TARGET');
            $this->fail('Trường đích một cấp không nhận trường nguồn liên cấp.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('nguồn một cấp cùng cấp', $exception->errors()['source_truong_ids'][0]);
        }
    }

    public function test_multi_level_target_rejects_sources_above_its_education_level(): void
    {
        $this->createSchool('TARGET', 'Trường liên cấp THCS', 'THCS', 'C01', 'multi_level');
        $this->createSchool('SOURCE', 'Trường THPT nguồn', 'THPT', 'C02', isActive: false);

        try {
            app(SchoolMergeService::class)->merge($this->provinceUser(), ['SOURCE'], 'TARGET');
            $this->fail('Trường liên cấp THCS không nhận trường THPT.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('cấp phổ thông thấp hơn', $exception->errors()['source_truong_ids'][0]);
        }
    }

    public function test_available_source_schools_follow_the_selected_target_level(): void
    {
        $this->createSchool('TARGET', 'Trường liên cấp THCS', 'THCS', 'C01', 'multi_level');
        $this->createSchool('SAME', 'Trường THCS cùng cấp', 'THCS', isActive: false);
        $this->createSchool('LOWER', 'Trường Tiểu học cấp dưới', 'TH', isActive: false);
        $this->createSchool('HIGHER', 'Trường THPT cấp trên', 'THPT', 'C02', isActive: false);

        $sourcePage = app(SchoolMergeService::class)->availableForMerge(
            $this->provinceUser(),
            ['target_code' => 'TARGET'],
        );

        $this->assertEqualsCanonicalizing(['SAME', 'LOWER'], $sourcePage->getCollection()->pluck('code')->all());
    }

    public function test_available_sources_only_include_inactive_schools_and_require_an_active_target(): void
    {
        $this->createSchool('TARGET', 'Trường đích', 'THCS', 'C01', isActive: true);
        $this->createSchool('INACTIVE', 'Trường nguồn đã dừng', 'THCS', 'C01', isActive: false);
        $this->createSchool('ACTIVE', 'Trường nguồn đang hoạt động', 'THCS', 'C01', isActive: true);
        $this->createSchool('INACTIVE_TARGET', 'Trường đích đã dừng', 'THCS', 'C01', isActive: false);

        $service = app(SchoolMergeService::class);
        $sources = $service->availableForMerge($this->provinceUser(), [
            'target_code' => 'TARGET',
        ]);
        $inactiveTargetSources = $service->availableForMerge($this->provinceUser(), [
            'target_code' => 'INACTIVE_TARGET',
        ]);

        $this->assertEqualsCanonicalizing(
            ['INACTIVE', 'INACTIVE_TARGET'],
            $sources->getCollection()->pluck('code')->all(),
        );
        $this->assertFalse($sources->getCollection()->contains('code', 'ACTIVE'));
        $this->assertSame([], $inactiveTargetSources->getCollection()->pluck('code')->all());
    }

    public function test_single_level_target_only_lists_single_level_sources(): void
    {
        $this->createSchool('TARGET', 'Trường một cấp đích', 'TH');
        $this->createSchool('SINGLE', 'Trường một cấp nguồn', 'TH', isActive: false);
        $this->createSchool('MULTI', 'Trường liên cấp nguồn', 'TH', 'C01', 'multi_level', false);

        $sourcePage = app(SchoolMergeService::class)->availableForMerge(
            $this->provinceUser(),
            ['target_code' => 'TARGET'],
        );

        $this->assertSame(['SINGLE'], $sourcePage->getCollection()->pluck('code')->all());
    }

    public function test_available_merge_targets_are_not_limited_to_the_source_list_first_page(): void
    {
        $this->createSchool('SOURCE', 'Trường nguồn', 'TH');
        for ($index = 1; $index <= 31; $index++) {
            $code = sprintf('SINGLE%02d', $index);
            $this->createSchool($code, "A trường một cấp {$index}", 'THCS');
        }
        $this->createSchool('TARGET', 'Z trường một cấp', 'THCS');

        $service = app(SchoolMergeService::class);
        $sourcePage = $service->availableForMerge($this->provinceUser(), ['per_page' => 30]);
        $targetPage = $service->availableForMerge($this->provinceUser(), [
            'q' => 'TARGET',
            'per_page' => 30,
        ]);

        $this->assertFalse($sourcePage->getCollection()->contains('code', 'TARGET'));
        $this->assertSame(['TARGET'], $targetPage->getCollection()->pluck('code')->all());
    }

    private function createSchool(
        string $code,
        string $name,
        string $level = 'TH',
        ?string $communeCode = 'C01',
        string $type = 'single_level',
        bool $isActive = true,
    ): void {
        DB::table('schools')->insert([
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'is_active' => $isActive,
            'level' => $level,
            'province_code' => 'P01',
            'commune_code' => $communeCode,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($communeCode !== null) {
            DB::table('communes')->insertOrIgnore([
                'code' => $communeCode,
                'province_code' => 'P01',
            ]);
        }
    }

    private function createEducation(string $schoolCode, string $class): void
    {
        DB::table('person_educations')->insert([
            'school_code' => $schoolCode,
            'current_class' => $class,
        ]);
    }

    private function provinceUser(): User
    {
        $user = new User;
        $user->setAttribute('scope_level', 'province');
        $user->setAttribute('province_code', 'P01');
        $user->setRelation('roles', collect());

        return $user;
    }
}
