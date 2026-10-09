<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_villages', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('village_code', 20);
            $table->foreign('village_code')->references('code')->on('villages')->cascadeOnDelete()->cascadeOnUpdate();
            $table->primary(['user_id', 'village_code']);
            $table->index('village_code');
        });

        DB::table('users')
            ->whereNotNull('village_code')
            ->select(['id', 'village_code'])
            ->orderBy('id')
            ->each(function (object $user): void {
                DB::table('user_villages')->insertOrIgnore([
                    'user_id' => $user->id,
                    'village_code' => $user->village_code,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_villages');
    }
};
