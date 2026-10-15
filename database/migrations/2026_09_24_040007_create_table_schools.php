<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique()->comment('Mã trường học (Duy nhất)');
            $table->string('name', 255)->nullable()->comment('Tên trường học');
            $table->string('level', 50)->nullable()->comment('Cấp học');
            $table->string('province_code', 20)->nullable()->comment('Mã Tỉnh/Thành phố');
            $table->string('commune_code', 20)->nullable()->comment('Mã Xã/Phường');
            $table->timestamps();

            $table->foreign('province_code')
                ->references('code')
                ->on('provinces')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('commune_code')
                ->references('code')
                ->on('communes')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->index(['province_code', 'commune_code'], 'idx_school_location');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schools');
    }
};