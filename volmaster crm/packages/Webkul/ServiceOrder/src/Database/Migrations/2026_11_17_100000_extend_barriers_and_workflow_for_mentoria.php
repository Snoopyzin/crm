<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Barreiras do capítulo 11.2: prazo dinâmico, fim do dia, data-alvo,
        // contagem em dias corridos, escalonamento em % do prazo e condições.
        Schema::table('barrier_rules', function (Blueprint $table) {
            // Verdadeiro = prazo conta só o horário comercial. As barreiras de
            // dias (48 h, 7 dias...) contam em relógio corrido.
            $table->boolean('conta_horario_comercial')->default(true)->after('critico');
            // Campo do evento que traz o prazo (modo dinâmico), ex.: tempo_alvo_min.
            $table->string('prazo_campo', 40)->nullable()->after('prazo_minutos');
            // Escalonamento em % do prazo (ex.: gerente aos +20% do tempo alvo).
            $table->unsignedSmallInteger('nivel2_apos_percentual')->nullable()->after('nivel2_apos_minutos');
            $table->unsignedSmallInteger('nivel3_apos_percentual')->nullable()->after('nivel3_apos_minutos');
            // Unidade de nivelN_apos_minutos: minutos | dias.
            $table->string('unidade_offset', 10)->default('minutos')->after('nivel3_destino');
            // Condições para abrir/fechar (ver BarrierConditions).
            $table->string('condicao_inicio', 40)->nullable()->after('unidade_offset');
            $table->string('condicao_fim', 40)->nullable()->after('condicao_inicio');
        });

        Schema::table('quotes', function (Blueprint $table) {
            // normal | retorno | garantia | chamado_externo | preventiva
            $table->string('tipo_os', 20)->default('normal');
            $table->unsignedInteger('os_origem_id')->nullable();
            // execucao | peca | diagnostico | uso_cliente
            $table->string('retorno_causa', 20)->nullable();

            $table->dateTime('prazo_prometido_em')->nullable();
            $table->dateTime('ultima_atualizacao_cliente_em')->nullable();
            $table->unsignedTinyInteger('ficha_devolucoes')->default(0);

            $table->dateTime('conferencia_final_em')->nullable();
            $table->dateTime('pecas_conferidas_em')->nullable();
            $table->dateTime('checklist_saida_em')->nullable();
            $table->decimal('horas_reais_saida', 8, 2)->nullable();
            $table->date('proxima_revisao_data')->nullable();
            $table->unsignedInteger('proxima_revisao_km')->nullable();
            $table->dateTime('enviada_financeiro_em')->nullable();

            $table->unsignedTinyInteger('nota_recomendacao')->nullable();

            $table->dateTime('chamado_autorizado_em')->nullable();
            $table->dateTime('pre_diagnostico_em')->nullable();

            // Test drive: quilometragem de saída e volta e resultado.
            $table->unsignedInteger('teste_km_saida')->nullable();
            $table->unsignedInteger('teste_km_volta')->nullable();
            $table->string('teste_resultado', 20)->nullable();

            $table->index('tipo_os');
            $table->index('os_origem_id');
        });

        // Categoria da CNH de quem conduz o test drive.
        Schema::table('mechanics', function (Blueprint $table) {
            $table->string('cnh_categoria', 3)->nullable();
        });

        Schema::table('quote_items', function (Blueprint $table) {
            // Precificação feita por Peças: origem (estoque | fornecedor) e custo.
            $table->dateTime('precificada_em')->nullable();
            $table->string('origem_preco', 12)->nullable();
            $table->decimal('custo_unitario', 12, 2)->nullable();
            $table->string('fornecedor', 120)->nullable();

            $table->dateTime('comprada_em')->nullable();
            $table->dateTime('prazo_entrega_em')->nullable();
            $table->dateTime('entregue_tecnico_em')->nullable();

            // Item adicional identificado depois do orçamento original.
            $table->boolean('adicional')->default(false);
        });

        Schema::create('service_order_reclamacoes', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('quote_id')->index();
            $table->dateTime('aberta_em');
            $table->dateTime('fechada_em')->nullable();
            $table->string('descricao', 500);
            $table->string('solucao', 500)->nullable();
            $table->unsignedInteger('aberta_por')->nullable();
            $table->unsignedInteger('fechada_por')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_order_reclamacoes');

        Schema::table('quote_items', function (Blueprint $table) {
            $table->dropColumn(['precificada_em', 'origem_preco', 'custo_unitario', 'fornecedor', 'comprada_em', 'prazo_entrega_em', 'entregue_tecnico_em', 'adicional']);
        });

        Schema::table('mechanics', function (Blueprint $table) {
            $table->dropColumn('cnh_categoria');
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->dropIndex(['tipo_os']);
            $table->dropIndex(['os_origem_id']);
            $table->dropColumn([
                'tipo_os', 'os_origem_id', 'retorno_causa', 'prazo_prometido_em', 'ultima_atualizacao_cliente_em', 'ficha_devolucoes',
                'conferencia_final_em', 'pecas_conferidas_em', 'checklist_saida_em', 'horas_reais_saida', 'proxima_revisao_data',
                'proxima_revisao_km', 'enviada_financeiro_em', 'nota_recomendacao', 'chamado_autorizado_em', 'pre_diagnostico_em',
                'teste_km_saida', 'teste_km_volta', 'teste_resultado',
            ]);
        });

        Schema::table('barrier_rules', function (Blueprint $table) {
            $table->dropColumn([
                'conta_horario_comercial', 'prazo_campo', 'nivel2_apos_percentual', 'nivel3_apos_percentual',
                'unidade_offset', 'condicao_inicio', 'condicao_fim',
            ]);
        });
    }
};
