<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PersonEducationSchoolYearMigrationTest extends TestCase
{
    public function test_it_replaces_the_school_year_label_with_a_foreign_key_and_preserves_existing_rows(): void
    {
        Schema::dropIfExists('person_educations');
        Schema::dropIfExists('school_years');

        Schema::create('school_years', function (Blueprint $table): void {
            $table->id();
            $table->date('start_time');
            $table->date('end_time');
            $table->boolean('status');
        });
        Schema::create('person_educations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('person_id');
            $table->string('school_year', 20);
            $table->unique(['person_id', 'school_year'], 'uq_person_school_year');
            $table->index('school_year', 'idx_education_school_year');
        });
        $educationId = DB::table('person_educations')->insertGetId([
            'person_id' => 1,
            'school_year' => '2025-2026',
        ]);

        $migration = require database_path('migrations/2026_11_06_150000_normalize_person_education_school_year.php');
        $migration->up();

        self::assertFalse(Schema::hasColumn('person_educations', 'school_year'));
        self::assertTrue(Schema::hasColumn('person_educations', 'school_year_id'));
        self::assertContains(
            'school_years',
            array_column(Schema::getForeignKeys('person_educations'), 'foreign_table'),
        );
        self::assertContains(
            'idx_person_education_person',
            array_column(Schema::getIndexes('person_educations'), 'name'),
        );
        self::assertContains(
            'uq_person_school_year',
            array_column(Schema::getIndexes('person_educations'), 'name'),
        );
        self::assertDatabaseHas('person_educations', [
            'id' => $educationId,
            'school_year_id' => 1,
        ]);
        self::assertDatabaseHas('school_years', [
            'id' => 1,
            'start_time' => '2025-08-01',
            'end_time' => '2026-07-31',
        ]);
    }
}
