<?php

namespace Tests\Unit\Services\Households;

use App\Models\User;
use App\Services\Households\HouseholdExportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\MockInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class HouseholdExportServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createExportSchema();
    }

    public function test_export_applies_household_filters_and_writes_person_education_and_disability_data(): void
    {
        $this->seedGeographyAndEthnicity();
        $householdId = $this->insertHousehold([
            'household_code' => 'HH-001',
            'school_year' => '2025-2026',
            'head_first_name' => 'An',
            'head_last_name' => 'Nguyễn',
            'province_code' => 'P01',
            'commune_code' => 'C01',
            'village_code' => 'V01',
            'address' => 'Số 1',
            'residence_type' => 'TAM_TRU',
        ]);
        $this->insertPerson($householdId, [
            'first_name' => 'Minh',
            'last_name' => 'Trần',
            'dob' => '2020-05-06',
            'gender' => 'NU',
            'ethnicity_code' => '01',
            'religion' => 'Không',
            'priority_type' => 'Ưu tiên',
            'relationship_with_head' => 'Con',
            'parent_name' => 'Nguyễn An',
            'phone' => '0900000000',
            'note' => 'Ghi chú',
        ], [
            'school_year' => '2025-2026',
            'academic_block' => 'Tiểu học',
            'current_class' => 'Lớp 1',
            'school_code' => 'SCH-01',
            'graduation_level' => 'Tiểu học',
            'is_complementary' => true,
            'graduation_year' => '2030',
            'literacy_current_class' => 'Lớp 1',
            'literacy_completed_class' => 'Lớp 2',
            'literacy_relapse_level' => 'Mức 1',
        ], [
            'mobility_disability' => true,
            'autism' => true,
            'has_disability_cert' => true,
            'can_study' => true,
            'special_circumstance' => 'Khó khăn',
            'special_circumstance_detail' => 'Cần hỗ trợ',
        ]);
        $otherHouseholdId = $this->insertHousehold([
            'household_code' => 'HH-002',
            'school_year' => '2025-2026',
            'province_code' => 'P02',
            'commune_code' => 'C01',
            'village_code' => 'V01',
        ]);
        $this->insertPerson($otherHouseholdId, [
            'first_name' => 'Không',
            'last_name' => 'Được xuất',
        ], [
            'school_year' => '2025-2026',
            'school_code' => 'SCH-01',
        ], [
        ]);

        $actor = new User;
        $actor->setRawAttributes(['province_code' => 'P01']);
        /** @var User&MockInterface $actor */
        $actor = Mockery::mock($actor)->makePartial();
        $actor->shouldReceive('hasRole')->once()->andReturnFalse();
        $actor->shouldReceive('roleScopeLevel')->once()->andReturn('province');

        $workbook = $this->exportedWorkbook($actor, [
            'school_code' => 'SCH-01',
            'school_year' => '2025-2026',
        ], [
            'q' => 'HH-',
            'commune_code' => 'C01',
            'village_code' => 'V01',
        ]);

        $sheet = $workbook->getActiveSheet();
        self::assertSame('HH-001', $sheet->getCell('O5')->getValue());
        self::assertSame('1', $sheet->getCell('B5')->getValue());
        self::assertSame('Trần', $sheet->getCell('C5')->getValue());
        self::assertSame('Minh', $sheet->getCell('D5')->getValue());
        self::assertSame('6', $sheet->getCell('E5')->getValue());
        self::assertSame('5', $sheet->getCell('F5')->getValue());
        self::assertSame('2020', $sheet->getCell('G5')->getValue());
        self::assertSame('X', $sheet->getCell('H5')->getValue());
        self::assertSame('Kinh', $sheet->getCell('I5')->getValue());
        self::assertSame('Nguyễn', $sheet->getCell('L5')->getValue());
        self::assertSame('An', $sheet->getCell('M5')->getValue());
        self::assertSame('Số 1', $sheet->getCell('N5')->getValue());
        self::assertSame('Tạm trú', $sheet->getCell('P5')->getValue());
        self::assertSame('Tiểu học', $sheet->getCell('R5')->getValue());
        self::assertSame('Lớp 1', $sheet->getCell('S5')->getValue());
        self::assertSame('SCH-01', $sheet->getCell('V5')->getValue());
        self::assertSame('X', $sheet->getCell('X5')->getValue());
        self::assertSame('2030', $sheet->getCell('Y5')->getValue());
        self::assertSame('Lớp 1', $sheet->getCell('AG5')->getValue());
        self::assertSame('Lớp 2', $sheet->getCell('AH5')->getValue());
        self::assertSame('X', $sheet->getCell('AJ5')->getValue());
        self::assertSame('X', $sheet->getCell('AP5')->getValue());
        self::assertSame('X', $sheet->getCell('AR5')->getValue());
        self::assertSame('X', $sheet->getCell('AS5')->getValue());
        self::assertSame('Khó khăn', $sheet->getCell('AT5')->getValue());
        self::assertSame('Cần hỗ trợ', $sheet->getCell('AU5')->getValue());
        self::assertSame('Con', $sheet->getCell('AV5')->getValue());
        self::assertSame('Nguyễn An', $sheet->getCell('AW5')->getValue());
        self::assertSame('0900000000', $sheet->getCell('AX5')->getValue());
        self::assertSame('Ghi chú', $sheet->getCell('AY5')->getValue());
        self::assertNull($sheet->getCell('O6')->getValue());

        $workbook->disconnectWorksheets();
    }

    private function exportedWorkbook(User $user, array $filters, array $queryFilters): Spreadsheet
    {
        $response = (new HouseholdExportService)->export($user, $filters, $queryFilters);
        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame('application/vnd.ms-excel', $response->headers->get('Content-Type'));

        ob_start();
        $response->sendContent();
        $contents = ob_get_clean();
        self::assertIsString($contents);
        self::assertNotSame('', $contents);

        $temporaryFile = tempnam(sys_get_temp_dir(), 'pcgd-export-');
        self::assertNotFalse($temporaryFile);

        try {
            file_put_contents($temporaryFile, $contents);

            return IOFactory::createReader('Xls')->load($temporaryFile);
        } finally {
            unlink($temporaryFile);
        }
    }

    private function createExportSchema(): void
    {
        Schema::create('households', function (Blueprint $table): void {
            $table->id();
            $table->string('household_code')->nullable();
            $table->string('school_year')->nullable();
            $table->string('head_first_name')->nullable();
            $table->string('head_last_name')->nullable();
            $table->string('province_code')->nullable();
            $table->string('commune_code')->nullable();
            $table->string('village_code')->nullable();
            $table->string('address')->nullable();
            $table->string('residence_type')->nullable();
            $table->string('residence_status')->nullable();
            $table->timestamps();
        });
        Schema::create('persons', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('household_id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->date('dob')->nullable();
            $table->string('gender')->nullable();
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
            $table->string('school_year')->nullable();
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
            $table->timestamps();
        });
        Schema::create('person_disabilities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('person_id');
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
        Schema::create('ethnicities', function (Blueprint $table): void {
            $table->string('code')->primary();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('provinces', function (Blueprint $table): void {
            $table->string('code')->primary();
            $table->string('name')->nullable();
        });
        Schema::create('communes', function (Blueprint $table): void {
            $table->string('code')->primary();
            $table->string('province_code')->nullable();
            $table->string('name')->nullable();
        });
        Schema::create('villages', function (Blueprint $table): void {
            $table->string('code')->primary();
            $table->string('commune_code')->nullable();
            $table->string('name')->nullable();
        });
    }

    private function seedGeographyAndEthnicity(): void
    {
        DB::table('provinces')->insert([
            ['code' => 'P01', 'name' => 'Tỉnh 1'],
            ['code' => 'P02', 'name' => 'Tỉnh 2'],
        ]);
        DB::table('communes')->insert([
            ['code' => 'C01', 'province_code' => 'P01', 'name' => 'Xã 1'],
            ['code' => 'C02', 'province_code' => 'P02', 'name' => 'Xã 2'],
        ]);
        DB::table('villages')->insert([
            ['code' => 'V01', 'commune_code' => 'C01', 'name' => 'Thôn 1'],
            ['code' => 'V02', 'commune_code' => 'C02', 'name' => 'Thôn 2'],
        ]);
        DB::table('ethnicities')->insert([
            'code' => '01',
            'name' => 'Kinh',
        ]);
    }

    private function insertHousehold(array $attributes): int
    {
        return DB::table('households')->insertGetId(array_merge([
            'head_first_name' => 'An',
            'head_last_name' => 'Nguyễn',
            'province_code' => 'P01',
            'commune_code' => 'C01',
            'village_code' => 'V01',
            'address' => null,
            'residence_type' => 'THUONG_TRU',
            'residence_status' => 'THUONG_TRU',
        ], $attributes));
    }

    private function insertPerson(int $householdId, array $person, array $education, array $disability): void
    {
        $personId = DB::table('persons')->insertGetId(array_merge([
            'household_id' => $householdId,
        ], $person));

        DB::table('person_educations')->insert(array_merge([
            'person_id' => $personId,
        ], $education));
        DB::table('person_disabilities')->insert(array_merge([
            'person_id' => $personId,
        ], $disability));
    }
}
