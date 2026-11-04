<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('schools')
            ->whereNull('type')
            ->whereIn('level', ['MN', 'TH', 'THCS', 'THPT'])
            ->update(['type' => 'single_level']);
    }

    public function down(): void
    {
        // Không hoàn tác dữ liệu để tránh xóa loại trường đã được người dùng cập nhật.
    }
};
