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
        Schema::table('import_logs', function (Blueprint $table): void {
            if (Schema::hasColumn('import_logs', 'processing_stage')) {
                $table->dropColumn('processing_stage');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('import_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('import_logs', 'processing_stage')) {
                $table->string('processing_stage', 40)->nullable()->after('status');
            }
        });
    }
};
