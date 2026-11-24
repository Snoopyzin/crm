<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tempo padrão (em horas) de cada serviço do catálogo: ao lançar o serviço na OS as horas
     * vêm daqui (B5 do roteiro) e viram o tempo padrão do cronômetro do técnico. Opcional.
     */
    public function up(): void
    {
        if (! Schema::hasTable('services') || Schema::hasColumn('services', 'tempo_padrao_horas')) {
            return;
        }

        Schema::table('services', function (Blueprint $table) {
            $table->decimal('tempo_padrao_horas', 6, 2)->nullable()->after('preco_padrao');
        });
    }

    public function down(): void
    {
        // Nada a desfazer: não se apaga dado.
    }
};
