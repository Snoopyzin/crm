<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Plano de contas (capítulo 10.1): padrão da mentoria com cerca de 20
        // contas; a oficina só cria subcontas (pai_id).
        Schema::create('plano_contas', function (Blueprint $table) {
            $table->increments('id');
            $table->string('codigo', 12)->unique();
            $table->string('nome');
            // receitas | deducoes | custos_variaveis | pessoal | despesas_fixas | abaixo_resultado
            $table->string('grupo', 20);
            // Papel da conta nos cálculos da DRE (ex.: mao_obra, pecas, terceiros, comissoes...).
            $table->string('papel', 24)->nullable();
            $table->unsignedInteger('pai_id')->nullable();
            $table->boolean('padrao')->default(false);
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });

        // Contas a pagar: o financeiro só precisa escolher a conta de cada pagamento.
        Schema::create('financeiro_lancamentos', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('descricao');
            $table->decimal('valor', 12, 2);
            $table->date('vencimento');
            // Mês em que o gasto pertence na DRE (por padrão o do vencimento).
            $table->date('competencia');
            $table->date('pago_em')->nullable();
            // dinheiro | pix | cartao | boleto | transferencia
            $table->string('forma', 14)->nullable();
            $table->unsignedInteger('conta_id')->nullable()->index();
            $table->unsignedInteger('service_order_id')->nullable()->index();
            $table->string('parceiro', 120)->nullable();
            // Conta programada (aluguel, folha, boleto conhecido): o financeiro paga sem pedir; avulsa segue a alçada de despesas.
            $table->boolean('programada')->default(true);
            $table->boolean('juros_multa')->default(false);
            $table->dateTime('conciliado_em')->nullable();
            // Pagamento de peças: a NF de entrada foi conferida antes de pagar? (FIN-07)
            $table->boolean('pagamento_pecas')->default(false);
            $table->boolean('nf_entrada_conferida')->default(false);
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['competencia']);
            $table->index(['vencimento', 'pago_em']);
        });

        // Fechamento do caixa do dia: entradas por forma, saídas e a diferença.
        Schema::create('caixa_fechamentos', function (Blueprint $table) {
            $table->increments('id');
            $table->date('data')->unique();
            $table->decimal('saldo_inicial', 14, 2)->default(0);
            $table->json('entradas_json')->nullable();
            $table->decimal('total_entradas', 14, 2)->default(0);
            $table->decimal('total_saidas', 14, 2)->default(0);
            $table->decimal('saldo_sistema', 14, 2)->default(0);
            $table->decimal('saldo_contado', 14, 2)->default(0);
            $table->decimal('diferenca', 14, 2)->default(0);
            $table->string('justificativa', 500)->nullable();
            $table->unsignedInteger('fechado_por')->nullable();
            $table->dateTime('fechado_em');
            $table->timestamps();
        });

        // Relatório diário do financeiro ao dono (9.1) e o de 5 linhas do gerente.
        Schema::create('relatorios_financeiro', function (Blueprint $table) {
            $table->increments('id');
            $table->date('data')->unique();
            // Até 3 itens escritos pelo financeiro: alertas e decisões para o dono.
            $table->json('alertas_json')->nullable();
            $table->json('snapshot_json')->nullable();
            $table->dateTime('enviado_em')->nullable();
            $table->unsignedInteger('enviado_por')->nullable();
            $table->boolean('no_horario')->nullable();
            $table->timestamps();
        });

        Schema::create('relatorios_gerente', function (Blueprint $table) {
            $table->increments('id');
            $table->date('data')->unique();
            $table->text('destaques')->nullable();
            $table->text('problemas')->nullable();
            $table->text('decisoes_tomadas')->nullable();
            $table->text('decisoes_pendentes')->nullable();
            $table->text('equipe')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->timestamps();
        });

        // Orçamento anual: meta mensal por linha da DRE.
        Schema::create('orcamento_anual', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedSmallInteger('ano');
            $table->unsignedTinyInteger('mes');
            $table->string('linha', 30);
            $table->decimal('valor', 14, 2);
            $table->timestamps();

            $table->unique(['ano', 'mes', 'linha']);
        });

        // Marco Zero: os números da oficina antes da mentoria (encontro de abertura).
        Schema::create('marco_zero', function (Blueprint $table) {
            $table->increments('id');
            $table->date('data');
            $table->decimal('faturamento', 14, 2)->nullable();
            $table->decimal('resultado', 14, 2)->nullable();
            $table->decimal('hora_cobrada', 10, 2)->nullable();
            $table->decimal('preco_minimo_hora', 10, 2)->nullable();
            $table->decimal('margem_pecas', 6, 2)->nullable();
            $table->decimal('produtividade', 6, 2)->nullable();
            $table->decimal('retrabalho', 6, 2)->nullable();
            $table->string('observacao', 500)->nullable();
            $table->unsignedInteger('registrado_por')->nullable();
            $table->timestamps();
        });

        // A DRE congela no fechamento mensal e só reabre com justificativa
        // do financeiro e aprovação do dono.
        Schema::create('dre_fechamentos', function (Blueprint $table) {
            $table->increments('id');
            $table->string('periodo', 7)->unique();
            $table->json('snapshot_json');
            $table->dateTime('congelado_em');
            $table->dateTime('reaberto_em')->nullable();
            $table->string('reabertura_justificativa', 500)->nullable();
            $table->unsignedInteger('reaberto_por')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dre_fechamentos');
        Schema::dropIfExists('marco_zero');
        Schema::dropIfExists('orcamento_anual');
        Schema::dropIfExists('relatorios_gerente');
        Schema::dropIfExists('relatorios_financeiro');
        Schema::dropIfExists('caixa_fechamentos');
        Schema::dropIfExists('financeiro_lancamentos');
        Schema::dropIfExists('plano_contas');
    }
};
