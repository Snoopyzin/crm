<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Configurações da oficina (chave/valor): horário comercial, horário
        // de silêncio, limite de SMS, % da operação acima do tempo etc.
        // Nenhum valor fica fixo no código -- os padrões da mentoria são
        // semeados por WorkshopSettings::DEFAULTS.
        Schema::create('workshop_settings', function (Blueprint $table) {
            $table->string('chave', 80)->primary();
            $table->text('valor')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('barrier_rules', function (Blueprint $table) {
            $table->increments('id');

            $table->string('codigo', 40)->unique();
            $table->string('nome');
            $table->string('etapa', 60)->nullable();

            $table->string('evento_inicio', 60);
            $table->string('evento_fim', 60);

            // minutos = prazo fixo após o evento de início;
            // operacao = tempo trabalhado (sem pausas) acima de X% do padrão.
            $table->string('modo', 12)->default('minutos');
            $table->unsignedInteger('prazo_minutos')->nullable();
            $table->unsignedSmallInteger('nivel1_percentual')->nullable();
            $table->unsignedSmallInteger('nivel2_percentual')->nullable();

            // Destinos separados por vírgula: tecnico_da_os, chefe, consultor,
            // pecas, financeiro, gerente, proprietario.
            $table->string('nivel1_destino', 120);
            $table->unsignedInteger('nivel2_apos_minutos')->nullable();
            $table->string('nivel2_destino', 120)->nullable();
            $table->unsignedInteger('nivel3_apos_minutos')->nullable();
            $table->string('nivel3_destino', 120)->nullable();

            $table->boolean('critico')->default(false);
            $table->boolean('ativo')->default(true);

            $table->timestamps();
        });

        Schema::create('barrier_instances', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedInteger('service_order_id');
            $table->string('barrier_code', 40);

            // Ex.: id do item (operação) quando a barreira é por operação.
            $table->unsignedInteger('referencia_id')->default(0);

            $table->dateTime('aberta_em');
            $table->dateTime('vence_em')->nullable();
            $table->dateTime('nivel2_em')->nullable();
            $table->dateTime('nivel3_em')->nullable();

            $table->unsignedTinyInteger('nivel_atual')->default(0);
            // no_prazo | vencida | cumprida
            $table->string('status', 12)->default('no_prazo');
            $table->boolean('cumprida_no_prazo')->nullable();
            $table->dateTime('fechada_em')->nullable();

            $table->timestamps();

            $table->index(['status', 'vence_em']);
            $table->index(['service_order_id', 'barrier_code', 'referencia_id'], 'barrier_instances_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barrier_instances');
        Schema::dropIfExists('barrier_rules');
        Schema::dropIfExists('workshop_settings');
    }
};
