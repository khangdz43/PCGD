<?php

use App\Support\AccessPermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'name' => AccessPermission::MANAGE_SCHOOLS,
            'guard_name' => 'web',
            'description' => AccessPermission::definitions()[AccessPermission::MANAGE_SCHOOLS],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $schoolPermissionId = DB::table('permissions')
            ->where('name', AccessPermission::MANAGE_SCHOOLS)
            ->where('guard_name', 'web')
            ->value('id');

        $communeAdminRoleIds = DB::table('roles')
            ->join('role_has_permissions as village_permission', 'village_permission.role_id', '=', 'roles.id')
            ->join('permissions as village_permission_definition', 'village_permission_definition.id', '=', 'village_permission.permission_id')
            ->join('role_has_permissions as account_permission', 'account_permission.role_id', '=', 'roles.id')
            ->join('permissions as account_permission_definition', 'account_permission_definition.id', '=', 'account_permission.permission_id')
            ->where('roles.scope_level', 'commune')
            ->where('village_permission_definition.name', AccessPermission::MANAGE_VILLAGES)
            ->where('village_permission_definition.guard_name', 'web')
            ->where('account_permission_definition.name', AccessPermission::MANAGE_USER_ACCOUNTS)
            ->where('account_permission_definition.guard_name', 'web')
            ->distinct()
            ->pluck('roles.id');

        DB::table('role_has_permissions')->insertOrIgnore(
            $communeAdminRoleIds->map(fn (int $roleId): array => [
                'role_id' => $roleId,
                'permission_id' => $schoolPermissionId,
            ])->all(),
        );
    }

    public function down(): void
    {
        // Không thu hồi quyền đã được cấp trong thời gian sử dụng bản migration.
    }
};
