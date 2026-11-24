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
        Schema::create('service_order_time_logs', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('quote_id')->unsigned();
            $table->foreign('quote_id')->references('id')->on('quotes')->onDelete('cascade');

            $table->integer('mechanic_id')->unsigned()->nullable();
            $table->foreign('mechanic_id')->references('id')->on('mechanics')->onDelete('set null');

            $table->string('action');
            $table->text('justificativa')->nullable();

            $table->dateTime('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_order_time_logs');
    }
};
