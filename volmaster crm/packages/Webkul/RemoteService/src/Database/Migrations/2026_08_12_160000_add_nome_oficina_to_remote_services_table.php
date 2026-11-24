<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remote_services', function (Blueprint $table) {
            $table->string('nome_oficina')->nullable()->after('cliente_nome_livre');
        });
    }

    public function down(): void
    {
        Schema::table('remote_services', function (Blueprint $table) {
            $table->dropColumn('nome_oficina');
        });
    }
};
