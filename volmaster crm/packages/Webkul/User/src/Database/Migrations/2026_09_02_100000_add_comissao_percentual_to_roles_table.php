<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How much commission (%) anyone with this Função/role earns -- shown
     * on the role's own create/edit form, next to Nome/Descrição.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->decimal('comissao_percentual', 5, 2)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('comissao_percentual');
        });
    }
};
