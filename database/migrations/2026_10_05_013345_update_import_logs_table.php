<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_logs', function (Blueprint $table) {
            if (Schema::hasColumn('import_logs', 'uploaded_by')) {
                $table->dropColumn('uploaded_by');
            }

            // Tạo FK trỏ sang users.id mặc định của Laravel
            $table->foreignId('user_id')
                ->nullable()
                ->after('file_name')
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('import_logs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');

            $table->string('uploaded_by', 100)->nullable()->after('file_name');
        });
    }
};
