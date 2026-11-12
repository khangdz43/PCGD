<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')
            ->where('scope_level', 'village')
            ->update(['scope_level' => 'commune']);

        DB::table('users')
            ->where('scope_level', 'village')
            ->update(['scope_level' => 'commune']);
    }

    public function down(): void
    {
        // Không thể đảo ngược an toàn vì tài khoản xã hiện tại cũng có thể được gán nhiều thôn.
    }
};
