<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Estado atual de cada passo obrigatório da OS (o histórico fica em
     * service_order_events), mais as tabelas de apoio: anexos (fotos do
     * termo e do laudo), evidências do laudo e tipos de queixa com o tempo
     * alvo de diagnóstico.
     */
    public function up(): void
    {
        Schema::create('diagnostico_queixa_tipos', function (Blueprint $table) {
            $table->increments('id');

            $table->string('nome');
            // Tempo alvo do diagnóstico para este tipo de queixa, em minutos.
            $table->unsignedInteger('tempo_alvo_min');
            $table->boolean('ativo')->default(true);

            $table->timestamps();
        });

        Schema::table('quotes', function (Blueprint $table) {
            // Termo de entrada (Cadastro).
            $table->unsignedInteger('km_entrada')->nullable();
            $table->string('combustivel_entrada', 20)->nullable();
            $table->text('itens_entrada')->nullable();
            $table->boolean('autorizacao_rodagem')->default(false);
            $table->dateTime('termo_assinado_em')->nullable();

            // Cor de crédito (verde / amarelo / vermelho) -- dado restrito.
            $table->string('cor_credito', 10)->nullable();
            $table->dateTime('cor_credito_em')->nullable();

            // Diagnóstico.
            $table->unsignedInteger('queixa_tipo_id')->nullable();
            $table->dateTime('diagnostico_inicio_em')->nullable();
            $table->string('diagnostico_visto_status', 12)->nullable();
            $table->unsignedInteger('diagnostico_visto_por')->nullable();
            $table->dateTime('diagnostico_visto_em')->nullable();
            $table->string('diagnostico_causa_atraso', 60)->nullable();
            $table->dateTime('relacao_pecas_em')->nullable();

            // Orçamento e negociação.
            $table->dateTime('orcamento_enviado_em')->nullable();
            $table->string('aprovacao_tipo', 12)->nullable();
            $table->string('aprovacao_meio', 20)->nullable();
            $table->dateTime('aprovacao_em')->nullable();
            $table->string('motivo_recusa')->nullable();
            $table->dateTime('execucao_liberada_em')->nullable();

            // Faturamento e saída.
            $table->string('nf_manual_numero', 60)->nullable();
            $table->dateTime('nf_manual_em')->nullable();
            $table->dateTime('pagamento_confirmado_em')->nullable();
            $table->dateTime('acordo_assinado_em')->nullable();
            $table->dateTime('saida_em')->nullable();
        });

        Schema::create('service_order_attachments', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('quote_id');
            $table->index('quote_id');

            // termo_foto | laudo_foto
            $table->string('tipo', 30);
            $table->string('path');
            $table->string('nome_original')->nullable();
            $table->unsignedInteger('user_id')->nullable();

            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('service_order_laudo_items', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('quote_id');
            $table->index('quote_id');

            // codigo (código de falha) | medicao | foto
            $table->string('tipo', 20);
            $table->string('descricao');
            $table->string('valor')->nullable();
            $table->unsignedInteger('attachment_id')->nullable();
            $table->unsignedInteger('user_id')->nullable();

            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_order_laudo_items');
        Schema::dropIfExists('service_order_attachments');

        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn([
                'km_entrada', 'combustivel_entrada', 'itens_entrada', 'autorizacao_rodagem', 'termo_assinado_em',
                'cor_credito', 'cor_credito_em',
                'queixa_tipo_id', 'diagnostico_inicio_em', 'diagnostico_visto_status', 'diagnostico_visto_por',
                'diagnostico_visto_em', 'diagnostico_causa_atraso', 'relacao_pecas_em',
                'orcamento_enviado_em', 'aprovacao_tipo', 'aprovacao_meio', 'aprovacao_em', 'motivo_recusa',
                'execucao_liberada_em',
                'nf_manual_numero', 'nf_manual_em', 'pagamento_confirmado_em', 'acordo_assinado_em', 'saida_em',
            ]);
        });

        Schema::dropIfExists('diagnostico_queixa_tipos');
    }
};
