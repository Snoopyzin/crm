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
        Schema::create('work_bays', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->unsignedInteger('capacity')->default(1);
            $table->string('status')->default('Ativa');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_bays');
    }
};
