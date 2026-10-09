<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'village_code') && Schema::hasTable('user_villages')) {
            DB::table('users')
                ->whereNotNull('village_code')
                ->select(['id', 'village_code'])
                ->orderBy('id')
                ->each(function (object $user): void {
                    DB::table('user_villages')->insertOrIgnore([
                        'user_id' => $user->id,
                        'village_code' => $user->village_code,
                    ]);
                });
        }

        $foreignKeys = collect(Schema::getForeignKeys('users'))->pluck('name');
        foreach ([
            'users_province_code_foreign',
            'users_commune_code_foreign',
            'users_village_code_foreign',
            'users_school_code_foreign',
        ] as $foreignKey) {
            if ($foreignKeys->contains($foreignKey)) {
                Schema::table('users', fn (Blueprint $table) => $table->dropForeign($foreignKey));
            }
        }

        $indexes = collect(Schema::getIndexes('users'))->pluck('name');
        foreach (['idx_users_location', 'idx_users_school'] as $index) {
            if ($indexes->contains($index)) {
                Schema::table('users', fn (Blueprint $table) => $table->dropIndex($index));
            }
        }

        $columnsToDrop = array_values(array_filter(
            ['village_code', 'school_code'],
            fn (string $column): bool => Schema::hasColumn('users', $column)
        ));
        if ($columnsToDrop !== []) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn($columnsToDrop));
        }

        if (! collect(Schema::getIndexes('users'))->pluck('name')->contains('idx_users_location')) {
            Schema::table('users', fn (Blueprint $table) => $table->index(
                ['province_code', 'commune_code'],
                'idx_users_location'
            ));
        }

        $foreignKeys = collect(Schema::getForeignKeys('users'))->pluck('name');
        Schema::table('users', function (Blueprint $table) use ($foreignKeys): void {
            if (! $foreignKeys->contains('users_province_code_foreign')) {
                $table->foreign('province_code')
                    ->references('code')->on('provinces')
                    ->nullOnDelete()->cascadeOnUpdate();
            }
            if (! $foreignKeys->contains('users_commune_code_foreign')) {
                $table->foreign('commune_code')
                    ->references('code')->on('communes')
                    ->nullOnDelete()->cascadeOnUpdate();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign('users_province_code_foreign');
            $table->dropForeign('users_commune_code_foreign');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('village_code', 20)->nullable()->after('commune_code');
            $table->string('school_code', 50)->nullable()->after('village_code');
            $table->dropIndex('idx_users_location');
            $table->index(['province_code', 'commune_code', 'village_code'], 'idx_users_location');
            $table->index('school_code', 'idx_users_school');
            $table->foreign('province_code')->references('code')->on('provinces')->nullOnDelete()->cascadeOnUpdate();
            $table->foreign('commune_code')->references('code')->on('communes')->nullOnDelete()->cascadeOnUpdate();
            $table->foreign('village_code')->references('code')->on('villages')->nullOnDelete()->cascadeOnUpdate();
            $table->foreign('school_code')->references('code')->on('schools')->nullOnDelete()->cascadeOnUpdate();
        });

        if (Schema::hasTable('user_villages')) {
            DB::table('user_villages')
                ->select(['user_id', 'village_code'])
                ->orderBy('user_id')
                ->orderBy('village_code')
                ->get()
                ->unique('user_id')
                ->each(function (object $assignment): void {
                    DB::table('users')
                        ->where('id', $assignment->user_id)
                        ->update(['village_code' => $assignment->village_code]);
                });
        }
    }
};
