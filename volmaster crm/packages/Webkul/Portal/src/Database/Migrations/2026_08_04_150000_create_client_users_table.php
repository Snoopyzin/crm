<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_users', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('person_id')->unsigned()->unique();
            $table->foreign('person_id')->references('id')->on('persons')->onDelete('cascade');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->string('status')->default('Convidado');
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_users');
    }
};
