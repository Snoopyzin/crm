<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remote_services', function (Blueprint $table) {
            $table->string('whatsapp')->nullable()->after('cliente_nome_livre');
            $table->string('fila_token', 36)->nullable()->unique()->after('whatsapp');
        });
    }

    public function down(): void
    {
        Schema::table('remote_services', function (Blueprint $table) {
            $table->dropColumn(['whatsapp', 'fila_token']);
        });
    }
};
