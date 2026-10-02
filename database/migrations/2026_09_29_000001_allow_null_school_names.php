<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('name')->nullable()->comment('Tên trường học')->change();
        });
    }

    public function down(): void
    {
        DB::table('schools')->whereNull('name')->update(['name' => DB::raw('code')]);

        Schema::table('schools', function (Blueprint $table) {
            $table->string('name')->nullable(false)->comment('Tên trường học')->change();
        });
    }
};
