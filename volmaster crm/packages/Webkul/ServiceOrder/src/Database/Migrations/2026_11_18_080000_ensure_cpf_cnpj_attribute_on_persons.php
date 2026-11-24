<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O Cadastro grava o CPF/CNPJ do cliente no atributo "cpf_cnpj" das
     * pessoas. O semeador do instalador apaga esse atributo em oficinas
     * criadas depois da migration original: este passo só o recria se faltar,
     * sem tocar em nada existente.
     */
    public function up(): void
    {
        if (! Schema::hasTable('attributes')) {
            return;
        }

        if (DB::table('attributes')->where('code', 'cpf_cnpj')->where('entity_type', 'persons')->exists()) {
            return;
        }

        DB::table('attributes')->insert([
            'code' => 'cpf_cnpj',
            'name' => 'CPF/CNPJ',
            'type' => 'text',
            'entity_type' => 'persons',
            'lookup_type' => null,
            'validation' => null,
            'sort_order' => '5',
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
