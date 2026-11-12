<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $systemAdministratorId = DB::table('roles')
                ->join('model_has_roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('roles.name', Role::SYSTEM_ADMIN)
                ->where('roles.guard_name', 'web')
                ->where('model_has_roles.model_type', User::class)
                ->orderBy('model_has_roles.model_id')
                ->value('model_has_roles.model_id');

            $unownedUsers = DB::table('users')
                ->whereNull('created_by')
                ->when($systemAdministratorId !== null, fn ($users) => $users->where('id', '!=', $systemAdministratorId))
                ->get(['id']);

            foreach ($unownedUsers as $user) {
                $assigners = DB::table('model_has_roles')
                    ->where('model_type', User::class)
                    ->where('model_id', $user->id)
                    ->whereNotNull('assigned_by')
                    ->distinct()
                    ->pluck('assigned_by');
                $ownerId = $assigners->count() === 1 ? $assigners->first() : $systemAdministratorId;

                if ($ownerId === null || ! DB::table('users')->where('id', $ownerId)->exists()) {
                    throw new \RuntimeException("Không xác định được người tạo tài khoản {$user->id}.");
                }

                DB::table('users')->where('id', $user->id)->update(['created_by' => $ownerId]);
            }

            $unownedRoles = DB::table('roles')
                ->whereNull('created_by')
                ->where('name', '!=', Role::SYSTEM_ADMIN)
                ->get(['id', 'name', 'guard_name']);

            foreach ($unownedRoles as $role) {
                $assigners = DB::table('model_has_roles')
                    ->where('role_id', $role->id)
                    ->where('model_type', User::class)
                    ->whereNotNull('assigned_by')
                    ->distinct()
                    ->pluck('assigned_by');
                $ownerId = $assigners->count() === 1 ? $assigners->first() : $systemAdministratorId;

                if ($ownerId === null || ! DB::table('users')->where('id', $ownerId)->exists()) {
                    throw new \RuntimeException("Không xác định được người tạo nhóm quyền {$role->id}.");
                }

                $hasDuplicate = DB::table('roles')
                    ->where('created_by', $ownerId)
                    ->where('name', $role->name)
                    ->where('guard_name', $role->guard_name)
                    ->exists();
                if ($hasDuplicate) {
                    throw new \RuntimeException("Không thể gán chủ sở hữu cho nhóm quyền {$role->id} do trùng tên.");
                }

                DB::table('roles')->where('id', $role->id)->update(['created_by' => $ownerId]);
            }
        });
    }

    public function down(): void
    {
        // Không hoàn tác chủ sở hữu đã được gán cho tài khoản và nhóm quyền.
    }
};
