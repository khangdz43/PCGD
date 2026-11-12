<?php

use App\Support\AccessPermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('role_has_permissions')
            ->whereIn('role_id', DB::table('roles')
                ->select('id')
                ->where('guard_name', 'web')
                ->where('scope_level', 'global'))
            ->whereIn('permission_id', DB::table('permissions')
                ->select('id')
                ->where('guard_name', 'web')
                ->whereIn('name', AccessPermission::mergePermissions()))
            ->delete();
    }

    public function down(): void
    {
        // Không khôi phục quyền gộp cho nhóm Trung ương khi rollback.
    }
};
