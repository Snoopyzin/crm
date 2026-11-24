<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * NCM (Nomenclatura Comum do Mercosul) and CEST (Código Especificador
     * da Substituição Tributária) -- required on the peça to emit a NF-e
     * for it later via Fiscal.
     */
    public function up(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            $table->string('ncm', 8)->nullable()->after('nome');
            $table->string('cest', 7)->nullable()->after('ncm');
        });
    }

    public function down(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            $table->dropColumn(['ncm', 'cest']);
        });
    }
};
