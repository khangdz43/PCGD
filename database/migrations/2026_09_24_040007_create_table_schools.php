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

            // Mã trường
            $table->string('code', 50)->unique()->comment('Mã trường học (Duy nhất)');
            $table->string('name', 255)->comment('Tên trường học');

            // Cấp học: mầm non , tiểu học ...
            $table->string('level', 50)->nullable()->comment('Cấp học');

            // tỉnh xã của trường
            $table->string('province_code', 20)->nullable()->comment('Mã Tỉnh/Thành phố');
            $table->string('communes_code', 20)->nullable()->comment('Mã Xã/Phường');

          
            $table->timestamps();

            // Khóa ngoại 
            $table->foreign('province_code')
                ->references('code')
                ->on('provinces')
                ->nullOnDelete();

            $table->foreign('communes_code')
                ->references('code')
                ->on('communes')
                ->nullOnDelete();

            // Indexes
            $table->index(['province_code', 'communes_code'], 'idx_school_location');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schools');
    }
};
