<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the mechanic is currently working on during Em Execução (e.g.
     * "Trocando o cabeçote") -- free text, shown next to the timer.
     */
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->string('execucao_tarefa_atual')->nullable()->after('negociacao_observacoes');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn('execucao_tarefa_atual');
        });
    }
};
