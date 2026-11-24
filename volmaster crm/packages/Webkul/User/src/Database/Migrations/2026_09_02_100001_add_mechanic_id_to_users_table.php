<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links a system login (Usuário) to a Mecânico reference, so that when
     * this user's Permissão de Visualização is "Individual", the workshop
     * pipeline knows which Ordens de Serviço are "theirs" (assigned to
     * them as mechanic), not just the ones they personally created.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('mechanic_id')->nullable()->after('view_permission');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('mechanic_id');
        });
    }
};
