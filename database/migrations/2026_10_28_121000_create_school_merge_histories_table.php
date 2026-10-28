<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('school_merge_histories')) {
            return;
        }

        Schema::create('school_merge_histories', function (Blueprint $table): void {
            $table->id();
            $table->string('province_code', 20);
            $table->string('commune_code', 20)->nullable();
            $table->json('source_codes');
            $table->json('source_names');
            $table->string('target_code', 20);
            $table->string('target_name', 255);
            $table->string('qd_sap_nhap', 100)->nullable();
            $table->foreignId('merged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['province_code', 'created_at']);
            $table->index(['commune_code', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_merge_histories');
    }
};
