<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communes', function (Blueprint $table) {
            // mã xã
            $table->string('code', 20)->primary()->comment('Mã Xã/Phường ');
            $table->string('name', 255)->comment('Tên Xã/Phường');

            //mã tỉnh khóa ngoại
            $table->string('province_code', 20)->comment('Mã Tỉnh quản lý');
            $table->foreign('province_code')
                ->references('code')
                ->on('provinces')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->timestamps();

            // index 2 cột
            $table->index('province_code', 'idx_ward_province');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communes');
    }
};
