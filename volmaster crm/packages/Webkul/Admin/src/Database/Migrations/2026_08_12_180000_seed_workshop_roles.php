<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds a starting set of roles for the workshop's different job functions.
 * Permissions here are a reasonable starting point, not a final answer --
 * fine-tune exact access per role via Configurações > Usuários > Funções.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roles = [
            [
                'name' => 'Financeiro',
                'description' => 'Acesso a Ordens de Serviço, Atendimentos Remotos e Contatos para conferência de valores e pagamentos.',
                'permissions' => [
                    'dashboard',
                    'service_orders', 'service_orders.view', 'service_orders.edit',
                    'remote_services', 'remote_services.view',
                    'contacts', 'contacts.persons', 'contacts.persons.view',
                    'quotes',
                ],
            ],
            [
                'name' => 'Mecânico',
                'description' => 'Acesso a Ordens de Serviço, Veículos, Peças, Baias de Trabalho e Atendimentos Remotos.',
                'permissions' => [
                    'dashboard',
                    'service_orders', 'service_orders.view', 'service_orders.edit',
                    'vehicles', 'vehicles.view', 'vehicles.create', 'vehicles.edit',
                    'parts', 'parts.view',
                    'work_bays', 'work_bays.view', 'work_bays.edit',
                    'mechanics', 'mechanics.view',
                    'remote_services', 'remote_services.view', 'remote_services.finish',
                ],
            ],
            [
                'name' => 'Consultor',
                'description' => 'Acesso a Contatos, Veículos, Ordens de Serviço e Atendimentos Remotos para atendimento ao cliente.',
                'permissions' => [
                    'dashboard',
                    'contacts', 'contacts.persons', 'contacts.persons.view', 'contacts.persons.create', 'contacts.persons.edit',
                    'vehicles', 'vehicles.view', 'vehicles.create', 'vehicles.edit',
                    'service_orders', 'service_orders.view', 'service_orders.create',
                    'remote_services', 'remote_services.view', 'remote_services.create', 'remote_services.approve',
                    'service_requests', 'service_requests.view', 'service_requests.convert',
                ],
            ],
            [
                'name' => 'Diagnóstico',
                'description' => 'Técnico do grupo Diagnóstico -- Atendimentos Remotos e Ordens de Serviço. A separação de quem vê o quê é feita pelo Grupo do usuário, não pela Função.',
                'permissions' => [
                    'dashboard',
                    'remote_services', 'remote_services.view', 'remote_services.create', 'remote_services.finish',
                    'service_orders', 'service_orders.view',
                    'vehicles', 'vehicles.view',
                ],
            ],
            [
                'name' => 'Programador',
                'description' => 'Técnico do grupo Programações -- Atendimentos Remotos e Ordens de Serviço. A separação de quem vê o quê é feita pelo Grupo do usuário, não pela Função.',
                'permissions' => [
                    'dashboard',
                    'remote_services', 'remote_services.view', 'remote_services.create', 'remote_services.finish',
                    'service_orders', 'service_orders.view',
                    'vehicles', 'vehicles.view',
                ],
            ],
        ];

        foreach ($roles as $role) {
            if (DB::table('roles')->where('name', $role['name'])->exists()) {
                continue;
            }

            DB::table('roles')->insert([
                'name' => $role['name'],
                'description' => $role['description'],
                'permission_type' => 'custom',
                'permissions' => json_encode($role['permissions']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('roles')->whereIn('name', ['Financeiro', 'Mecânico', 'Consultor', 'Diagnóstico', 'Programador'])->delete();
    }
};
