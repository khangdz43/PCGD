<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('person_educations', function (Blueprint $table) {
            // 1. Xóa các cột cũ đặt sai ngữ nghĩa/kiểu dữ liệu
            $table->dropColumn(['completed_class', 'literacy_relapse_level', 'learning_capacity']);

            // 2. Thêm lại các cột mới chuẩn ngữ nghĩa nghiệp vụ Xóa Mù Chữ
            $table->string('literacy_current_class', 20)
                ->nullable()
                ->after('dropped_year')
                ->comment('Đang học lớp XMC nào (VD: Lớp 1, Lớp 2...)');

            $table->string('literacy_completed_class', 20)
                ->nullable()
                ->after('literacy_current_class')
                ->comment('Hoàn thành lớp XMC nào');

            $table->tinyInteger('literacy_relapse_level')
                ->nullable()
                ->after('literacy_completed_class')
                ->comment('Tái mù chữ mức: 1 = Mức 1, 2 = Mức 2');

            $table->string('learning_capacity', 50)
                ->nullable()
                ->after('literacy_relapse_level')
                ->comment('Năng lực học tập (Khả năng tiếp thu/Khuyết tật)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('person_educations', function (Blueprint $table) {
            // Xóa các cột mới
            $table->dropColumn([
                'literacy_current_class',
                'literacy_completed_class',
                'literacy_relapse_level',
                'learning_capacity'
            ]);

            // Khôi phục lại các cột cũ
            $table->string('completed_class', 50)->nullable();
            $table->string('literacy_relapse_level', 50)->nullable();
            $table->string('learning_capacity', 100)->nullable();
        });
    }
};
