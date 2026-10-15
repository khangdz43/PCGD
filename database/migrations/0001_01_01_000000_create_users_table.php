<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('username')->unique()->nullable()->comment('Tên đăng nhập');
            $table->string('password');
            $table->string('phone', 20)->nullable()->comment('Số điện thoại');
            $table->boolean('is_active')->default(true)->comment('Trạng thái tài khoản đang hoạt động');
            $table->string('province_code', 20)->nullable();
            $table->string('commune_code', 20)->nullable();
            $table->string('scope_level', 20)->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index(['province_code', 'commune_code'], 'idx_users_location');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
