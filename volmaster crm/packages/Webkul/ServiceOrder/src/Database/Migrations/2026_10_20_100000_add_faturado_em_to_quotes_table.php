<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the order first reached Faturamento -- the date commissions are
     * counted by (Comissões report). Stamped by ServiceOrderObserver.
     * Orders already past that point get their best known date.
     */
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->timestamp('faturado_em')->nullable()->index();
        });

        DB::table('quotes')
            ->whereIn('workshop_status', ['Faturamento', 'Pós-Venda', 'Concluída'])
            ->update(['faturado_em' => DB::raw('COALESCE(data_conclusao, updated_at)')]);
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn('faturado_em');
        });
    }
};
