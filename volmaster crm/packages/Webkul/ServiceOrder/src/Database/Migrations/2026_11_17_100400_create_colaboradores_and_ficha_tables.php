<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Colaboradores (capítulo 3.2): quem trabalha na oficina, com ou sem
        // acesso ao sistema (a zeladora, por exemplo, pode não ter usuário).
        Schema::create('colaboradores', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nome');
            $table->string('cpf', 14)->nullable();
            $table->unsignedInteger('user_id')->nullable()->index();
            $table->unsignedInteger('mechanic_id')->nullable()->index();
            // Cargos separados por vírgula: um colaborador pode acumular cargos.
            $table->string('cargos', 120)->nullable();
            // oficina (técnico, chefe) ou administracao: separa o pessoal na DRE e no custo da hora.
            $table->string('classificacao', 14)->default('oficina');
            $table->decimal('salario_base', 12, 2)->nullable();
            $table->date('admissao')->nullable();
            // O registro foi feito antes de o colaborador começar? (GER-13)
            $table->boolean('registro_antes_inicio')->nullable();
            $table->date('ferias_vencimento')->nullable();
            $table->date('exame_periodico_vencimento')->nullable();
            $table->date('epi_vencimento')->nullable();
            $table->string('cnh_categoria', 3)->nullable();
            // Matriz de competências do técnico: sistema => nível (0 a 3).
            $table->json('competencias')->nullable();
            $table->boolean('ativo')->default(true);
            $table->date('desligamento_em')->nullable();
            $table->timestamps();
        });

        // Cargos sem responsável (Ficha de Enquadramento): gera pendência no
        // painel do gerente até resolver.
        Schema::create('cargos_pendentes', function (Blueprint $table) {
            $table->increments('id');
            $table->string('cargo', 20);
            // redistribuir | contratar
            $table->string('acao', 14)->default('redistribuir');
            $table->string('gatilho', 255)->nullable();
            $table->date('prazo')->nullable();
            $table->date('resolvido_em')->nullable();
            $table->timestamps();
        });

        // Tabela de margem de peças (faixa de custo, margem mínima e alvo).
        Schema::create('margem_pecas_faixas', function (Blueprint $table) {
            $table->increments('id');
            // Custo máximo da faixa (null = sem limite, a última faixa).
            $table->decimal('custo_ate', 12, 2)->nullable();
            $table->decimal('margem_minima', 5, 2);
            $table->decimal('margem_alvo', 5, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('margem_pecas_faixas');
        Schema::dropIfExists('cargos_pendentes');
        Schema::dropIfExists('colaboradores');
    }
};
