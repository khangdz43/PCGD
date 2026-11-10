<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicateAssignments = DB::table('model_has_roles')
            ->select('model_id')
            ->where('model_type', 'App\\Models\\User')
            ->groupBy('model_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        $unsupportedAssignments = DB::table('model_has_roles')
            ->where('model_type', '!=', 'App\\Models\\User')
            ->exists();
        $orphanedAssignments = DB::table('model_has_roles')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('users')
                    ->whereColumn('users.id', 'model_has_roles.model_id');
            })
            ->exists();

        if ($duplicateAssignments || $unsupportedAssignments || $orphanedAssignments) {
            throw new RuntimeException(
                'Không thể chuẩn hóa phân quyền: phát hiện tài khoản có nhiều nhóm, model không hỗ trợ hoặc dữ liệu gán mồ côi.'
            );
        }

        Schema::table('roles', function (Blueprint $table): void {
            $table->json('permission_codes')->nullable();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('role_assigned_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::table('model_has_roles')
            ->where('model_type', 'App\\Models\\User')
            ->orderBy('model_id')
            ->get()
            ->each(function (object $assignment): void {
                DB::table('users')
                    ->where('id', $assignment->model_id)
                    ->update([
                        'role_id' => $assignment->role_id,
                        'role_assigned_by' => $assignment->assigned_by,
                    ]);
            });

        $permissionCodesByRole = DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->select('role_has_permissions.role_id', 'permissions.name')
            ->orderBy('role_has_permissions.role_id')
            ->get()
            ->groupBy('role_id')
            ->map(fn ($permissions) => $permissions->pluck('name')->values()->all());

        DB::table('roles')->orderBy('id')->get(['id'])->each(function (object $role) use ($permissionCodesByRole): void {
            DB::table('roles')
                ->where('id', $role->id)
                ->update([
                    'permission_codes' => json_encode(
                        $permissionCodesByRole->get($role->id, []),
                        JSON_THROW_ON_ERROR
                    ),
                ]);
        });

        Schema::drop('model_has_roles');
        Schema::drop('role_has_permissions');
    }

    public function down(): void
    {
        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('model_type')->default('App\\Models\\User');
            $table->unsignedBigInteger('model_id');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->cascadeOnDelete();
            $table->primary(['role_id', 'model_id', 'model_type']);
            $table->index(['model_id', 'model_type']);
        });

        DB::table('roles')->orderBy('id')->get(['id', 'guard_name', 'permission_codes'])->each(
            function (object $role): void {
                $permissionNames = json_decode($role->permission_codes ?? '[]', true, flags: JSON_THROW_ON_ERROR);
                $permissionIds = DB::table('permissions')
                    ->where('guard_name', $role->guard_name)
                    ->whereIn('name', $permissionNames)
                    ->pluck('id');

                foreach ($permissionIds as $permissionId) {
                    DB::table('role_has_permissions')->insert([
                        'role_id' => $role->id,
                        'permission_id' => $permissionId,
                    ]);
                }
            }
        );

        DB::table('users')
            ->whereNotNull('role_id')
            ->orderBy('id')
            ->get(['id', 'role_id', 'role_assigned_by'])
            ->each(function (object $user): void {
                DB::table('model_has_roles')->insert([
                    'role_id' => $user->role_id,
                    'model_type' => 'App\\Models\\User',
                    'model_id' => $user->id,
                    'assigned_by' => $user->role_assigned_by,
                ]);
            });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['role_id']);
            $table->dropForeign(['role_assigned_by']);
            $table->dropColumn(['role_id', 'role_assigned_by']);
        });

        Schema::table('roles', function (Blueprint $table): void {
            $table->dropColumn('permission_codes');
        });
    }
};
