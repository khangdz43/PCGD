<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Client\ConnectionException;

class CommuneSeeder extends Seeder
{
    public function run(): void
    {
        $provinceCodes = DB::table('provinces')->pluck('code');

        if ($provinceCodes->isEmpty()) {
            $this->command->error("Bảng provinces chưa có dữ liệu. Vui lòng chạy ProvinceSeeder trước!");
            return;
        }

        foreach ($provinceCodes as $provinceCode) {
            $url = "https://production.cas.so/address-kit/2025-07-01/provinces/{$provinceCode}/communes";

            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                    'Accept'     => 'application/json',
                ])
                    ->retry(3, 1000)
                    ->timeout(30)
                    ->get($url);

                if ($response->successful()) {
                    $json = $response->json();
                    $communes = $json['communes'] ?? (is_array($json) ? $json : []);
                    $data = [];

                    foreach ($communes as $commune) {
                        if (isset($commune['code'], $commune['name'])) {
                            $data[] = [
                                'code'          => (string) $commune['code'],
                                'name'          => $commune['name'],
                                'province_code' => (string) ($commune['provinceCode'] ?? $provinceCode),
                                'created_at'    => now(),
                                'updated_at'    => now(),
                            ];
                        }
                    }

                    if (!empty($data)) {
                        DB::table('communes')->upsert(
                            $data,
                            ['code'],
                            ['name', 'province_code', 'updated_at']
                        );
                        $this->command->info("Đã seed {$provinceCode}: " . count($data) . " xã/phường.");
                    }
                } else {
                    $this->command->warn("Lỗi HTTP {$response->status()} tại tỉnh: {$provinceCode}");
                }
            } catch (\Exception $e) {
                $this->command->error("Bỏ qua tỉnh {$provinceCode} do lỗi kết nối: " . $e->getMessage());
            }

            sleep(1);
        }
    }
}
