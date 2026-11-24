<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Matriz de alçadas (capítulo 7.1), configurável por oficina. Os limites
        // ficam em branco até o dono preencher: sem limite não há como julgar
        // "acima da alçada", então a ação passa e a tela avisa que falta configurar.
        Schema::create('alcada_regras', function (Blueprint $table) {
            $table->string('tema', 30)->primary();
            $table->decimal('limite_sozinho', 12, 2)->nullable();
            $table->decimal('limite_gerente', 12, 2)->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });

        // Fila de alçadas: pedido, quem pediu, OS ou documento, valor, motivo,
        // prazo (24 h para o dono) e a decisão com comentário. Só inserção de
        // decisões: um pedido decidido não volta atrás.
        Schema::create('alcada_pedidos', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('tema', 30)->index();
            $table->unsignedInteger('service_order_id')->nullable()->index();
            $table->unsignedInteger('solicitante_id')->nullable();
            $table->decimal('valor', 14, 2)->nullable();
            $table->string('descricao', 500);
            // gerente | proprietario: quem precisa decidir.
            $table->string('nivel_necessario', 12);
            // pendente | encaminhado | aprovado | recusado | consumido
            $table->string('status', 12)->default('pendente')->index();
            // Usado por quem aplicou a aprovação (consumido).
            $table->unsignedInteger('encaminhado_por')->nullable();
            $table->dateTime('encaminhado_em')->nullable();
            $table->unsignedInteger('decidido_por')->nullable();
            $table->dateTime('decidido_em')->nullable();
            $table->string('comentario', 500)->nullable();
            $table->dateTime('consumido_em')->nullable();
            // Pedidos que chegaram ao dono sem passar pelo gerente (DON-05).
            $table->boolean('sem_gerente')->default(false);
            $table->dateTime('prazo_dono_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alcada_pedidos');
        Schema::dropIfExists('alcada_regras');
    }
};
