<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropUnique('roles_name_guard_name_unique');
            $table->unique(
                ['created_by', 'name', 'guard_name'],
                'roles_creator_name_guard_unique'
            );
        });
    }

    public function down(): void
    {
        $hasDuplicateNames = DB::table('roles')
            ->select('name', 'guard_name')
            ->groupBy('name', 'guard_name')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicateNames) {
            throw new RuntimeException(
                'Không thể khôi phục unique tên nhóm toàn hệ thống khi đang có nhóm trùng tên.'
            );
        }

        Schema::table('roles', function (Blueprint $table): void {
            $table->dropUnique('roles_creator_name_guard_unique');
            $table->unique(['name', 'guard_name']);
        });
    }
};
