<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->string('village_code', 20)
                ->nullable()
                ->after('commune_code')
                ->comment('Mã thôn/xóm');

            $table->foreign('village_code')
                ->references('code')
                ->on('villages')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->index('village_code', 'idx_household_village');
        });
    }

    public function down(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->dropForeign(['village_code']);
            $table->dropIndex('idx_household_village');
            $table->dropColumn('village_code');
        });
    }
};
