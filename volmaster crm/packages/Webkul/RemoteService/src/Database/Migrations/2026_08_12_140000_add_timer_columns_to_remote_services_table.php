<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remote_services', function (Blueprint $table) {
            $table->timestamp('iniciado_em')->nullable()->after('status');
            $table->timestamp('finalizado_em')->nullable()->after('iniciado_em');
        });
    }

    public function down(): void
    {
        Schema::table('remote_services', function (Blueprint $table) {
            $table->dropColumn(['iniciado_em', 'finalizado_em']);
        });
    }
};
