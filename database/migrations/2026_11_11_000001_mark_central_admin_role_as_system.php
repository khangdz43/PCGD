<?php

use App\Models\Role;
use App\Support\AccessPermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')
            ->where('name', Role::SYSTEM_ADMIN)
            ->where('guard_name', 'web')
            ->update(['is_system' => true]);

        $this->upsertSystemRole(
            Role::CENTRAL_ADMIN,
            'global',
            'Quản trị tài khoản và nhóm người dùng Trung ương, cấp tỉnh',
            [
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
            ],
        );
        $this->upsertSystemRole(
            Role::PROVINCE_ADMIN_NAMES[0],
            'province',
            'Quản trị tài khoản, nhóm người dùng và trường học cấp tỉnh',
            AccessPermission::permissionsForScope('province'),
        );
        $this->upsertSystemRole(
            Role::COMMUNE_ADMIN_NAMES[0],
            'commune',
            'Quản trị tài khoản, nhóm người dùng và địa bàn cấp xã',
            AccessPermission::permissionsForScope('commune'),
        );
    }

    public function down(): void
    {
        DB::table('roles')
            ->whereIn('name', [
                Role::SYSTEM_ADMIN,
                Role::CENTRAL_ADMIN,
                Role::PROVINCE_ADMIN_NAMES[0],
                Role::COMMUNE_ADMIN_NAMES[0],
            ])
            ->where('guard_name', 'web')
            ->update(['is_system' => false]);
    }

    private function upsertSystemRole(
        string $name,
        string $scopeLevel,
        string $description,
        array $permissionNames,
    ): void {
        $definitions = AccessPermission::definitions();
        $now = now();
        foreach ($permissionNames as $permissionName) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permissionName, 'guard_name' => 'web'],
                [
                    'description' => $definitions[$permissionName],
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }

        $attributes = [
            'scope_level' => $scopeLevel,
            'description' => $description,
            'permission_codes' => json_encode($permissionNames, JSON_THROW_ON_ERROR),
            'created_by' => null,
            'is_system' => true,
            'updated_at' => $now,
        ];
        $role = DB::table('roles')
            ->where('name', $name)
            ->where('guard_name', 'web')
            ->orderByRaw('created_by IS NOT NULL')
            ->first();

        if ($role === null) {
            DB::table('roles')->insert([
                'name' => $name,
                'guard_name' => 'web',
                ...$attributes,
                'created_at' => $now,
            ]);

            return;
        }

        DB::table('roles')->where('id', $role->id)->update($attributes);
    }
};
