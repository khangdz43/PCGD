<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('school_merge_histories')) {
            return;
        }

        DB::table('school_merge_histories')
            ->select('source_codes')
            ->orderBy('id')
            ->chunk(100, function ($histories): void {
                foreach ($histories as $history) {
                    $sourceCodes = json_decode($history->source_codes, true, flags: JSON_THROW_ON_ERROR);
                    if ($sourceCodes === []) {
                        continue;
                    }

                    DB::table('schools')
                        ->whereIn('code', $sourceCodes)
                        ->update([
                            'is_active' => false,
                            'deleted_at' => null,
                        ]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('school_merge_histories')) {
            return;
        }

        DB::table('school_merge_histories')
            ->select('source_codes')
            ->orderBy('id')
            ->chunk(100, function ($histories): void {
                foreach ($histories as $history) {
                    $sourceCodes = json_decode($history->source_codes, true, flags: JSON_THROW_ON_ERROR);
                    if ($sourceCodes === []) {
                        continue;
                    }

                    DB::table('schools')
                        ->whereIn('code', $sourceCodes)
                        ->update([
                            'is_active' => true,
                            'deleted_at' => now(),
                        ]);
                }
            });
    }
};
