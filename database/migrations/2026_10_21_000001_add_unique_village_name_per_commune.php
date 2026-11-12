<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('villages', function (Blueprint $table): void {
            $table->unique(['commune_code', 'name'], 'uq_villages_commune_name');
        });
    }

    public function down(): void
    {
        Schema::table('villages', function (Blueprint $table): void {
            $table->dropUnique('uq_villages_commune_name');
        });
    }
};
