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
        Schema::create('remote_services', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('person_id')->unsigned();
            $table->foreign('person_id')->references('id')->on('persons')->onDelete('cascade');

            $table->integer('vehicle_id')->unsigned()->nullable();
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->onDelete('set null');
            $table->string('veiculo_descricao_livre')->nullable();

            $table->string('teamviewer_id')->nullable();
            $table->string('tipo_atendimento')->nullable();

            $table->text('descricao_problema');
            $table->text('descricao_solucao')->nullable();

            $table->string('status')->default('Aberto');

            $table->decimal('valor', 10, 2)->nullable();
            $table->boolean('pago')->default(false);
            $table->boolean('faturado')->default(false);
            $table->string('forma_pagamento')->nullable();

            $table->integer('consultor_id')->unsigned()->nullable();
            $table->foreign('consultor_id')->references('id')->on('users')->onDelete('set null');

            $table->integer('tecnico_id')->unsigned()->nullable();
            $table->foreign('tecnico_id')->references('id')->on('users')->onDelete('set null');

            $table->integer('service_order_id')->unsigned()->nullable();
            $table->foreign('service_order_id')->references('id')->on('quotes')->onDelete('set null');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('remote_services');
    }
};
