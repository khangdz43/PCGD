<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('school_years', function (Blueprint $table) {
            $table->id();
            $table->date('start_time');
            $table->date('end_time');
            $table->boolean('status');
        });


        DB::unprepared('
            CREATE TRIGGER trg_school_years_bi 
            BEFORE INSERT ON school_years
            FOR EACH ROW
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM school_years
                    WHERE NEW.start_time <= end_time 
                      AND NEW.end_time >= start_time
                ) THEN
                    SIGNAL SQLSTATE "45000" 
                    SET MESSAGE_TEXT = "Overlap error: Khoảng thời gian năm học bị giao nhau!";
                END IF;
            END
        ');

        DB::unprepared('
            CREATE TRIGGER trg_school_years_bu 
            BEFORE UPDATE ON school_years
            FOR EACH ROW
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM school_years
                    WHERE id != NEW.id 
                      AND NEW.start_time <= end_time 
                      AND NEW.end_time >= start_time
                ) THEN
                    SIGNAL SQLSTATE "45000" 
                    SET MESSAGE_TEXT = "Overlap error: Khoảng thời gian năm học bị giao nhau khi update!";
                END IF;
            END
        ');
    }

    public function down(): void
    {
  
        DB::unprepared('DROP TRIGGER IF EXISTS trg_school_years_bi;');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_school_years_bu;');

        Schema::dropIfExists('school_years');
    }
};