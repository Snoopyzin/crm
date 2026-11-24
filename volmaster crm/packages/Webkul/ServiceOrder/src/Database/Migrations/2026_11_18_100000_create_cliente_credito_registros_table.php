<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Histórico da cor de crédito do CLIENTE (B1 do roteiro de 18/11/2026): cada análise é uma
     * linha nova (nunca se edita nem se apaga). A cor vigente é a última linha. Fica fora da
     * tabela de clientes de propósito: não aparece na linha do tempo do cliente para qualquer perfil.
     */
    public function up(): void
    {
        if (Schema::hasTable('cliente_credito_registros')) {
            return;
        }

        Schema::create('cliente_credito_registros', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('person_id')->index();
            $table->string('cor', 10);
            $table->string('cor_anterior', 10)->nullable();
            $table->string('sugestao_cor', 10)->nullable();
            $table->string('sugestao_motivo', 255)->nullable();
            $table->text('observacao')->nullable();
            $table->text('justificativa')->nullable();
            // A OS de onde o registro veio (quando veio de uma OS).
            $table->unsignedInteger('quote_id')->nullable()->index();
            $table->unsignedInteger('user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        // Nada a desfazer: histórico de crédito não se apaga.
    }
};
