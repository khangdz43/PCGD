<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DepartmentGroupSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->warn('Không tạo nhóm quyền dùng chung. Hãy tạo nhóm từ tài khoản quản lý cấp dưới tương ứng.');
    }
}
