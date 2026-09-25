<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

class ProvinceSeeder extends Seeder
{
    public function run(): void
    {
        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'Accept'     => 'application/json',
        ])->get('https://production.cas.so/address-kit/2025-07-01/provinces');

        if ($response->successful()) {
            $json = $response->json();

            $provinces = $json['provinces'] ?? [];
            $data = [];

            foreach ($provinces as $province) {
                if (isset($province['code'], $province['name'])) {
                    $data[] = [
                        'code'       => (string) $province['code'],
                        'name'       => $province['name'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            if (!empty($data)) {
                DB::table('provinces')->upsert($data, ['code'], ['name', 'updated_at']);
                $this->command->info("Đã seed thành công " . count($data) . " Tỉnh/Thành.");
            }
        } else {
            $this->command->error("Lỗi gọi API Tỉnh: Status " . $response->status());
        }
    }
}
