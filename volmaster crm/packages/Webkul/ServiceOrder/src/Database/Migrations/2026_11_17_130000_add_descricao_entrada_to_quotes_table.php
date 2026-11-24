<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Checklist de entrada do Novo Cadastro: a descrição do veículo (estado
     * geral, cor, avarias visíveis). Km, combustível, itens e fotos já tinham
     * colunas e anexos do termo de entrada; o vídeo é um anexo novo (tipo
     * "termo_video") e não precisa de coluna.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('quotes', 'descricao_entrada')) {
            Schema::table('quotes', function (Blueprint $table) {
                $table->text('descricao_entrada')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('quotes', 'descricao_entrada')) {
            Schema::table('quotes', function (Blueprint $table) {
                $table->dropColumn('descricao_entrada');
            });
        }
    }
};
