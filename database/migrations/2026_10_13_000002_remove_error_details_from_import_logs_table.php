<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_logs', function (Blueprint $table): void {
            if (Schema::hasColumn('import_logs', 'error_details')) {
                $table->dropColumn('error_details');
            }
        });
    }

    public function down(): void
    {
        Schema::table('import_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('import_logs', 'error_details')) {
                $table->json('error_details')->nullable()->after('status');
            }
        });
    }
};
