<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('households', 'school_year_id')) {
            Schema::table('households', function (Blueprint $table): void {
                $table->unsignedBigInteger('school_year_id')->nullable()->after('school_year');
            });
        }

        if (Schema::hasColumn('households', 'school_year')) {
            $labels = DB::table('households')
                ->whereNotNull('school_year')
                ->distinct()
                ->pluck('school_year');
            $today = now()->toDateString();

            foreach ($labels as $label) {
                if (! preg_match('/^(\d{4})-(\d{4})$/', (string) $label, $matches)
                    || (int) $matches[2] !== (int) $matches[1] + 1) {
                    throw new RuntimeException("Không thể chuyển năm học '{$label}' sang bảng school_years.");
                }

                $startYear = (int) $matches[1];
                $endYear = (int) $matches[2];
                $schoolYearId = DB::table('school_years')
                    ->whereYear('start_time', $startYear)
                    ->whereYear('end_time', $endYear)
                    ->value('id');

                if ($schoolYearId === null) {
                    $startTime = sprintf('%04d-08-01', $startYear);
                    $endTime = sprintf('%04d-07-31', $endYear);
                    $schoolYearId = DB::table('school_years')->insertGetId([
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                        'status' => $today >= $startTime && $today <= $endTime,
                    ]);
                }

                DB::table('households')
                    ->where('school_year', $label)
                    ->update(['school_year_id' => $schoolYearId]);
            }
        }

        if ($this->hasIndex('households', 'uq_household_school_year_code')) {
            Schema::table('households', function (Blueprint $table): void {
                $table->dropUnique('uq_household_school_year_code');
            });
        }

        if (Schema::hasColumn('households', 'school_year')) {
            Schema::table('households', function (Blueprint $table): void {
                $table->dropColumn('school_year');
            });
        }

        if (! $this->hasForeignKey('households', 'school_year_id')) {
            Schema::table('households', function (Blueprint $table): void {
                $table->foreign('school_year_id')
                    ->references('id')
                    ->on('school_years')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();
            });
        }

        if (! $this->hasIndex('households', 'uq_household_school_year_code')) {
            Schema::table('households', function (Blueprint $table): void {
                $table->unique(['school_year_id', 'household_code'], 'uq_household_school_year_code');
            });
        }
    }

    public function down(): void
    {
        if ($this->hasForeignKey('households', 'school_year_id')) {
            Schema::table('households', function (Blueprint $table): void {
                $table->dropForeign(['school_year_id']);
            });
        }

        if ($this->hasIndex('households', 'uq_household_school_year_code')) {
            Schema::table('households', function (Blueprint $table): void {
                $table->dropUnique('uq_household_school_year_code');
            });
        }

        if (! Schema::hasColumn('households', 'school_year')) {
            Schema::table('households', function (Blueprint $table): void {
                $table->string('school_year', 20)->nullable()->after('household_code');
            });
        }

        DB::table('households')
            ->join('school_years', 'school_years.id', '=', 'households.school_year_id')
            ->update([
                'households.school_year' => DB::raw(
                    "CONCAT(YEAR(school_years.start_time), '-', YEAR(school_years.end_time))"
                ),
            ]);

        if (Schema::hasColumn('households', 'school_year_id')) {
            Schema::table('households', function (Blueprint $table): void {
                $table->dropColumn('school_year_id');
            });
        }

        if (! $this->hasIndex('households', 'uq_household_school_year_code')) {
            Schema::table('households', function (Blueprint $table): void {
                $table->unique(['school_year', 'household_code'], 'uq_household_school_year_code');
            });
        }
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['name'] === $indexName) {
                return true;
            }
        }

        return false;
    }

    private function hasForeignKey(string $table, string $column): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if ($foreignKey['columns'] === [$column]) {
                return true;
            }
        }

        return false;
    }
};