<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')->updateOrInsert(
            ['name' => 'manage-villages', 'guard_name' => 'web'],
            ['description' => 'Quản lý danh mục địa bàn (thôn / xóm)', 'updated_at' => now(), 'created_at' => now()]
        );
        $villagePermissionId = DB::table('permissions')
            ->where('name', 'manage-villages')
            ->where('guard_name', 'web')
            ->value('id');
        DB::table('permissions')->updateOrInsert(
            ['name' => 'manage-schools', 'guard_name' => 'web'],
            ['description' => 'Quản lý danh mục trường học', 'updated_at' => now(), 'created_at' => now()]
        );
        $schoolPermissionId = DB::table('permissions')
            ->where('name', 'manage-schools')
            ->where('guard_name', 'web')
            ->value('id');

        $legacyPermissionId = DB::table('permissions')
            ->where('name', 'manage-catalogs')
            ->where('guard_name', 'web')
            ->value('id');

        if ($legacyPermissionId !== null && Schema::hasTable('role_has_permissions')) {
            $roleIds = DB::table('role_has_permissions')
                ->where('permission_id', $legacyPermissionId)
                ->pluck('role_id');
            foreach ($roleIds as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    ['role_id' => $roleId, 'permission_id' => $villagePermissionId],
                    ['role_id' => $roleId, 'permission_id' => $schoolPermissionId],
                ]);
            }
        }

        if ($legacyPermissionId !== null && Schema::hasTable('model_has_permissions')) {
            $assignments = DB::table('model_has_permissions')
                ->where('permission_id', $legacyPermissionId)
                ->get(['model_type', 'model_id', 'assigned_by']);
            foreach ($assignments as $assignment) {
                DB::table('model_has_permissions')->insertOrIgnore([
                    [
                        'permission_id' => $villagePermissionId,
                        'model_type' => $assignment->model_type,
                        'model_id' => $assignment->model_id,
                        'assigned_by' => $assignment->assigned_by,
                    ],
                    [
                        'permission_id' => $schoolPermissionId,
                        'model_type' => $assignment->model_type,
                        'model_id' => $assignment->model_id,
                        'assigned_by' => $assignment->assigned_by,
                    ],
                ]);
            }
        }

        $viewPermissionId = DB::table('permissions')
            ->where('name', 'view-households')
            ->where('guard_name', 'web')
            ->value('id');
        $dependentPermissionIds = DB::table('permissions')
            ->whereIn('name', [
                'create-households',
                'update-households',
                'delete-households',
                'import-households',
                'export-households',
            ])
            ->where('guard_name', 'web')
            ->pluck('id');

        if ($viewPermissionId !== null && Schema::hasTable('role_has_permissions')) {
            $roleIds = DB::table('role_has_permissions')
                ->whereIn('permission_id', $dependentPermissionIds)
                ->distinct()
                ->pluck('role_id');
            foreach ($roleIds as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $viewPermissionId,
                ]);
            }
        }

        if ($viewPermissionId !== null && Schema::hasTable('model_has_permissions')) {
            $assignments = DB::table('model_has_permissions')
                ->whereIn('permission_id', $dependentPermissionIds)
                ->get(['model_type', 'model_id', 'assigned_by']);
            foreach ($assignments as $assignment) {
                DB::table('model_has_permissions')->insertOrIgnore([
                    'permission_id' => $viewPermissionId,
                    'model_type' => $assignment->model_type,
                    'model_id' => $assignment->model_id,
                    'assigned_by' => $assignment->assigned_by,
                ]);
            }
        }

        if ($legacyPermissionId !== null) {
            DB::table('permissions')->where('id', $legacyPermissionId)->delete();
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')->updateOrInsert(
            ['name' => 'manage-catalogs', 'guard_name' => 'web'],
            ['description' => 'Quản lý danh mục địa bàn và trường học', 'updated_at' => now(), 'created_at' => now()]
        );
        $legacyPermissionId = DB::table('permissions')
            ->where('name', 'manage-catalogs')
            ->where('guard_name', 'web')
            ->value('id');
        $catalogPermissionIds = DB::table('permissions')
            ->whereIn('name', ['manage-villages', 'manage-schools'])
            ->where('guard_name', 'web')
            ->pluck('id');

        if (Schema::hasTable('role_has_permissions')) {
            $roleIds = DB::table('role_has_permissions')
                ->whereIn('permission_id', $catalogPermissionIds)
                ->distinct()
                ->pluck('role_id');
            foreach ($roleIds as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $legacyPermissionId,
                ]);
            }
        }

        if (Schema::hasTable('model_has_permissions')) {
            $assignments = DB::table('model_has_permissions')
                ->whereIn('permission_id', $catalogPermissionIds)
                ->get(['model_type', 'model_id', 'assigned_by']);
            foreach ($assignments as $assignment) {
                DB::table('model_has_permissions')->insertOrIgnore([
                    'permission_id' => $legacyPermissionId,
                    'model_type' => $assignment->model_type,
                    'model_id' => $assignment->model_id,
                    'assigned_by' => $assignment->assigned_by,
                ]);
            }
        }

        DB::table('permissions')->whereIn('id', $catalogPermissionIds)->delete();
    }
};
