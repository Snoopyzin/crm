<?php

namespace Webkul\ServiceOrder\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Refaz numa oficina recém-criada o que o semeador do instalador apaga depois
 * das migrations (atributos do cliente que o Cadastro usa e o papel do dono).
 * Idempotente: usa a conexão padrão, que no gancho de provisionamento já
 * aponta para o banco da oficina nova.
 */
class OficinaPadrao
{
    /**
     * Atributos do cliente que as migrations criam e o AttributeSeeder do instalador apaga.
     */
    protected const ATRIBUTOS_CLIENTE = [
        'cpf_cnpj' => ['name' => 'CPF/CNPJ', 'type' => 'text', 'sort_order' => '5'],
        'endereco' => ['name' => 'Endereço', 'type' => 'address', 'sort_order' => '6'],
    ];

    /**
     * @return array<string, bool> o que foi criado/ajustado agora
     */
    public function garantir(): array
    {
        $feito = [];

        foreach (self::ATRIBUTOS_CLIENTE as $codigo => $dados) {
            if (DB::table('attributes')->where('code', $codigo)->where('entity_type', 'persons')->exists()) {
                continue;
            }

            DB::table('attributes')->insert([
                'code' => $codigo,
                'name' => $dados['name'],
                'type' => $dados['type'],
                'entity_type' => 'persons',
                'lookup_type' => null,
                'validation' => null,
                'sort_order' => $dados['sort_order'],
                'is_required' => '0',
                'is_unique' => '0',
                'quick_add' => '0',
                'is_user_defined' => '1',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $feito[$codigo] = true;
        }

        // O Administrador do instalador (id 1) é o proprietário; a migration que
        // adivinha o papel pelo nome rodou antes de ele existir.
        if (Schema::hasColumn('roles', 'perfil_oficina')) {
            $ajustadas = DB::table('roles')->where('id', 1)->where('permission_type', 'all')
                ->where(fn ($q) => $q->whereNull('perfil_oficina')->orWhere('perfil_oficina', ''))
                ->update(['perfil_oficina' => 'proprietario']);

            if ($ajustadas) {
                $feito['perfil_proprietario'] = true;
            }
        }

        return $feito;
    }
}
