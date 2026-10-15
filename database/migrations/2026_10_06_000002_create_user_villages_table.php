<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_villages', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('village_code', 20);
            $table->foreign('village_code')->references('code')->on('villages')->cascadeOnDelete()->cascadeOnUpdate();
            $table->primary(['user_id', 'village_code']);
            $table->index('village_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_villages');
    }
};
