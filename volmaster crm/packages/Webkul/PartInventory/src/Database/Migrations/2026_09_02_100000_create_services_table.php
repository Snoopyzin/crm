<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catálogo de Serviços -- o equivalente de "parts" pra mão de obra:
     * sem estoque/depósito (serviço não é estocado), mas com o mesmo
     * agrupamento por Grupo (part_categories) usado pelas Peças, pra ficar
     * tudo organizado num lugar só.
     */
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->increments('id');

            $table->string('nome');
            $table->text('descricao')->nullable();

            $table->integer('part_category_id')->unsigned()->nullable();
            $table->foreign('part_category_id')->references('id')->on('part_categories')->onDelete('set null');

            $table->decimal('preco_padrao', 12, 4)->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
