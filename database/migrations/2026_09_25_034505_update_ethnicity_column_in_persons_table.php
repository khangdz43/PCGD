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
        Schema::table('persons', function (Blueprint $table) {
            $table->dropColumn('ethnicity');

            $table->string('ethnicity_code', 20)->nullable();

            $table->foreign('ethnicity_code')
                ->references('code')
                ->on('ethnicities')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->index('ethnicity_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            //
        });
    }
};
