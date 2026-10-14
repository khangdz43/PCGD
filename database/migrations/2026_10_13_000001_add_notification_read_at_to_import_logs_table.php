<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_logs', function (Blueprint $table): void {
            $table->timestamp('notification_read_at')->nullable();
        });

        DB::table('import_logs')
            ->whereIn('status', ['SUCCESS', 'FAILED'])
            ->update(['notification_read_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('import_logs', function (Blueprint $table): void {
            $table->dropColumn('notification_read_at');
        });
    }
};
