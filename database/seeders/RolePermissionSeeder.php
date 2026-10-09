<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\AccessPermission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RolePermissionSeeder extends Seeder
{
    // seed 1 thằng admin

    public function run(): void
    {

        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) {
            $this->command->error('Bảng roles hoặc permissions chưa tồn tại');

            return;
        }

        DB::transaction(function (): void {
            // lấy ra danh sách các quyền
            $permissions = collect(AccessPermission::definitions())
                ->map(
                    fn (string $description, string $permission): Permission => Permission::firstOrCreate(
                        ['name' => $permission, 'guard_name' => 'web'],
                        ['description' => $description]
                    ));

            // 3. Khởi tạo Role SYSTEM_ADMIN (Toàn quyền)
            $systemAdminRole = Role::firstOrCreate(
                ['name' => Role::SYSTEM_ADMIN, 'guard_name' => 'web'],
                [
                    'scope_level' => 'global',
                    'description' => 'Quản trị toàn bộ hệ thống',
                ]
            );
            // Sync tất cả permissions cho SYSTEM_ADMIN
            $systemAdminRole->permissions()->sync($permissions->pluck('id'));

            $this->command->info('Đã khởi tạo quyền và nhóm quản trị hệ thống.');
        });
    }
}
