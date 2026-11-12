<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('person_disabilities', function (Blueprint $table) {
            if (! Schema::hasColumn('person_disabilities', 'can_study')) {
                $table->boolean('can_study')->nullable()->after('has_disability_cert');
            }
        });

        Schema::table('person_educations', function (Blueprint $table) {
            if (! Schema::hasColumn('person_educations', 'literacy_current_class')) {
                $table->string('literacy_current_class', 20)->nullable()->after('completed_class');
            }

            if (! Schema::hasColumn('person_educations', 'literacy_completed_class')) {
                $table->string('literacy_completed_class', 20)->nullable()->after('literacy_current_class');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'created_by')) {
                $table->foreignId('created_by')
                    ->nullable()
                    ->after('id')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'created_by')) {
                $table->dropForeign(['created_by']);
                $table->dropColumn('created_by');
            }
        });

        Schema::table('person_educations', function (Blueprint $table) {
            $columns = array_filter([
                Schema::hasColumn('person_educations', 'literacy_current_class') ? 'literacy_current_class' : null,
                Schema::hasColumn('person_educations', 'literacy_completed_class') ? 'literacy_completed_class' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('person_disabilities', function (Blueprint $table) {
            if (Schema::hasColumn('person_disabilities', 'can_study')) {
                $table->dropColumn('can_study');
            }
        });
    }
};
