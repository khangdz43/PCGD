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
            $table->string('household_code', 50)->comment('mã hộ , 1 hộ có nhiều người trong nhà í');
            $table->string('head_first_name', 100)->nullable(); // tên chủ hộ
            $table->string('head_last_name', 50)->nullable(); //họ
            // thêm trường thành phố , xã, thôn 
            $table->text('address')->nullable(); // này lưu địa chỉ kiểu số nhà ....
            $table->enum('residence_type', ['THUONG_TRU', 'TAM_TRU', 'KHAC'])->default('THUONG_TRU');
            $table->string('residence_status', 100)->nullable(); // tình trạng cư chú (đang ở , đã chuyển đi)
            $table->foreignId('import_log_id')  // cột import_log_id lket cọt id bảng import_logs
                ->nullable()
                ->constrained('import_logs')
                ->nullOnDelete() // cha bị xóa thì cũng xóa
                ->cascadeOnUpdate(); // nếu id bên bảng kia thay đổi bên này cũng tự đổi theo
            $table->timestamps(); //tạo  2 cột create_at và update_at
            $table->index('household_code'); // đánh index cho cột household_code
        });
    }



 
    
    public function down(): void
    {
        Schema::dropIfExists('households');
    }
};
