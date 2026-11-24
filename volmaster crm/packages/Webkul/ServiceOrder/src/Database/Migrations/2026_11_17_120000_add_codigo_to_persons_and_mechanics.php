<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Código sequencial (1, 2, 3...) de cada cliente e de cada mecânico, para o
     * dono da oficina ter noção do tamanho da carteira. Quem já está cadastrado
     * é numerado na ordem em que entrou; os próximos saem do contador.
     */
    private const TABELAS = ['persons' => 'clientes', 'mechanics' => 'mecanicos'];

    public function up(): void
    {
        if (! Schema::hasTable('codigo_sequencias')) {
            Schema::create('codigo_sequencias', function (Blueprint $table) {
                $table->string('nome', 30)->primary();
                $table->unsignedInteger('ultimo')->default(0);
            });
        }

        foreach (self::TABELAS as $tabela => $sequencia) {
            if (! Schema::hasTable($tabela)) {
                continue;
            }

            if (! Schema::hasColumn($tabela, 'codigo')) {
                Schema::table($tabela, function (Blueprint $table) {
                    $table->unsignedInteger('codigo')->nullable()->unique();
                });
            }

            // Quem ainda não tem código recebe o próximo número, na ordem de cadastro.
            DB::statement('SET @codigo = '.(int) DB::table($tabela)->max('codigo'));
            DB::statement("UPDATE {$tabela} SET codigo = (@codigo := @codigo + 1) WHERE codigo IS NULL ORDER BY id");

            DB::table('codigo_sequencias')->updateOrInsert(
                ['nome' => $sequencia],
                ['ultimo' => (int) DB::table($tabela)->max('codigo')]
            );
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABELAS) as $tabela) {
            if (Schema::hasColumn($tabela, 'codigo')) {
                Schema::table($tabela, function (Blueprint $table) {
                    $table->dropUnique(['codigo']);
                    $table->dropColumn('codigo');
                });
            }
        }

        Schema::dropIfExists('codigo_sequencias');
    }
};
