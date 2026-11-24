<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * O Novo Cadastro grava o endereço do cliente (CEP, rua, número, bairro...)
     * no atributo "Endereço" das pessoas. Ele já nasce numa migration de
     * outubro, mas bancos criados depois (ou refeitos pelo instalador) podem
     * não tê-lo: este passo só o cria se faltar, sem tocar em nada existente.
     */
    public function up(): void
    {
        if (DB::table('attributes')->where('code', 'endereco')->where('entity_type', 'persons')->exists()) {
            return;
        }

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

    public function down(): void
    {
        // Nada a desfazer: o atributo pertence à migration original.
    }
};
