<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aceite do termo de entrada pelo link enviado ao WhatsApp do cliente (B2 do roteiro):
     * cada link é uma linha; o token só existe no link (aqui fica só o hash) e o aceite guarda
     * data, hora, IP e aparelho de quem aceitou, com a assinatura desenhada na tela.
     */
    public function up(): void
    {
        if (Schema::hasTable('service_order_termos')) {
            return;
        }

        Schema::create('service_order_termos', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('quote_id')->index();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expira_em');
            $table->unsignedInteger('criado_por')->nullable();
            $table->timestamp('aceito_em')->nullable();
            $table->string('aceite_nome', 150)->nullable();
            $table->boolean('autoriza_rodagem')->nullable();
            $table->string('aceite_ip', 45)->nullable();
            $table->string('aceite_user_agent', 250)->nullable();
            $table->longText('assinatura')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        // Nada a desfazer: o aceite do termo é prova e não se apaga.
    }
};
