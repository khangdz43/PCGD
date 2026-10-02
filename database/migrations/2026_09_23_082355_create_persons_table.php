<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('persons', function (Blueprint $table) {
            $table->id();

            // khóa ngoại xác định chủ hộ và chủ hộ có thể ko cần có trong bảng persons (nếu >60 tuổi sẽ k thuộc diện xóa mù nên chả cần cho ko sao )
            $table->foreignId('household_id')
                ->constrained('households')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->foreignId('import_log_id')
                ->nullable()
                ->constrained('import_logs')
                ->nullOnDelete()
                ->cascadeOnUpdate();


            $table->string('citizen_id', 20)->nullable()->comment('CCCD'); // tự thêm thôi
            $table->string('first_name', 100);
            $table->string('last_name', 50);
            $table->date('dob')->nullable();
            $table->string('dob_str', 20)->nullable()->comment('Lưu nguyên bản chuỗi ngày sinh từ Excel nếu bị sai format');
            $table->enum('gender', ['NAM', 'NU'])->default('NAM');// không cho khác tí sửa lại
            $table->string('ethnicity', 50)->nullable();
            $table->string('religion', 50)->nullable(); //tôn giáo
            $table->string('priority_type', 100)->nullable(); // diện ưu tiên
            $table->string('relationship_with_head', 100)->nullable();
            $table->string('parent_name', 150)->nullable(); 
            $table->string('phone', 20)->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['last_name', 'first_name'], 'idx_person_name');
            $table->index('dob', 'idx_person_dob');
            $table->index('citizen_id', 'idx_person_citizen_id'); //tự thêm cột cccd đấy
        });
    }













    public function down(): void
    {
        Schema::dropIfExists('persons');
    }
};
