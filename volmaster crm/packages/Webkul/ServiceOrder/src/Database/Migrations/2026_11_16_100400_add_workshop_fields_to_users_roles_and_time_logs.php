<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Celular para SMS de reserva, com o aceite registrado.
        Schema::table('users', function (Blueprint $table) {
            $table->string('celular', 20)->nullable();
            $table->dateTime('sms_aceite_em')->nullable();
        });

        // Qual papel da oficina cada função (role) do Krayin representa:
        // proprietario, gerente, chefe, consultor, tecnico, pecas, financeiro.
        // É daqui que as barreiras descobrem quem recebe cada alerta.
        Schema::table('roles', function (Blueprint $table) {
            $table->string('perfil_oficina', 20)->nullable();
        });

        // Tempo de cada operação (item de serviço) fica no mesmo registro de
        // pausas/retomadas do cronômetro que já existe.
        Schema::table('service_order_time_logs', function (Blueprint $table) {
            $table->unsignedInteger('item_id')->nullable()->after('mechanic_id');
            $table->index('item_id');
        });

        $this->grantValoresFinanceirosToExistingRoles();
        $this->guessPerfilOficina();
    }

    /**
     * Passa a existir a permissão "ver valores financeiros". Para ninguém
     * perder a tela de valores no deploy, todas as funções personalizadas
     * existentes recebem a permissão -- exceto as que, pelo nome, são de
     * técnico (mecânico, eletricista, mecatrônico). O dono confere em
     * Configurações > Funções.
     */
    protected function grantValoresFinanceirosToExistingRoles(): void
    {
        foreach (DB::table('roles')->where('permission_type', 'custom')->get() as $role) {
            if ($this->isTecnicoName($role->name)) {
                continue;
            }

            $permissions = json_decode($role->permissions ?? '[]', true) ?: [];

            if (in_array('ver_valores_financeiros', $permissions, true)) {
                continue;
            }

            $permissions[] = 'ver_valores_financeiros';

            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values($permissions))]);
        }
    }

    /**
     * Sugestão inicial do papel da oficina a partir do nome da função. O
     * dono ajusta na tela de configuração dos alertas.
     */
    protected function guessPerfilOficina(): void
    {
        // Modificador "u": os nomes têm letras acentuadas (Mecânico, Peças...).
        $map = [
            'proprietario' => '/propriet|dono|owner|administrador|admin/iu',
            'gerente' => '/gerente|manager/iu',
            'chefe' => '/chefe/iu',
            'consultor' => '/consultor/iu',
            'tecnico' => '/t[eé]cnico|mec[aâ]nico|eletricista|mecatr/iu',
            'pecas' => '/pe[cç]as/iu',
            'financeiro' => '/financ/iu',
        ];

        foreach (DB::table('roles')->whereNull('perfil_oficina')->get() as $role) {
            foreach ($map as $perfil => $pattern) {
                if (preg_match($pattern, $role->name)) {
                    DB::table('roles')->where('id', $role->id)->update(['perfil_oficina' => $perfil]);

                    break;
                }
            }
        }
    }

    /**
     * Função de técnico (mecânico, eletricista, mecatrônico). "Consultor
     * Técnico" tem "técnico" no nome, mas é consultor e vê valores: nomes
     * com outro cargo da oficina não contam como técnico.
     */
    protected function isTecnicoName(?string $name): bool
    {
        $name = (string) $name;

        if (preg_match('/consultor|gerente|chefe|financ|pe[cç]as|propriet|dono|admin/iu', $name)) {
            return false;
        }

        return (bool) preg_match('/t[eé]cnico|mec[aâ]nico|eletricista|mecatr/iu', $name);
    }

    public function down(): void
    {
        Schema::table('service_order_time_logs', function (Blueprint $table) {
            $table->dropIndex(['item_id']);
            $table->dropColumn('item_id');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('perfil_oficina');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['celular', 'sms_aceite_em']);
        });
    }
};
