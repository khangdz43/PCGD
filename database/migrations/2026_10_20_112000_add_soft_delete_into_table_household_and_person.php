<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('households', function (Blueprint $table): void {
            $table->softDeletes();
            // xóa bởi ai
            $table->foreignId('deleted_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::table('persons', function (Blueprint $table): void {
            $table->softDeletes();
            $table->foreignId('deleted_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('persons', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropSoftDeletes();
        });

        Schema::table('households', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropSoftDeletes();
        });
    }
};
