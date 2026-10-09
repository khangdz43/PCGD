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
            $table->foreignId('created_by')->nullable()->after('description')->constrained('users')->cascadeOnDelete();
            $table->string('province_code', 20)->nullable()->after('created_by');
            $table->string('commune_code', 20)->nullable()->after('province_code');
            $table->foreign('province_code')->references('code')->on('provinces')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreign('commune_code')->references('code')->on('communes')->cascadeOnDelete()->cascadeOnUpdate();
        });

        DB::table('roles')->where('scope_level', 'school')->update(['scope_level' => 'commune']);
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['province_code']);
            $table->dropForeign(['commune_code']);
            $table->dropColumn(['created_by', 'province_code', 'commune_code']);
        });
    }
};
