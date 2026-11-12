<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class SeedMissingUserPhonesSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Chỉ được tạo số điện thoại mẫu trong môi trường local hoặc testing.');
        }

        $updated = 0;
        User::query()
            ->whereNull('phone')
            ->orderBy('id')
            ->chunkById(100, function ($users) use (&$updated): void {
                foreach ($users as $user) {
                    $user->phone = '000'.str_pad((string) $user->id, 7, '0', STR_PAD_LEFT);
                    $user->save();
                    $updated++;
                }
            });

        $this->command?->info("Đã gán số điện thoại mẫu cho {$updated} tài khoản chưa có số điện thoại.");
    }
}
