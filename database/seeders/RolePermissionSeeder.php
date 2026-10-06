<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\AccessPermission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
                    fn(string $description, string $permission): Permission => Permission::firstOrCreate(
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

            // 4. Khởi tạo các Role Mẫu (Role Templates) theo cấp quản lý
            $roleTemplates = [
                'province-admin' => [
                    'scope_level' => 'province',
                    'description' => 'Quản trị cấp tỉnh và tạo tài khoản cấp xã, thôn, trường trong tỉnh',
                    'permissions' => [
                        AccessPermission::MANAGE_USER_ACCOUNTS,
                        AccessPermission::VIEW_HOUSEHOLDS,
                        AccessPermission::CREATE_HOUSEHOLDS,
                        AccessPermission::UPDATE_HOUSEHOLDS,
                        AccessPermission::DELETE_HOUSEHOLDS,
                    ],
                ],
                'commune-admin' => [
                    'scope_level' => 'commune',
                    'description' => 'Quản trị cấp xã và tạo tài khoản cấp thôn, trường trong xã',
                    'permissions' => [
                        AccessPermission::MANAGE_USER_ACCOUNTS,
                        AccessPermission::VIEW_HOUSEHOLDS,
                        AccessPermission::CREATE_HOUSEHOLDS,
                        AccessPermission::UPDATE_HOUSEHOLDS,
                        AccessPermission::DELETE_HOUSEHOLDS,
                    ],
                ],
                'village-officer' => [
                    'scope_level' => 'village',
                    'description' => 'Cán bộ nhập liệu trong phạm vi thôn/xóm được giao',
                    'permissions' => [
                        AccessPermission::VIEW_HOUSEHOLDS,
                        AccessPermission::CREATE_HOUSEHOLDS,
                        AccessPermission::UPDATE_HOUSEHOLDS,
                    ],
                ],
                'school-officer' => [
                    'scope_level' => 'school',
                    'description' => 'Cán bộ nhập liệu trong phạm vi trường được giao',
                    'permissions' => [
                        AccessPermission::VIEW_HOUSEHOLDS,
                        AccessPermission::CREATE_HOUSEHOLDS,
                        AccessPermission::UPDATE_HOUSEHOLDS,
                    ],
                ],
            ];

            foreach ($roleTemplates as $name => $definition) {
                $templateRole = Role::firstOrCreate(
                    ['name' => $name, 'guard_name' => 'web'],
                    [
                        'scope_level' => $definition['scope_level'],
                        'description' => $definition['description'],
                    ]
                );

                $permissionIds = Permission::whereIn('name', $definition['permissions'])
                    ->where('guard_name', 'web')
                    ->pluck('id');

                $templateRole->permissions()->sync($permissionIds);
            }

            // 5. Khởi tạo tài khoản Super Admin mặc định
            $adminEmail = 'admin@gmail.com';

            $adminUser = User::firstOrCreate(
                ['email' => $adminEmail],
                [
                    'name' => 'System Administrator',
                    'password' => Hash::make('admin123456789'), // Mật khẩu mặc định
                ]
            );

            // Gán role SYSTEM_ADMIN cho tài khoản này
            $adminUser->roles()->sync([$systemAdminRole->id]);

            $this->command->info("Đã khởi tạo thành công bộ phân quyền và tài khoản Admin mặc định ({$adminEmail}).");
        });
    }
}
