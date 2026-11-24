<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Histórico importado das planilhas de medição (capítulo 9.3) aparece no
        // painel com a marca "origem: planilha".
        foreach (['indicator_snapshots', 'bonus_apuracoes', 'mentoria_registros'] as $tabela) {
            Schema::table($tabela, function (Blueprint $table) {
                $table->string('origem', 12)->default('sistema');
            });
        }
    }

    public function down(): void
    {
        foreach (['indicator_snapshots', 'bonus_apuracoes', 'mentoria_registros'] as $tabela) {
            Schema::table($tabela, function (Blueprint $table) {
                $table->dropColumn('origem');
            });
        }
    }
};
