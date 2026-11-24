<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajustes de tela pedidos pelo patrão (19/11/2026):
 *  - Cadastro e Ordem de Serviço: "Relato do motorista" e "Defeito relatado";
 *  - Diagnóstico: "O que precisa ser feito" (a tomada de decisão do diagnóstico).
 *
 * Só colunas novas e opcionais; nada do que já existe é alterado ou apagado.
 */
return new class extends Migration
{
    private const COLUNAS = ['relato_motorista', 'defeito_relatado', 'diagnostico_o_que_fazer'];

    public function up(): void
    {
        foreach (self::COLUNAS as $coluna) {
            if (! Schema::hasColumn('quotes', $coluna)) {
                Schema::table('quotes', function (Blueprint $table) use ($coluna) {
                    $table->text($coluna)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::COLUNAS as $coluna) {
            if (Schema::hasColumn('quotes', $coluna)) {
                Schema::table('quotes', function (Blueprint $table) use ($coluna) {
                    $table->dropColumn($coluna);
                });
            }
        }
    }
};
