<?php

namespace Database\Seeders;

use App\Models\SchoolYear;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoSchoolMergeBaDinhSeeder extends Seeder
{
    private const PROVINCE_CODE = '01';

    private const COMMUNE_CODE = '00004';

    private const SCHOOL_YEAR = '2026-2027';

    private const HOUSEHOLD_CODE = 'DEMO-GOP-TRUONG-BA-DINH';

    private const TARGET_SCHOOL_CODE = '01_00004TAM';

    private const SOURCE_SCHOOL_CODE = '01_00004TI';

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Không chạy dữ liệu mẫu gộp trường trên môi trường production.');
        }

        $result = DB::transaction(function (): string {
            $schoolYearId = SchoolYear::resolveOrCreateFromLabel(self::SCHOOL_YEAR)->id;
            $existingHousehold = DB::table('households')
                ->where('school_year', self::SCHOOL_YEAR)
                ->where('household_code', self::HOUSEHOLD_CODE)
                ->first();

            if ($existingHousehold !== null) {
                return 'Phiếu mẫu đã tồn tại; không thay đổi dữ liệu.';
            }

            $commune = DB::table('communes')
                ->where('code', self::COMMUNE_CODE)
                ->where('province_code', self::PROVINCE_CODE)
                ->first();
            $villageCode = DB::table('villages')
                ->where('commune_code', self::COMMUNE_CODE)
                ->whereNull('merged_into_code')
                ->orderBy('code')
                ->value('code');
            $target = DB::table('schools')->where('code', self::TARGET_SCHOOL_CODE)->first();
            $source = DB::table('schools')->where('code', self::SOURCE_SCHOOL_CODE)->first();

            if ($commune === null || $villageCode === null) {
                throw new RuntimeException('Không tìm thấy phường Ba Đình hoặc thôn đang sử dụng để tạo phiếu mẫu.');
            }

            if (
                $target === null
                || $source === null
                || ! (bool) $target->is_active
                || (bool) $source->is_active
                || $target->level !== 'THCS'
                || $source->level !== 'THCS'
                || $target->commune_code !== self::COMMUNE_CODE
                || $source->commune_code !== self::COMMUNE_CODE
            ) {
                throw new RuntimeException('Không tìm thấy cặp trường THCS đích hoạt động và nguồn không hoạt động tại phường Ba Đình.');
            }

            $now = now();
            $householdId = DB::table('households')->insertGetId([
                'household_code' => self::HOUSEHOLD_CODE,
                'school_year' => self::SCHOOL_YEAR,
                'head_first_name' => 'An',
                'head_last_name' => 'Dữ liệu mẫu',
                'province_code' => self::PROVINCE_CODE,
                'commune_code' => self::COMMUNE_CODE,
                'village_code' => $villageCode,
                'address' => 'Địa chỉ dữ liệu mẫu gộp trường',
                'residence_type' => 'THUONG_TRU',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('persons')->insert([
                'household_id' => $householdId,
                'first_name' => 'An',
                'last_name' => 'Dữ liệu mẫu',
                'gender' => 'NAM',
                'relationship_with_head' => 'Chủ hộ',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $sourceStudentId = DB::table('persons')->insertGetId([
                'household_id' => $householdId,
                'first_name' => 'Học sinh nguồn',
                'last_name' => 'Dữ liệu mẫu',
                'dob' => '2013-05-10',
                'gender' => 'NAM',
                'relationship_with_head' => 'Con',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $targetStudentId = DB::table('persons')->insertGetId([
                'household_id' => $householdId,
                'first_name' => 'Học sinh đích',
                'last_name' => 'Dữ liệu mẫu',
                'dob' => '2012-08-20',
                'gender' => 'NU',
                'relationship_with_head' => 'Con',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('person_educations')->insert([
                [
                    'person_id' => $sourceStudentId,
                    'school_year_id' => $schoolYearId,
                    'academic_block' => 'THCS',
                    'current_class' => '6A',
                    'school_code' => self::SOURCE_SCHOOL_CODE,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'person_id' => $targetStudentId,
                    'school_year_id' => $schoolYearId,
                    'academic_block' => 'THCS',
                    'current_class' => '7A',
                    'school_code' => self::TARGET_SCHOOL_CODE,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            return 'Đã tạo phiếu mẫu tại phường Ba Đình.';
        });

        $this->command?->info($result);
        $this->command?->line('Mã phiếu: '.self::HOUSEHOLD_CODE.' | Trường nguồn: '.self::SOURCE_SCHOOL_CODE.' | Trường đích: '.self::TARGET_SCHOOL_CODE);
    }
}
