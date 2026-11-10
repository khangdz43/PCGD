<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HouseholdSchoolYearMigrationTest extends TestCase
{
    public function test_it_replaces_the_household_school_year_label_with_a_foreign_key(): void
    {
        Schema::create('school_years', function (Blueprint $table): void {
            $table->id();
            $table->date('start_time');
            $table->date('end_time');
            $table->boolean('status');
        });
        Schema::create('households', function (Blueprint $table): void {
            $table->id();
            $table->string('household_code', 50);
            $table->string('school_year', 20)->nullable();
            $table->unique(['school_year', 'household_code'], 'uq_household_school_year_code');
        });

        DB::table('school_years')->insert([
            'start_time' => '2025-08-01',
            'end_time' => '2026-07-31',
            'status' => false,
        ]);
        DB::table('households')->insert([
            ['household_code' => '01', 'school_year' => '2025-2026'],
            ['household_code' => '02', 'school_year' => '2026-2027'],
        ]);

        $migration = require database_path('migrations/2026_11_07_000000_normalize_household_school_year.php');
        $migration->up();

        self::assertFalse(Schema::hasColumn('households', 'school_year'));
        self::assertTrue(Schema::hasColumn('households', 'school_year_id'));
        self::assertSame(
            [1, 2],
            DB::table('households')->orderBy('id')->pluck('school_year_id')->all()
        );
        self::assertTrue(collect(Schema::getForeignKeys('households'))->contains(
            fn (array $foreignKey): bool => $foreignKey['columns'] === ['school_year_id']
                && $foreignKey['foreign_table'] === 'school_years'
        ));
        self::assertTrue(collect(Schema::getIndexes('households'))->contains(
            fn (array $index): bool => $index['name'] === 'uq_household_school_year_code'
                && $index['columns'] === ['school_year_id', 'household_code']
        ));
    }
}