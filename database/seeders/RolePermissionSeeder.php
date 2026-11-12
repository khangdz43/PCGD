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
                    fn (string $description, string $permission): Permission => Permission::updateOrCreate(
                        ['name' => $permission, 'guard_name' => 'web'],
                        ['description' => $description],
                    ));

            // 3. Khởi tạo Role SYSTEM_ADMIN (Toàn quyền)
            $systemAdminRole = Role::firstOrCreate(
                ['name' => Role::SYSTEM_ADMIN, 'guard_name' => 'web'],
                [
                    'scope_level' => 'global',
                    'description' => 'Quản trị toàn bộ hệ thống',
                ]
            );
            $systemAdminRole->forceFill(['is_system' => true])->save();
            // Sync tất cả permissions cho SYSTEM_ADMIN
            $systemAdminRole->syncPermissions(
                $permissions
                    ->reject(fn (Permission $permission): bool => in_array(
                        $permission->name,
                        AccessPermission::mergePermissions(),
                        true
                    ))
                    ->pluck('id')
            );

            $centralAdminRole = Role::updateOrCreate(
                ['name' => Role::CENTRAL_ADMIN, 'guard_name' => 'web'],
                [
                    'scope_level' => 'global',
                    'description' => 'Quản trị tài khoản và nhóm người dùng Trung ương, cấp tỉnh',
                    'created_by' => null,
                ]
            );
            $centralAdminRole->forceFill(['is_system' => true])->save();
            $centralAdminRole->syncPermissions(
                $permissions
                    ->filter(fn (Permission $permission): bool => in_array($permission->name, [
                        AccessPermission::MANAGE_ACCESS_CONTROL,
                        AccessPermission::MANAGE_USER_ACCOUNTS,
                        AccessPermission::VIEW_USERS,
                        AccessPermission::CREATE_USERS,
                        AccessPermission::UPDATE_USERS,
                        AccessPermission::DELETE_USERS,
                        AccessPermission::VIEW_ROLES,
                        AccessPermission::CREATE_ROLES,
                        AccessPermission::UPDATE_ROLES,
                        AccessPermission::DELETE_ROLES,
                        AccessPermission::VIEW_ORGANIZATION_ACCOUNTS,
                    ], true))
                    ->pluck('id')
            );

            foreach ([
                [
                    'name' => Role::PROVINCE_ADMIN_NAMES[0],
                    'scope_level' => 'province',
                    'description' => 'Quản trị tài khoản, nhóm người dùng và trường học cấp tỉnh',
                ],
                [
                    'name' => Role::COMMUNE_ADMIN_NAMES[0],
                    'scope_level' => 'commune',
                    'description' => 'Quản trị tài khoản, nhóm người dùng và địa bàn cấp xã',
                ],
            ] as $definition) {
                $role = Role::updateOrCreate(
                    ['name' => $definition['name'], 'guard_name' => 'web'],
                    [
                        'scope_level' => $definition['scope_level'],
                        'description' => $definition['description'],
                        'created_by' => null,
                    ]
                );
                $role->forceFill(['is_system' => true])->save();
                $role->syncPermissions(
                    $permissions
                        ->whereIn(
                            'name',
                            AccessPermission::permissionsForScope($definition['scope_level'])
                        )
                        ->pluck('id')
                );
            }

            $this->command->info('Đã khởi tạo các nhóm quản trị hệ thống.');
        });
    }
}
