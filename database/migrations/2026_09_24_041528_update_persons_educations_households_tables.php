<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        //  Xóa cột citizen_id , bỏ default của gender
        Schema::table('persons', function (Blueprint $table) {
            if (Schema::hasColumn('persons', 'citizen_id')) {
                // Drop index trước để tránh lỗi dính constraint khi drop column
                $table->dropIndex('idx_person_citizen_id');
                $table->dropColumn('citizen_id');
            }

            // Chuyển gender về string  không có default value
            $table->string('gender', 10)->default(null)->change();
        });

        //  Bảng person_disabilities: Thêm cột có khả năng học tập
        Schema::table('person_disabilities', function (Blueprint $table) {
            if (!Schema::hasColumn('person_disabilities', 'can_study')) {
                $table->boolean('can_study')->nullable()->after('has_disability_cert')->comment('Có khả năng học tập hay không');
            }
        });

        // Bảng person_educations: Gán khóa ngoại cho school_code trỏ sang schools(code)
        Schema::table('person_educations', function (Blueprint $table) {
            $table->foreign('school_code')
                ->references('code')
                ->on('schools')
                ->nullOnDelete()
                ->cascadeOnUpdate();
        });

        // 4. Bảng schools: Xóa FK cũ -> Rename communes_code -> commune_code -> Khai báo FK mới
        Schema::table('schools', function (Blueprint $table) {
            if (Schema::hasColumn('schools', 'communes_code')) {
                // Phải drop foreign cũ trước mới rename cột được
                $table->dropForeign(['communes_code']);
                $table->renameColumn('communes_code', 'commune_code');
            }
        });

        Schema::table('schools', function (Blueprint $table) {
            // Tạo FK chuẩn cho cột commune_code vừa rename
            $table->foreign('commune_code')
                ->references('code')
                ->on('communes')
                ->nullOnDelete()
                ->cascadeOnUpdate();
        });

        // 5. Bảng households: Thêm province_code và commune_code + Khóa ngoại
        Schema::table('households', function (Blueprint $table) {
            if (!Schema::hasColumn('households', 'province_code')) {
                // Sửa thành head_last_name cho đúng schema gốc
                $table->string('province_code', 20)->nullable()->after('head_last_name')->comment('Mã Tỉnh/Thành phố');
            }

            if (!Schema::hasColumn('households', 'commune_code')) {
                $table->string('commune_code', 20)->nullable()->after('province_code')->comment('Mã Xã/Phường');
            }

            $table->foreign('province_code')
                ->references('code')
                ->on('provinces')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('commune_code')
                ->references('code')
                ->on('communes')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->index(['province_code', 'commune_code'], 'idx_household_location');
        });
    }

    public function down(): void
    {
        // Rollback households
        Schema::table('households', function (Blueprint $table) {
            $table->dropForeign(['province_code']);
            $table->dropForeign(['commune_code']);
            $table->dropIndex('idx_household_location');
            $table->dropColumn(['province_code', 'commune_code']);
        });

        // Rollback schools
        Schema::table('schools', function (Blueprint $table) {
            $table->dropForeign(['commune_code']);
            if (Schema::hasColumn('schools', 'commune_code')) {
                $table->renameColumn('commune_code', 'communes_code');
            }
            $table->foreign('communes_code')
                ->references('code')
                ->on('communes')
                ->nullOnDelete();
        });

        // Rollback person_educations
        Schema::table('person_educations', function (Blueprint $table) {
            $table->dropForeign(['school_code']);
        });

        // Rollback person_disabilities
        Schema::table('person_disabilities', function (Blueprint $table) {
            $table->dropColumn('can_study');
        });

        // Rollback persons
        Schema::table('persons', function (Blueprint $table) {
            $table->string('citizen_id', 20)->nullable()->comment('CCCD');
            $table->index('citizen_id', 'idx_person_citizen_id');
        });
    }
};
