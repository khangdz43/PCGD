<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->foreign('province_code')
                ->references('code')
                ->on('provinces')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('commune_code')
                ->references('code')
                ->on('communes')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('village_code')
                ->references('code')
                ->on('villages')
                ->nullOnDelete()
                ->cascadeOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->dropForeign(['province_code']);
            $table->dropForeign(['commune_code']);
            $table->dropForeign(['village_code']);
        });
    }
};
