<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  
    public function up(): void
    {
        Schema::create('villages', function (Blueprint $table) {
            $table->string('code', 20)->primary();
            $table->string('name', 255);

            $table->string('commune_code', 20);

            $table->foreign('commune_code')
                ->references('code')
                ->on('communes')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->timestamps();

            $table->index('commune_code');
        });
    }

  
    public function down(): void
    {
        Schema::dropIfExists('villages');
    }
};
