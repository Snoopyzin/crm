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
        Schema::create('service_order_boletos', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('quote_id')->unsigned();
            $table->foreign('quote_id')->references('id')->on('quotes')->onDelete('cascade');

            $table->decimal('valor', 12, 2);
            $table->date('vencimento')->nullable();
            $table->string('status')->default('Pendente');
            $table->string('link_pagamento')->nullable();
            $table->string('gateway')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_order_boletos');
    }
};
