<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Horário da previsão de conclusão, para o veículo que entra e sai no mesmo
     * dia. É opcional e fica numa coluna própria: as OS antigas continuam só com
     * a data (e o prazo vale até o fim do dia, como sempre valeu).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('quotes', 'hora_previsao')) {
            Schema::table('quotes', function (Blueprint $table) {
                $table->time('hora_previsao')->nullable()->after('data_previsao');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('quotes', 'hora_previsao')) {
            Schema::table('quotes', function (Blueprint $table) {
                $table->dropColumn('hora_previsao');
            });
        }
    }
};
