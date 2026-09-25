<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('person_disabilities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('person_id')
                ->unique() // 1- 1 , 1 người thì 1 quan hệ khuyết tật
                ->constrained('persons')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            // khuyết tật
            $table->boolean('mobility_disability')->default(false); // vdong
            $table->boolean('hearing_speech_disability')->default(false); // nghe
            $table->boolean('visual_disability')->default(false);
            $table->boolean('mental_disability')->default(false); // thần kinh
            $table->boolean('intellectual_disability')->default(false); // trí tuệ
            $table->boolean('learning_disability')->default(false); // học tập
            $table->boolean('autism')->default(false); // tự kỉ
            $table->boolean('other_disability')->default(false); // khác
            $table->boolean('has_disability_cert')->default(false); // chứng chỉ 
            // có khả năng học tập không nữa

            // Hhoàn cảnh
            $table->string('special_circumstance', 100)->nullable();
            $table->text('special_circumstance_detail')->nullable();

            $table->timestamps();
        });
    }

  
    public function down(): void
    {
        Schema::dropIfExists('person_disabilities');
    }
};
