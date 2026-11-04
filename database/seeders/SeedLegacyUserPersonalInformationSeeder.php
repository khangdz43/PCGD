<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class SeedLegacyUserPersonalInformationSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Chỉ được tạo dữ liệu cá nhân mẫu trong môi trường local hoặc testing.');
        }

        $updated = 0;
        User::query()
            ->where(function ($query): void {
                $query->whereNull('citizen_id')
                    ->orWhereNull('date_of_birth')
                    ->orWhereNull('email');
            })
            ->orderBy('id')
            ->chunkById(100, function ($users) use (&$updated): void {
                foreach ($users as $user) {
                    $user->citizen_id ??= '999'.str_pad((string) $user->id, 9, '0', STR_PAD_LEFT);
                    $user->date_of_birth ??= fake()->dateTimeBetween('-75 years', '-18 years')->format('Y-m-d');
                    $user->email ??= "pcgd.user{$user->id}@example.test";
                    $user->save();
                    $updated++;
                }
            });

        $this->command?->info("Đã thêm dữ liệu cá nhân mẫu cho {$updated} tài khoản cũ.");
        $this->command?->warn('CCCD, ngày sinh và email được tạo là dữ liệu giả lập, không phải thông tin định danh thật.');
    }
}
