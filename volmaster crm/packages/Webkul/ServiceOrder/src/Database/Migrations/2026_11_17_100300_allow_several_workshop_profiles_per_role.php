<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Acúmulo de cargos (capítulo 2): uma função pode representar mais de um
        // papel da oficina (ex.: "chefe,tecnico"). Os papéis ficam separados por
        // vírgula; as permissões da função já somam o que cada cargo faz.
        Schema::table('roles', function (Blueprint $table) {
            $table->string('perfil_oficina', 120)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('perfil_oficina', 20)->nullable()->change();
        });
    }
};
