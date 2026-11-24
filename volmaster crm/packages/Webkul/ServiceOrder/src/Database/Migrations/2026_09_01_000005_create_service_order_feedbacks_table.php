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
        Schema::create('service_order_feedbacks', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('quote_id')->unsigned();
            $table->foreign('quote_id')->references('id')->on('quotes')->onDelete('cascade');

            $table->string('token', 36)->unique();
            $table->dateTime('enviado_em')->nullable();
            $table->dateTime('respondido_em')->nullable();
            $table->string('avaliacao')->nullable();
            $table->text('comentario')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_order_feedbacks');
    }
};
