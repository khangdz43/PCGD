<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
   
    public function up(): void
    {
        Schema::create('import_logs', function (Blueprint $table) {
            $table->id();
            $table->string('file_name', 255);
            $table->string('uploaded_by', 100)->nullable();
            $table->integer('total_rows')->default(0);
            $table->integer('success_rows')->default(0);
            $table->integer('error_rows')->default(0);
            // comment trong mysql
            $table->string('status', 50)->nullable()->comment('PENDING, PROCESSING, SUCCESS, FAILED, ROLLED_BACK');
            $table->json('error_details')->nullable(); // trả ra json thay vì text vì 
            // lỗi có ở nhiều dòng mà text thì khó nhìn lắm để json để hthi tất những dòng lỗi
            $table->timestamp('created_at')->useCurrent();
        });
    }






  
    public function down(): void
    {
        Schema::dropIfExists('import_logs');
    }
};
