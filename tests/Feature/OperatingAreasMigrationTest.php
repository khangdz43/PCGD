<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OperatingAreasMigrationTest extends TestCase
{
    public function test_migration_copies_legacy_locations_and_removes_legacy_columns(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('province_code')->nullable();
            $table->string('commune_code')->nullable();
            $table->string('scope_level')->nullable();
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('province_code')->nullable();
            $table->string('commune_code')->nullable();
        });
        Schema::create('provinces', function (Blueprint $table): void {
            $table->string('code')->primary();
        });
        Schema::create('communes', function (Blueprint $table): void {
            $table->string('code')->primary();
        });
        Schema::create('villages', function (Blueprint $table): void {
            $table->string('code')->primary();
        });
        Schema::create('user_villages', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->string('village_code');
        });

        DB::table('provinces')->insert(['code' => 'P01']);
        DB::table('communes')->insert(['code' => 'C01']);
        DB::table('villages')->insert(['code' => 'V01']);
        DB::table('users')->insert([
            'id' => 1,
            'province_code' => 'P01',
            'commune_code' => 'C01',
            'scope_level' => 'commune',
        ]);
        DB::table('user_villages')->insert(['user_id' => 1, 'village_code' => 'V01']);

        $migration = require database_path('migrations/2026_11_09_000000_align_user_operating_areas_with_live_schema.php');
        $migration->up();

        $this->assertDatabaseHas('user_operating_areas', [
            'user_id' => 1,
            'province_code' => 'P01',
            'commune_code' => 'C01',
            'village_code' => 'V01',
        ]);
        $this->assertFalse(Schema::hasTable('user_villages'));
        $this->assertFalse(Schema::hasColumn('users', 'province_code'));
        $this->assertFalse(Schema::hasColumn('users', 'commune_code'));
        $this->assertFalse(Schema::hasColumn('users', 'scope_level'));
        $this->assertFalse(Schema::hasColumn('roles', 'province_code'));
        $this->assertFalse(Schema::hasColumn('roles', 'commune_code'));
    }

    public function test_migration_preserves_an_existing_operating_areas_table(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('role_id')->nullable();
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('scope_level');
            $table->json('permission_codes')->nullable();
        });
        Schema::create('user_operating_areas', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('province_code');
            $table->string('commune_code')->nullable();
            $table->string('village_code')->nullable();
        });
        DB::table('users')->insert(['id' => 1, 'role_id' => 3]);
        DB::table('user_operating_areas')->insert([
            'user_id' => 1,
            'province_code' => 'P01',
            'commune_code' => 'C01',
            'village_code' => 'V01',
        ]);

        $migration = require database_path('migrations/2026_11_09_000000_align_user_operating_areas_with_live_schema.php');
        $migration->up();

        $this->assertDatabaseHas('user_operating_areas', [
            'user_id' => 1,
            'province_code' => 'P01',
            'commune_code' => 'C01',
            'village_code' => 'V01',
        ]);
        $this->assertFalse(Schema::hasColumn('users', 'province_code'));
        $this->assertFalse(Schema::hasColumn('roles', 'province_code'));
    }
}
