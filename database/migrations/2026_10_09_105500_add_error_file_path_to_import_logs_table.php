<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_logs', function (Blueprint $table): void {
            $table->string('error_file_path')->nullable()->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('import_logs', function (Blueprint $table): void {
            $table->dropColumn('error_file_path');
        });
    }
};
