<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Registro de acesso (LGPD, capítulo 11 passo 7): quem abriu ou mexeu em
        // dados financeiros, de pessoal e do painel do dono. Só inserção.
        Schema::create('acessos_sensiveis', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id')->nullable()->index();
            $table->string('rota', 80)->index();
            $table->string('metodo', 8);
            $table->string('ip', 45)->nullable();
            $table->dateTime('acessado_em')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acessos_sensiveis');
    }
};
