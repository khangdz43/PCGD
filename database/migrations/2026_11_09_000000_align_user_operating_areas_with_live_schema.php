<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_operating_areas')) {
            Schema::create('user_operating_areas', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('province_code', 20);
                $table->string('commune_code', 20)->nullable();
                $table->string('village_code', 20)->nullable();
                $table->unique(
                    ['user_id', 'province_code', 'commune_code', 'village_code'],
                    'user_operating_areas_assignment_unique',
                );
                $table->index(
                    ['province_code', 'commune_code', 'village_code'],
                    'user_operating_areas_location_index',
                );
                $table->foreign('province_code')
                    ->references('code')
                    ->on('provinces')
                    ->cascadeOnDelete()
                    ->cascadeOnUpdate();
                $table->foreign('commune_code')
                    ->references('code')
                    ->on('communes')
                    ->cascadeOnDelete()
                    ->cascadeOnUpdate();
                $table->foreign('village_code')
                    ->references('code')
                    ->on('villages')
                    ->cascadeOnDelete()
                    ->cascadeOnUpdate();
            });

        }

        $this->migrateLegacyLocations();
        if (Schema::hasTable('user_villages')) {
            $this->migrateLegacyVillages();
        }

        Schema::dropIfExists('user_villages');
        $this->dropLegacyColumns('users', ['province_code', 'commune_code', 'scope_level']);
        $this->dropLegacyColumns('roles', ['province_code', 'commune_code']);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'province_code')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('province_code', 20)->nullable();
                $table->string('commune_code', 20)->nullable();
                $table->string('scope_level', 20)->nullable();
                $table->index(['province_code', 'commune_code'], 'idx_users_location');
            });
        }

        if (Schema::hasTable('user_operating_areas')) {
            DB::table('users')
                ->whereExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('user_operating_areas')
                        ->whereColumn('user_operating_areas.user_id', 'users.id');
                })
                ->update([
                    'province_code' => DB::raw('(SELECT province_code FROM user_operating_areas WHERE user_operating_areas.user_id = users.id ORDER BY id LIMIT 1)'),
                    'commune_code' => DB::raw('(SELECT commune_code FROM user_operating_areas WHERE user_operating_areas.user_id = users.id ORDER BY id LIMIT 1)'),
                    'scope_level' => DB::raw("(SELECT CASE WHEN commune_code IS NULL THEN 'province' ELSE 'commune' END FROM user_operating_areas WHERE user_operating_areas.user_id = users.id ORDER BY id LIMIT 1)"),
                ]);

            if (! Schema::hasTable('user_villages')) {
                Schema::create('user_villages', function (Blueprint $table): void {
                    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                    $table->string('village_code', 20);
                    $table->foreign('village_code')
                        ->references('code')
                        ->on('villages')
                        ->cascadeOnDelete()
                        ->cascadeOnUpdate();
                    $table->primary(['user_id', 'village_code']);
                    $table->index('village_code');
                });
            }

            DB::table('user_operating_areas')
                ->whereNotNull('village_code')
                ->orderBy('id')
                ->get(['user_id', 'village_code'])
                ->each(function (object $area): void {
                    DB::table('user_villages')->insertOrIgnore([
                        'user_id' => $area->user_id,
                        'village_code' => $area->village_code,
                    ]);
                });
        }

        if (! Schema::hasColumn('roles', 'province_code')) {
            Schema::table('roles', function (Blueprint $table): void {
                $table->string('province_code', 20)->nullable();
                $table->string('commune_code', 20)->nullable();
            });
        }
    }

    private function migrateLegacyLocations(): void
    {
        if (! Schema::hasColumn('users', 'province_code')) {
            return;
        }

        $users = DB::table('users')->whereNotNull('province_code');
        if (Schema::hasTable('user_villages')) {
            $users->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('user_villages')
                    ->whereColumn('user_villages.user_id', 'users.id');
            });
        }

        $users->orderBy('id')->chunkById(500, function ($users): void {
            $areas = $users->map(fn (object $user): array => [
                'user_id' => $user->id,
                'province_code' => $user->province_code,
                'commune_code' => $user->commune_code,
                'village_code' => null,
            ])->all();

            if ($areas !== []) {
                DB::table('user_operating_areas')->insertOrIgnore($areas);
            }
        });
    }

    private function migrateLegacyVillages(): void
    {
        DB::table('user_villages')
            ->join('users', 'users.id', '=', 'user_villages.user_id')
            ->orderBy('user_villages.user_id')
            ->orderBy('user_villages.village_code')
            ->select([
                'users.id as user_id',
                'users.province_code',
                'users.commune_code',
                'user_villages.village_code',
            ])
            ->chunk(500, function ($villages): void {
                $areas = $villages
                    ->filter(fn (object $village): bool => $village->province_code !== null)
                    ->map(fn (object $village): array => [
                        'user_id' => $village->user_id,
                        'province_code' => $village->province_code,
                        'commune_code' => $village->commune_code,
                        'village_code' => $village->village_code,
                    ])->all();

                if ($areas !== []) {
                    DB::table('user_operating_areas')->insertOrIgnore($areas);
                }
            });
    }

    private function dropLegacyColumns(string $tableName, array $columns): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        $columns = array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn($tableName, $column),
        ));
        if ($columns === []) {
            return;
        }

        $foreignKeys = collect(Schema::getForeignKeys($tableName))
            ->filter(fn (array $foreignKey): bool => array_intersect($columns, $foreignKey['columns']) !== []);
        foreach ($foreignKeys as $foreignKey) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
        }

        if ($tableName === 'users') {
            $indexes = collect(Schema::getIndexes($tableName))
                ->filter(fn (array $index): bool => $index['name'] === 'idx_users_location');
            foreach ($indexes as $index) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropIndex($index['name']));
            }
        }

        Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn($columns));
    }
};
