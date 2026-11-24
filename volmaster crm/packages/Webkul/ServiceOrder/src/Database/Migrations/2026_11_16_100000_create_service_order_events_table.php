<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Log de eventos da OS (append-only): cada passo registra data, hora e
     * usuário. É a matéria-prima dos indicadores e das barreiras de prazo.
     *
     * Sem chave estrangeira em service_order_id de propósito: o histórico
     * precisa sobreviver mesmo que a OS seja apagada. Nunca se edita nem se
     * apaga um evento; correção = novo evento com justificativa (a regra é
     * garantida no model).
     */
    public function up(): void
    {
        Schema::create('service_order_events', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Cada oficina tem o próprio banco; a coluna guarda o id do tenant
            // (ou fica nula na instalação raiz) para consolidações futuras.
            $table->unsignedInteger('oficina_id')->nullable();

            $table->unsignedInteger('service_order_id');
            $table->string('tipo_evento', 60);
            $table->string('etapa_origem', 60)->nullable();
            $table->string('etapa_destino', 60)->nullable();
            $table->unsignedInteger('usuario_id')->nullable();

            $table->dateTime('ocorrido_em');
            $table->json('dados_json')->nullable();

            $table->timestamp('criado_em')->useCurrent();

            $table->index(['service_order_id', 'tipo_evento']);
            $table->index(['tipo_evento', 'ocorrido_em']);
            $table->index('ocorrido_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_order_events');
    }
};
