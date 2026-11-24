<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Marcadores e pesos do bônus por cargo e fase (capítulo 6.2). Os padrões
        // da mentoria são semeados por BonusCatalog; a oficina e o mentor ajustam.
        Schema::create('bonus_marcadores', function (Blueprint $table) {
            $table->increments('id');
            $table->string('cargo', 20);
            // 1 = processo (meses 1 e 2); 2 = processo + resultado. Zeladoria só tem a fase 1.
            $table->unsignedTinyInteger('fase');
            $table->string('nome')->nullable();
            // Um ou mais indicadores, separados por vírgula. Com mais de um, o
            // marcador só pontua se todos baterem a meta.
            $table->string('codigos', 80);
            $table->unsignedSmallInteger('peso');
            // Marcador de resultado: zera quando o gatilho de resultado da Ficha não foi atingido.
            $table->boolean('resultado')->default(false);
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->index(['cargo', 'fase']);
        });

        // Travas (capítulo 6.3): qualquer trava confirmada no mês zera o bônus.
        Schema::create('bonus_travas', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('periodo', 7)->index();
            $table->unsignedInteger('colaborador_id')->index();
            $table->string('cargo', 20);
            $table->string('tipo', 60);
            $table->string('descricao', 500);
            $table->string('evidencia', 500)->nullable();
            $table->unsignedInteger('service_order_id')->nullable();
            // automatica | manual
            $table->string('origem', 12)->default('manual');
            // Evita registrar duas vezes a mesma trava automática.
            $table->string('chave_unica', 120)->nullable()->unique();
            $table->unsignedInteger('registrada_por')->nullable();
            // pendente | confirmada | rejeitada
            $table->string('status', 12)->default('pendente');
            $table->unsignedInteger('decidida_por')->nullable();
            $table->dateTime('decidida_em')->nullable();
            $table->string('decisao_comentario', 500)->nullable();
            $table->timestamps();
        });

        // Nota e valor do mês de cada colaborador por cargo.
        Schema::create('bonus_apuracoes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('periodo', 7)->index();
            $table->unsignedInteger('colaborador_id')->index();
            $table->string('cargo', 20);
            $table->unsignedTinyInteger('fase');
            $table->decimal('nota', 5, 1)->nullable();
            // 100 | 70 | 40 | 0
            $table->unsignedSmallInteger('faixa_percentual')->default(0);
            $table->boolean('zerado_por_trava')->default(false);
            $table->decimal('salario_base', 12, 2)->nullable();
            $table->decimal('percentual_cheio', 5, 2)->nullable();
            $table->decimal('valor', 12, 2)->nullable();
            // Marcador por marcador: indicador, realizado, meta, situação, peso, pontos.
            $table->json('detalhe_json')->nullable();
            $table->string('motivo_resultado', 255)->nullable();
            // calculado | revisado | aprovado | estornado
            $table->string('status', 12)->default('calculado');
            $table->text('feedback')->nullable();
            $table->unsignedInteger('revisado_por')->nullable();
            $table->dateTime('revisado_em')->nullable();
            $table->dateTime('ciencia_colaborador_em')->nullable();
            $table->unsignedInteger('aprovado_por')->nullable();
            $table->dateTime('aprovado_em')->nullable();
            $table->unsignedBigInteger('estorna_id')->nullable();
            $table->string('justificativa_estorno', 500)->nullable();
            $table->dateTime('calculado_em');
            $table->timestamps();

            $table->unique(['periodo', 'colaborador_id', 'cargo'], 'bonus_apuracoes_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bonus_apuracoes');
        Schema::dropIfExists('bonus_travas');
        Schema::dropIfExists('bonus_marcadores');
    }
};
