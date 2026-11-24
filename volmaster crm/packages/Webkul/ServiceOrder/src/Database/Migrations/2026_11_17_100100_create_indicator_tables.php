<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabela de metas (capítulo 3.3): uma linha por indicador. Os padrões da
        // mentoria vêm de IndicatorCatalog e são semeados na primeira consulta;
        // a oficina só sobrescreve meta_oficina / ativo.
        Schema::create('indicator_definitions', function (Blueprint $table) {
            $table->string('codigo', 12)->primary();
            $table->string('nome');
            $table->string('cargo', 20);
            $table->text('formula');
            // min | teto | zero | nota
            $table->string('tipo_meta', 8);
            $table->decimal('meta_padrao', 12, 2)->nullable();
            $table->decimal('meta_oficina', 12, 2)->nullable();
            // "Meta da empresa": fica sem cor até o dono preencher meta_oficina.
            $table->boolean('meta_empresa')->default(false);
            // percent | count | days | hours | nota | brl
            $table->string('unidade', 10)->default('percent');
            $table->string('valida', 60)->nullable();
            $table->boolean('ativo')->default(true);
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->timestamps();

            $table->index('cargo');
        });

        // Registros do dia a dia que não nascem na OS: auditorias com nota,
        // ocorrências, reuniões, checklists da zeladoria, conciliações,
        // inventário... Só inserção: correção = novo registro com justificativa.
        Schema::create('mentoria_registros', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('tipo', 40)->index();
            // Quem é o assunto do registro (colaborador avaliado).
            $table->unsignedInteger('user_id')->nullable()->index();
            $table->unsignedInteger('mechanic_id')->nullable()->index();
            $table->unsignedInteger('service_order_id')->nullable()->index();
            $table->dateTime('ocorrido_em')->index();
            $table->decimal('valor', 14, 2)->nullable();
            $table->boolean('ok')->nullable();
            $table->json('dados_json')->nullable();
            $table->string('observacao', 500)->nullable();
            $table->unsignedBigInteger('corrige_id')->nullable();
            $table->string('justificativa', 500)->nullable();
            $table->unsignedInteger('registrado_por')->nullable();
            $table->timestamp('criado_em')->useCurrent();

            $table->index(['tipo', 'ocorrido_em']);
        });

        // Valores congelados no fechamento do mês (dia 1), base do bônus.
        Schema::create('indicator_snapshots', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('periodo', 7);
            $table->string('codigo', 12);
            // oficina | user | mechanic
            $table->string('escopo', 10)->default('oficina');
            $table->unsignedInteger('escopo_id')->default(0);
            $table->decimal('realizado', 14, 2)->nullable();
            $table->decimal('numerador', 14, 2)->nullable();
            $table->decimal('denominador', 14, 2)->nullable();
            $table->decimal('meta', 12, 2)->nullable();
            // verde | amarelo | vermelho | sem_meta | sem_dado
            $table->string('situacao', 10);
            $table->dateTime('calculado_em');

            $table->unique(['periodo', 'codigo', 'escopo', 'escopo_id'], 'indicator_snapshots_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indicator_snapshots');
        Schema::dropIfExists('mentoria_registros');
        Schema::dropIfExists('indicator_definitions');
    }
};
