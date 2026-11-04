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

            if ($systemAdministratorId === null) {
                throw new \RuntimeException('Không tìm thấy tài khoản quản trị hệ thống để sửa dữ liệu phân quyền.');
            }

            $assignments = DB::table('model_has_roles')
                ->join('users', 'users.id', '=', 'model_has_roles.model_id')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.model_type', User::class)
                ->whereNotNull('roles.created_by')
                ->where('roles.created_by', '!=', $systemAdministratorId)
                ->whereIn('roles.scope_level', ['province', 'commune'])
                ->select([
                    'model_has_roles.model_id as user_id',
                    'model_has_roles.role_id',
                    'model_has_roles.assigned_by',
                    'users.created_by as user_creator',
                    'users.scope_level as user_scope',
                    'users.province_code as user_province',
                    'users.commune_code as user_commune',
                    'roles.created_by as role_creator',
                    'roles.scope_level as role_scope',
                    'roles.province_code as role_province',
                    'roles.commune_code as role_commune',
                ])
                ->orderBy('model_has_roles.model_id')
                ->orderBy('model_has_roles.role_id')
                ->get();

            $ownershipCandidates = [];
            $eligibleAssignments = [];
            $invalidAssignments = [];

            foreach ($assignments as $assignment) {
                $isOutsideRoleArea = $assignment->user_scope !== $assignment->role_scope
                    || ($assignment->role_province !== null
                        && $assignment->user_province !== $assignment->role_province)
                    || ($assignment->role_commune !== null
                        && $assignment->user_commune !== $assignment->role_commune);

                if ($isOutsideRoleArea) {
                    $invalidAssignments[(int) $assignment->role_id][] = (int) $assignment->user_id;

                    continue;
                }

                if (
                    (int) $assignment->user_creator !== (int) $systemAdministratorId
                    || ($assignment->assigned_by !== null
                        && (int) $assignment->assigned_by !== (int) $systemAdministratorId)
                ) {
                    continue;
                }

                $userId = (int) $assignment->user_id;
                $ownerId = (int) $assignment->role_creator;
                $ownershipCandidates[$userId][$ownerId] = true;
                $eligibleAssignments[$userId][] = [
                    'role_id' => (int) $assignment->role_id,
                    'owner_id' => $ownerId,
                ];
            }

            foreach ($invalidAssignments as $roleId => $userIds) {
                foreach (array_chunk(array_unique($userIds), 500) as $userIdChunk) {
                    DB::table('model_has_roles')
                        ->where('model_type', User::class)
                        ->where('role_id', $roleId)
                        ->whereIn('model_id', $userIdChunk)
                        ->delete();
                }
            }

            foreach ($ownershipCandidates as $userId => $ownerIds) {
                if (count($ownerIds) !== 1) {
                    continue;
                }

                $ownerId = (int) array_key_first($ownerIds);
                DB::table('users')
                    ->where('id', $userId)
                    ->where('created_by', $systemAdministratorId)
                    ->update(['created_by' => $ownerId]);

                foreach ($eligibleAssignments[$userId] as $assignment) {
                    if ($assignment['owner_id'] !== $ownerId) {
                        continue;
                    }

                    DB::table('model_has_roles')
                        ->where('model_type', User::class)
                        ->where('model_id', $userId)
                        ->where('role_id', $assignment['role_id'])
                        ->where(function ($query) use ($systemAdministratorId): void {
                            $query->whereNull('assigned_by')
                                ->orWhere('assigned_by', $systemAdministratorId);
                        })
                        ->update(['assigned_by' => $ownerId]);
                }
            }
        });
    }

    public function down(): void
    {
        // Không thể khôi phục chính xác người tạo và nhóm đã gỡ khỏi địa bàn không hợp lệ.
    }
};
