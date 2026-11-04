<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('villages', function (Blueprint $table): void {
            $table->timestamp('intake_paused_at')->nullable()->after('commune_code');
            $table->foreignId('intake_paused_by')
                ->nullable()
                ->after('intake_paused_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('merged_into_code', 20)->nullable()->after('intake_paused_by');
            $table->timestamp('merged_at')->nullable()->after('merged_into_code');
            $table->foreign('merged_into_code')
                ->references('code')
                ->on('villages')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->index(['commune_code', 'merged_into_code']);
        });
    }

    public function down(): void
    {
        Schema::table('villages', function (Blueprint $table): void {
            $table->dropForeign(['merged_into_code']);
            $table->dropForeign(['intake_paused_by']);
            $table->dropIndex(['commune_code', 'merged_into_code']);
            $table->dropColumn([
                'intake_paused_at',
                'intake_paused_by',
                'merged_into_code',
                'merged_at',
            ]);
        });
    }
};
