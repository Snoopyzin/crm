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
        Schema::create('whatsapp_message_logs', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('quote_id')->unsigned()->nullable();
            $table->foreign('quote_id')->references('id')->on('quotes')->onDelete('set null');

            $table->string('to');
            $table->string('template');
            $table->string('status');
            $table->text('response')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_message_logs');
    }
};
