<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anotações e anexos só aparecem no link de acompanhamento do cliente quando a
 * oficina marca "Mostrar ao cliente". As que já existem ficam ocultas (padrão
 * seguro): nada que era interno vira público sem alguém decidir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('service_order_extras') && ! Schema::hasColumn('service_order_extras', 'visivel_cliente')) {
            Schema::table('service_order_extras', function (Blueprint $table) {
                $table->boolean('visivel_cliente')->default(false)->after('arquivos');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('service_order_extras', 'visivel_cliente')) {
            Schema::table('service_order_extras', function (Blueprint $table) {
                $table->dropColumn('visivel_cliente');
            });
        }
    }
};
