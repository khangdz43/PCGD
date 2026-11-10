<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class RoleNormalizationMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name')->default('web');
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name')->default('web');
        });
        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->primary(['role_id', 'model_id', 'model_type']);
        });
        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->primary(['role_id', 'permission_id']);
        });

        DB::table('users')->insert([['id' => 1], ['id' => 2]]);
        DB::table('roles')->insert([
            ['id' => 1, 'name' => 'Nhóm một'],
            ['id' => 2, 'name' => 'Nhóm hai'],
        ]);
        DB::table('permissions')->insert([
            ['id' => 1, 'name' => 'view-households'],
            ['id' => 2, 'name' => 'manage-user-accounts'],
        ]);
        DB::table('role_has_permissions')->insert([
            ['role_id' => 1, 'permission_id' => 1],
            ['role_id' => 1, 'permission_id' => 2],
        ]);
        DB::table('model_has_roles')->insert([
            'role_id' => 2,
            'model_type' => 'App\\Models\\User',
            'model_id' => 2,
            'assigned_by' => 1,
        ]);
    }

    public function test_migration_preserves_role_codes_and_assigner_and_can_be_reversed(): void
    {
        $migration = require database_path('migrations/2026_11_08_000000_normalize_role_assignments_and_permissions.php');
        $migration->up();

        $this->assertSame(2, DB::table('users')->where('id', 2)->value('role_id'));
        $this->assertSame(1, DB::table('users')->where('id', 2)->value('role_assigned_by'));
        $this->assertSame(
            ['view-households', 'manage-user-accounts'],
            json_decode(DB::table('roles')->where('id', 1)->value('permission_codes'), true, flags: JSON_THROW_ON_ERROR)
        );
        $this->assertFalse(Schema::hasTable('model_has_roles'));
        $this->assertFalse(Schema::hasTable('role_has_permissions'));

        $migration->down();

        $this->assertDatabaseHas('model_has_roles', [
            'role_id' => 2,
            'model_type' => 'App\\Models\\User',
            'model_id' => 2,
            'assigned_by' => 1,
        ]);
        $this->assertDatabaseHas('role_has_permissions', ['role_id' => 1, 'permission_id' => 1]);
        $this->assertDatabaseHas('role_has_permissions', ['role_id' => 1, 'permission_id' => 2]);
        $this->assertFalse(Schema::hasColumn('users', 'role_id'));
        $this->assertFalse(Schema::hasColumn('roles', 'permission_codes'));
    }

    public function test_migration_refuses_multiple_roles_without_dropping_source_data(): void
    {
        DB::table('model_has_roles')->insert([
            'role_id' => 1,
            'model_type' => 'App\\Models\\User',
            'model_id' => 2,
        ]);
        $migration = require database_path('migrations/2026_11_08_000000_normalize_role_assignments_and_permissions.php');

        try {
            $migration->up();
            $this->fail('Migration phải từ chối user có nhiều hơn một nhóm.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('nhiều nhóm', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('model_has_roles'));
        $this->assertTrue(Schema::hasTable('role_has_permissions'));
        $this->assertFalse(Schema::hasColumn('users', 'role_id'));
        $this->assertSame(2, DB::table('model_has_roles')->where('model_id', 2)->count());
    }
}
