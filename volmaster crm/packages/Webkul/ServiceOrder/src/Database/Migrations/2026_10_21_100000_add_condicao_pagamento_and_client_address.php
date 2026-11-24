<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * For the printed OS: the agreed payment terms (set in Negociação) and
     * the client's full address -- a custom "Endereço" attribute on persons,
     * so it shows up in the client form like CPF/CNPJ does.
     */
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->string('condicao_pagamento')->nullable();
        });

        if (! DB::table('attributes')->where('code', 'endereco')->where('entity_type', 'persons')->exists()) {
            DB::table('attributes')->insert([
                'code' => 'endereco',
                'name' => 'Endereço',
                'type' => 'address',
                'entity_type' => 'persons',
                'lookup_type' => null,
                'validation' => null,
                'sort_order' => '6',
                'is_required' => '0',
                'is_unique' => '0',
                'quick_add' => '0',
                'is_user_defined' => '1',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn('condicao_pagamento');
        });

        DB::table('attributes')->where('code', 'endereco')->where('entity_type', 'persons')->delete();
    }
};
