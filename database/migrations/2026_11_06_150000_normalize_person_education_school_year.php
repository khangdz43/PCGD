<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('person_educations', function (Blueprint $table): void {
            $table->unsignedBigInteger('school_year_id')->nullable()->after('school_year');
        });

        $labels = DB::table('person_educations')
            ->select('school_year')
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

            DB::table('person_educations')
                ->where('school_year', $label)
                ->update(['school_year_id' => $schoolYearId]);
        }

        if (DB::table('person_educations')->whereNull('school_year_id')->exists()) {
            throw new RuntimeException('Có hồ sơ học tập chưa liên kết được với năm học.');
        }

        Schema::table('person_educations', function (Blueprint $table): void {
            $table->dropUnique('uq_person_school_year');
            $table->dropIndex('idx_education_school_year');
            $table->dropColumn('school_year');
            $table->unsignedBigInteger('school_year_id')->nullable(false)->change();
            $table->foreign('school_year_id')
                ->references('id')
                ->on('school_years')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->unique(['person_id', 'school_year_id'], 'uq_person_school_year');
            $table->index('school_year_id', 'idx_education_school_year');
        });
    }

    public function down(): void
    {
        Schema::table('person_educations', function (Blueprint $table): void {
            $table->dropForeign(['school_year_id']);
            $table->dropUnique('uq_person_school_year');
            $table->dropIndex('idx_education_school_year');
            $table->string('school_year', 20)->nullable();
        });

        DB::table('person_educations')
            ->join('school_years', 'school_years.id', '=', 'person_educations.school_year_id')
            ->update([
                'person_educations.school_year' => DB::raw(
                    "CONCAT(YEAR(school_years.start_time), '-', YEAR(school_years.end_time))"
                ),
            ]);

        Schema::table('person_educations', function (Blueprint $table): void {
            $table->string('school_year', 20)->nullable(false)->change();
            $table->unique(['person_id', 'school_year'], 'uq_person_school_year');
            $table->index('school_year', 'idx_education_school_year');
            $table->dropColumn('school_year_id');
        });
    }
};
