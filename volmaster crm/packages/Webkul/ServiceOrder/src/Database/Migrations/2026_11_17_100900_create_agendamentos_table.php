<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Etapa 00 - Agendamento (opcional): data e hora agendadas, queixa e a
        // capacidade do dia (20% reservado a encaixes).
        Schema::create('agendamentos', function (Blueprint $table) {
            $table->increments('id');
            $table->dateTime('agendado_para')->index();
            $table->string('cliente', 120);
            $table->string('telefone', 30)->nullable();
            $table->string('placa', 12)->nullable();
            $table->string('queixa', 500)->nullable();
            // agendado | chegou | faltou | cancelado
            $table->string('status', 10)->default('agendado');
            $table->boolean('encaixe')->default(false);
            $table->unsignedInteger('service_order_id')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agendamentos');
    }
};
