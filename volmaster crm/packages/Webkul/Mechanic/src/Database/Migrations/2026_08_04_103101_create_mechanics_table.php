<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mechanics', function (Blueprint $table) {
            $table->increments('id');

            $table->string('nome');
            $table->enum('especialidade', [
                'motor',
                'transmissao',
                'eletrica',
                'diagnostico',
                'suspensao',
                'freios',
                'hidraulica',
                'arrefecimento',
            ]);
            $table->decimal('years_of_experience', 4, 1)->nullable();
            $table->decimal('avaliacao', 2, 1)->default(0);
            $table->string('foto_path')->nullable();
            $table->enum('status', ['Ativo', 'Lenda'])->default('Ativo');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mechanics');
    }
};
