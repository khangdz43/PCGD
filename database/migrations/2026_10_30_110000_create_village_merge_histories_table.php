<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('village_merge_histories', function (Blueprint $table): void {
            $table->id();
            $table->string('commune_code', 20);
            $table->json('source_codes');
            $table->json('source_names');
            $table->string('target_code', 20);
            $table->string('target_name', 255);
            $table->string('reason', 255)->nullable();
            $table->foreignId('merged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['commune_code', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('village_merge_histories');
    }
};
