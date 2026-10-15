<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ràng buộc này không được ứng dụng sử dụng; kiểm tra trùng được thực hiện ở nghiệp vụ.
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'territorial_admin_key')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (collect(Schema::getIndexes('users'))->pluck('name')->contains('users_territorial_admin_key_unique')) {
                $table->dropUnique('users_territorial_admin_key_unique');
            }
            $table->dropColumn('territorial_admin_key');
        });
    }
};
