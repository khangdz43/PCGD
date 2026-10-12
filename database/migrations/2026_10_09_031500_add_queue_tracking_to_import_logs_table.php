<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_logs', function (Blueprint $table): void {
            $table->string('file_path')->nullable()->after('user_id');
            $table->json('context')->nullable()->after('file_path');
            $table->unsignedInteger('processed_rows')->default(0)->after('total_rows');
        });
    }

    public function down(): void
    {
        Schema::table('import_logs', function (Blueprint $table): void {
            $table->dropColumn(['file_path', 'context', 'processed_rows']);
        });
    }
};
