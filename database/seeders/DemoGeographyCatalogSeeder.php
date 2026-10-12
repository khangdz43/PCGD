<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoGeographyCatalogSeeder extends Seeder
{
    private const SCHOOL_LEVELS = [
        'MN' => 'Trường Mầm non',
        'TH' => 'Trường Tiểu học',
        'THCS' => 'Trường THCS',
        'THPT' => 'Trường THPT',
    ];

    private const PLACE_NAMES = [
        'Đồng Tâm',
        'Tân Lập',
        'An Bình',
        'Hòa Bình',
        'Bình Minh',
        'Phú Hòa',
        'Phú Lộc',
        'Đông Thành',
        'Nam Sơn',
        'Trung Sơn',
        'Bến Mới',
        'Cầu Mới',
        'Nà Làng',
        'Suối Tre',
        'Đồng Tiến',
        'Tân Phú',
        'Mỹ Hòa',
        'Long Thạnh',
        'Thanh Bình',
        'Bình An',
    ];

    private const NORTHERN_MOUNTAIN_PROVINCES = [
        'Cao Bằng',
        'Điện Biên',
        'Lai Châu',
        'Lạng Sơn',
        'Lào Cai',
        'Sơn La',
        'Thái Nguyên',
        'Tuyên Quang',
    ];

    private const SOUTHERN_PROVINCES = [
        'An Giang',
        'Cà Mau',
        'Cần Thơ',
        'Đồng Nai',
        'Đồng Tháp',
        'Tây Ninh',
        'Vĩnh Long',
    ];

    private const CENTRAL_HIGHLAND_PROVINCES = [
        'Đắk Lắk',
        'Gia Lai',
        'Lâm Đồng',
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Không tạo dữ liệu mẫu danh mục trong môi trường production.');
        }

        $communes = DB::table('communes as c')
            ->join('provinces as p', 'p.code', '=', 'c.province_code')
            ->orderBy('c.code')
            ->get([
                'c.code',
                'c.name',
                'c.province_code',
                'p.name as province_name',
            ]);

        if ($communes->isEmpty()) {
            throw new RuntimeException('Chưa có dữ liệu tỉnh và xã để tạo danh mục mẫu.');
        }

        $existingVillageCodes = array_fill_keys(
            DB::table('villages')->where('code', 'like', 'MPL-VIL-%')->pluck('code')->all(),
            true,
        );
        $existingSchoolCodes = array_fill_keys(
            DB::table('schools')->where('code', 'like', 'MPL-SCH-%')->pluck('code')->all(),
            true,
        );

        $timestamp = now();
        $villages = [];
        $schools = [];

        foreach ($communes as $commune) {
            $code = (string) $commune->code;
            $seed = $this->stableSeed($code);
            $placeName = $this->placeName((string) $commune->name);
            $schoolCount = 2 + ($seed % 3);

            foreach (array_slice(array_keys(self::SCHOOL_LEVELS), 0, $schoolCount) as $level) {
                $schoolCode = 'MPL-SCH-' . $this->safeCode($code) . '-' . $level;

                if (isset($existingSchoolCodes[$schoolCode])) {
                    continue;
                }

                $schools[] = [
                    'code' => $schoolCode,
                    'name' => self::SCHOOL_LEVELS[$level] . ' ' . $placeName,
                    'level' => $level,
                    'province_code' => $commune->province_code,
                    'commune_code' => $code,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            $villageCount = 3 + ($seed % 4);
            $villageType = $this->villageType(
                (string) $commune->name,
                (string) $commune->province_name,
                $seed,
            );
            $nameOffset = $seed % count(self::PLACE_NAMES);

            for ($index = 0; $index < $villageCount; $index++) {
                $villageCode = 'MPL-VIL-' . $this->safeCode($code) . '-' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);

                if (isset($existingVillageCodes[$villageCode])) {
                    continue;
                }

                $name = self::PLACE_NAMES[($nameOffset + $index) % count(self::PLACE_NAMES)];
                $villages[] = [
                    'code' => $villageCode,
                    'name' => $villageType . ' ' . $name,
                    'commune_code' => $code,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }
        }

        DB::transaction(function () use ($villages, $schools): void {
            foreach (array_chunk($villages, 500) as $chunk) {
                DB::table('villages')->insert($chunk);
            }

            foreach (array_chunk($schools, 500) as $chunk) {
                DB::table('schools')->insert($chunk);
            }
        });

        $this->command?->info('Đã tạo ' . count($villages) . ' thôn/tổ dân phố và ' . count($schools) . ' trường mẫu.');
    }

    private function stableSeed(string $value): int
    {
        return crc32($value) & 0x7fffffff;
    }

    private function safeCode(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '';
    }

    private function placeName(string $communeName): string
    {
        return preg_replace('/^(Xã|Phường|Đặc khu|Thị trấn)\s+/u', '', trim($communeName)) ?: trim($communeName);
    }

    private function villageType(string $communeName, string $provinceName, int $seed): string
    {
        $communeName = trim($communeName);
        $provinceName = trim(preg_replace('/^(Tỉnh|Thành phố)\s+/u', '', $provinceName) ?: $provinceName);

        if (preg_match('/^(Phường|Thị trấn|Đặc khu)\b/u', $communeName) === 1) {
            return $seed % 2 === 0 ? 'Tổ dân phố' : 'Khu phố';
        }

        if (in_array($provinceName, self::SOUTHERN_PROVINCES, true)) {
            return 'Ấp';
        }

        if (in_array($provinceName, self::NORTHERN_MOUNTAIN_PROVINCES, true) && $seed % 3 === 0) {
            return 'Bản';
        }

        if (in_array($provinceName, self::CENTRAL_HIGHLAND_PROVINCES, true) && $seed % 3 === 0) {
            return 'Buôn';
        }

        return 'Thôn';
    }
}
