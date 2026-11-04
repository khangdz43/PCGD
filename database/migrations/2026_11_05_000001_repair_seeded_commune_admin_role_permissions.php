<?php

use App\Models\Role;
use App\Models\User;
use App\Support\AccessPermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $roles = DB::table('roles')
                ->where('name', 'Quản trị xã')
                ->where('guard_name', 'web')
                ->where('scope_level', 'commune')
                ->whereNotNull('created_by')
                ->whereExists(function ($users): void {
                    $users->selectRaw('1')
                        ->from('model_has_roles')
                        ->join('users', 'users.id', '=', 'model_has_roles.model_id')
                        ->whereColumn('model_has_roles.role_id', 'roles.id')
                        ->where('model_has_roles.model_type', User::class)
                        ->where('users.scope_level', 'commune')
                        ->whereColumn('users.created_by', 'roles.created_by')
                        ->whereColumn('users.province_code', 'roles.province_code')
                        ->where(function ($commune): void {
                            $commune->whereNull('roles.commune_code')
                                ->orWhereColumn('users.commune_code', 'roles.commune_code');
                        });
                })
                ->pluck('id');

            if ($roles->isEmpty()) {
                return;
            }

            $permissionNames = [
                AccessPermission::MANAGE_VILLAGES,
                AccessPermission::MANAGE_USER_ACCOUNTS,
                AccessPermission::MANAGE_ACCESS_CONTROL,
            ];
            $permissions = DB::table('permissions')
                ->where('guard_name', 'web')
                ->whereIn('name', $permissionNames)
                ->get(['id', 'name']);

            $missingNames = array_diff(
                $permissionNames,
                $permissions->pluck('name')->all(),
            );
            if ($missingNames !== []) {
                throw new \RuntimeException(
                    'Không tìm thấy quyền cần thiết để sửa nhóm Quản trị xã: '.implode(', ', $missingNames)
                );
            }

            $schoolPermission = DB::table('permissions')
                ->where('guard_name', 'web')
                ->where('name', AccessPermission::MANAGE_SCHOOLS)
                ->first(['id']);
            if ($schoolPermission === null) {
                throw new \RuntimeException('Không tìm thấy quyền quản lý trường học cho nhóm Quản trị xã.');
            }
            $permissions->push($schoolPermission);

            foreach ($roles as $roleId) {
                foreach ($permissions as $permission) {
                    DB::table('role_has_permissions')->insertOrIgnore([
                        'role_id' => $roleId,
                        'permission_id' => $permission->id,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Không thể khôi phục chính xác quyền của nhóm trước khi được sửa.
    }
};
