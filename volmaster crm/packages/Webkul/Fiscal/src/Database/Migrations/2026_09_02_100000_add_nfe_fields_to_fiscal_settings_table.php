<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * NFS-e (serviço) only needed CNPJ/inscrição municipal. NF-e (produto,
     * modelo 55) also needs a Inscrição Estadual (IE), CFOP padrão and o
     * regime tributário -- exigidos pelo SEFAZ, não pela prefeitura.
     */
    public function up(): void
    {
        Schema::table('fiscal_settings', function (Blueprint $table) {
            $table->string('inscricao_estadual')->nullable()->after('inscricao_municipal');
            $table->string('cfop_padrao', 4)->default('5102')->after('inscricao_estadual');
            $table->string('regime_tributario', 1)->nullable()->after('cfop_padrao');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_settings', function (Blueprint $table) {
            $table->dropColumn(['inscricao_estadual', 'cfop_padrao', 'regime_tributario']);
        });
    }
};
