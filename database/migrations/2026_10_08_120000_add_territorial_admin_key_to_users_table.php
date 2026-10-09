<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'territorial_admin_key')) {
                return;
            }

            $table->string('territorial_admin_key')
                ->nullable()
                ->storedAs("CASE WHEN is_active = 1 AND scope_level = 'province' THEN CONCAT('province:', province_code) WHEN is_active = 1 AND scope_level = 'commune' THEN CONCAT('commune:', commune_code) ELSE NULL END")
                ->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'territorial_admin_key')) {
                return;
            }

            $table->dropUnique(['territorial_admin_key']);
            $table->dropColumn('territorial_admin_key');
        });
    }
};
