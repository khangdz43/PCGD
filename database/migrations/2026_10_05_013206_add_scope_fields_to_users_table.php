<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 1. Thêm các cột mới
            if (!Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 20)->nullable()->after('email');
            }
            if (!Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('password');
            }
            if (!Schema::hasColumn('users', 'province_code')) {
                $table->string('province_code', 20)->nullable()->after('is_active');
            }
            if (!Schema::hasColumn('users', 'commune_code')) {
                $table->string('commune_code', 20)->nullable()->after('province_code');
            }
            // Thêm village_code cho Cán bộ cấp Thôn/Xóm
            if (!Schema::hasColumn('users', 'village_code')) {
                $table->string('village_code', 20)->nullable()->after('commune_code');
            }
            if (!Schema::hasColumn('users', 'school_code')) {
                $table->string('school_code', 50)->nullable()->after('village_code');
            }

            // 2. Thêm khóa ngoại trỏ tới các bảng hành chính
            $table->foreign('province_code')->references('code')->on('provinces')->nullOnDelete()->cascadeOnUpdate();
            $table->foreign('commune_code')->references('code')->on('communes')->nullOnDelete()->cascadeOnUpdate();
            $table->foreign('village_code')->references('code')->on('villages')->nullOnDelete()->cascadeOnUpdate();
            $table->foreign('school_code')->references('code')->on('schools')->nullOnDelete()->cascadeOnUpdate();

            // 3. Thêm Index
            $table->index(['province_code', 'commune_code', 'village_code'], 'idx_users_location');
            $table->index('school_code', 'idx_users_school');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['province_code']);
            $table->dropForeign(['commune_code']);
            $table->dropForeign(['village_code']);
            $table->dropForeign(['school_code']);

            $table->dropIndex('idx_users_location');
            $table->dropIndex('idx_users_school');

            $table->dropColumn(['phone', 'is_active', 'province_code', 'commune_code', 'village_code', 'school_code']);
        });
    }
};
