<?php

use App\Models\Role;
use App\Support\AccessPermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->boolean('is_system')->default(false);
        });

        $managedRoles = [
            [
                'scope_level' => 'province',
                'names' => ['Quản trị cấp tỉnh', 'Quản trị tỉnh'],
                'permissions' => [
                    AccessPermission::permissionSources(AccessPermission::MANAGE_SCHOOLS),
                    AccessPermission::permissionSources(AccessPermission::MANAGE_USER_ACCOUNTS),
                ],
            ],
            [
                'scope_level' => 'commune',
                'names' => ['Quản trị xã', 'Quản trị cấp xã'],
                'permissions' => [
                    AccessPermission::permissionSources(AccessPermission::MANAGE_VILLAGES),
                    AccessPermission::permissionSources(AccessPermission::MANAGE_USER_ACCOUNTS),
                ],
            ],
        ];

        foreach ($managedRoles as $definition) {
            $query = DB::table('roles')
                ->where('guard_name', 'web')
                ->where('scope_level', $definition['scope_level'])
                ->whereIn('name', $definition['names']);

            foreach ($definition['permissions'] as $permissionSources) {
                $query->whereExists(function ($permissions) use ($permissionSources): void {
                    $permissions->selectRaw('1')
                        ->from('role_has_permissions')
                        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
                        ->whereColumn('role_has_permissions.role_id', 'roles.id')
                        ->where('permissions.guard_name', 'web')
                        ->whereIn('permissions.name', $permissionSources);
                });
            }

            $query->update(['is_system' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropColumn('is_system');
        });
    }
};
