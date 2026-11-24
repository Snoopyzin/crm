<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per tenant -- the fiscal identity ("emitente") used to issue
     * NFS-e through Focus NFe. Lives on the tenant's own database, same as
     * fiscal_documents/api_keys, since each tenant fills this in themselves.
     */
    public function up(): void
    {
        Schema::create('fiscal_settings', function (Blueprint $table) {
            $table->increments('id');

            $table->string('razao_social')->nullable();
            $table->string('nome_fantasia')->nullable();
            $table->string('cnpj', 18)->nullable();
            $table->string('inscricao_municipal')->nullable();
            $table->string('codigo_municipio', 7)->nullable();
            $table->string('uf', 2)->nullable();
            $table->boolean('optante_simples_nacional')->default(true);
            $table->string('item_lista_servico')->default('14.01');
            $table->decimal('aliquota_iss', 5, 2)->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_settings');
    }
};
