<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            if (! Schema::hasColumn('persons', 'ethnicity_code')) {
                $table->string('ethnicity_code', 20)->nullable()->after('gender');
            }
        });

        $ethnicityMap = DB::table('ethnicities')->pluck('code', 'name')->all();

        foreach (DB::table('persons')->whereNotNull('ethnicity')->get() as $person) {
            $code = $person->ethnicity;
            if ($code === null || $code === '') {
                continue;
            }

            $resolvedCode = $ethnicityMap[$code] ?? null;
            if ($resolvedCode === null) {
                $resolvedCode = $ethnicityMap[trim((string) $code)] ?? null;
            }

            if ($resolvedCode !== null) {
                DB::table('persons')
                    ->where('id', $person->id)
                    ->update(['ethnicity_code' => $resolvedCode]);
            }
        }

        Schema::table('persons', function (Blueprint $table) {
            $table->foreign('ethnicity_code')
                ->references('code')
                ->on('ethnicities')
                ->nullOnDelete()
                ->cascadeOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            $table->dropForeign(['ethnicity_code']);
            $table->dropColumn('ethnicity_code');
        });
    }
};
