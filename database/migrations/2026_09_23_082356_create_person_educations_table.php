<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('person_educations', function (Blueprint $table) {
            $table->id();

         
            $table->foreignId('person_id')
                ->constrained('persons')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->foreignId('import_log_id')
                ->nullable()
                ->constrained('import_logs')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->string('school_year', 20)->comment('2025-2026 năm học');
            $table->string('academic_block', 50)->nullable();
            $table->string('current_class', 50)->nullable();
            $table->string('school_code', 50)->nullable();// khóa ngoại tới cái kia mà chưa có

            // Tốt nghiệp văn hóa
            $table->string('graduation_level', 50)->nullable();
            $table->boolean('is_complementary')->default(false); //bổ túc
            $table->string('graduation_year', 20)->nullable()->comment('2022-2023');

            // Tốt nghiệp nghề
            $table->string('vocational_grad_level', 50)->nullable();
            $table->string('vocational_grad_year', 20)->nullable()->comment('2022-2023');

            // Dở dang / Bỏ học
            $table->string('finished_class', 20)->nullable();
            $table->string('finished_year', 20)->nullable();
            $table->string('dropped_class', 20)->nullable();
            $table->string('dropped_year', 20)->nullable();

            // đánh giá mù chữ 
            $table->string('completed_class', 50)->nullable(); // đã hoàn thành lớp mấy >=3 là ko cần học mù chữ
            $table->string('literacy_relapse_level', 50)->nullable(); // mức độ tái mù chữ
            $table->string('learning_capacity', 100)->nullable(); //  // đang học lớp mù chữ nào

            $table->timestamps();

            // Unique Constraint & Indexes
            $table->unique(['person_id', 'school_year'], 'uq_person_school_year');
            $table->index('school_year', 'idx_education_school_year');
            $table->index('school_code', 'idx_education_school_code');
        });
    }

 
    public function down(): void
    {
        Schema::dropIfExists('person_educations');
    }
};
