<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->string('household_code', 50)->comment('Mã hộ gia đình');
            $table->string('school_year', 20)->nullable()->comment('Năm học');
            $table->string('head_first_name', 100)->nullable();
            $table->string('head_last_name', 50)->nullable();
            $table->string('province_code', 20)->nullable()->comment('Mã tỉnh/thành phố');
            $table->string('commune_code', 20)->nullable()->comment('Mã xã/phường');
            $table->string('village_code', 20)->nullable()->comment('Mã thôn/xóm');
            $table->text('address')->nullable();
            $table->enum('residence_type', ['THUONG_TRU', 'TAM_TRU', 'KHAC'])->default('THUONG_TRU');
            $table->string('residence_status', 100)->nullable();
            $table->foreignId('import_log_id')->nullable()->constrained('import_logs')->nullOnDelete()->cascadeOnUpdate();
            $table->timestamps();

            $table->unique(['school_year', 'household_code'], 'uq_household_school_year_code');
            $table->index(['province_code', 'commune_code', 'village_code'], 'idx_household_location');
            $table->index('household_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('households');
    }
};
