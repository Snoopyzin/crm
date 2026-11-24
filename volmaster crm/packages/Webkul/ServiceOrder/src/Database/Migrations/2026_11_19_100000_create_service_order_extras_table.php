<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Anotações e anexos" da OS (pedido do patrão, 19/11/2026): qualquer coisa que a equipe
 * queira juntar à OS, com título, texto e fotos, vídeos e outros arquivos. Tabela nova,
 * nada do que já existe é alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('service_order_extras')) {
            return;
        }

        Schema::create('service_order_extras', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('quote_id');
            $table->index('quote_id');

            $table->unsignedInteger('user_id')->nullable();
            $table->string('titulo', 150)->nullable();
            $table->text('texto')->nullable();

            // Lista de arquivos: [{path, nome, tipo: foto|video|arquivo, tamanho}]
            $table->json('arquivos')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_order_extras');
    }
};
