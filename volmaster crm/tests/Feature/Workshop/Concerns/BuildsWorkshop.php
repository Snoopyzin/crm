<?php

namespace Tests\Feature\Workshop\Concerns;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Mechanic\Models\Mechanic;
use Webkul\ServiceOrder\Models\AlcadaRegra;
use Webkul\ServiceOrder\Models\BarrierRule;
use Webkul\ServiceOrder\Models\BonusMarcador;
use Webkul\ServiceOrder\Models\Colaborador;
use Webkul\ServiceOrder\Models\IndicatorDefinition;
use Webkul\ServiceOrder\Models\PlanoConta;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderItem;
use Webkul\ServiceOrder\Repositories\ServiceOrderRepository;
use Webkul\ServiceOrder\Services\Sms\SmsManager;
use Webkul\ServiceOrder\Services\WorkshopSettings;
use Webkul\User\Models\Role;
use Webkul\User\Models\User;
use Webkul\Vehicle\Repositories\VehicleRepository;

/**
 * Monta oficina, usuários por papel, OS e itens para os testes do piloto.
 * Usado com DatabaseTransactions: tudo é desfeito ao fim de cada teste.
 */
trait BuildsWorkshop
{
    protected function setUpWorkshop(): void
    {
        WorkshopSettings::flush();
        // As tabelas padrão são semeadas uma vez por banco; cada teste volta ao banco vazio.
        BarrierRule::forgetSeeded();
        IndicatorDefinition::forgetSeeded();
        AlcadaRegra::forgetSeeded();
        BonusMarcador::forgetSeeded();
        PlanoConta::forgetSeeded();
        SmsManager::fake(null);
        Carbon::setTestNow();

        // A função "Administrator" do seed é reconhecida como proprietário pela
        // migration; nos testes só os usuários criados em cada teste contam.
        DB::table('roles')->update(['perfil_oficina' => null]);

        // Atributo "Endereço" do cliente (criado por migration em produção).
        if (! DB::table('attributes')->where('code', 'endereco')->where('entity_type', 'persons')->exists()) {
            DB::table('attributes')->insert([
                'code' => 'endereco', 'name' => 'Endereço', 'type' => 'address', 'entity_type' => 'persons',
                'sort_order' => 6, 'is_required' => 0, 'is_unique' => 0, 'quick_add' => 0, 'is_user_defined' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Atributo "CPF/CNPJ" do cliente: criado por migration em produção,
        // mas o seeder do instalador recria os atributos do zero no banco local.
        if (! DB::table('attributes')->where('code', 'cpf_cnpj')->where('entity_type', 'persons')->exists()) {
            DB::table('attributes')->insert([
                'code' => 'cpf_cnpj', 'name' => 'CPF/CNPJ', 'type' => 'text', 'entity_type' => 'persons',
                'sort_order' => 5, 'is_required' => 0, 'is_unique' => 0, 'quick_add' => 0, 'is_user_defined' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * Liga o piloto. Por padrão só as barreiras do piloto original ficam
     * ativas (os testes antigos medem alertas de uma barreira por vez); os
     * testes das demais pedem as suas em $barriers (ou 'todas').
     *
     * @param  string[]|string|null  $barriers
     */
    /**
     * O mínimo que a tela exige para LIGAR o piloto (A5): valor da hora, impostos,
     * início da mentoria e um gerente ativo. `enablePilot()` grava a chave direto e não precisa disto.
     */
    protected function completeFichaForPilot(): void
    {
        WorkshopSettings::set('valor_hora_mao_obra', 150);
        WorkshopSettings::set('pct_impostos', 6);
        WorkshopSettings::set('inicio_mentoria', '2026-11-01');

        Colaborador::create(['nome' => 'Gerente Teste', 'cargos' => 'gerente', 'classificacao' => 'administracao', 'ativo' => true]);
    }

    protected function enablePilot(array $settings = [], array|string|null $barriers = null): void
    {
        WorkshopSettings::set('piloto_ativo', true);

        foreach ($settings as $key => $value) {
            WorkshopSettings::set($key, $value);
        }

        BarrierRule::query()->delete();
        BarrierRule::forgetSeeded();
        BarrierRule::ensureDefaults();

        if ($barriers !== 'todas') {
            $this->onlyBarriers($barriers ?? ['orcamento_2h', 'operacao_tempo', 'critico_bloqueio_forcado', 'critico_saida_sem_nota']);
        }
    }

    /**
     * Deixa ativas somente as barreiras informadas.
     *
     * @param  string[]  $codes
     */
    protected function onlyBarriers(array $codes): void
    {
        BarrierRule::query()->update(['ativo' => false]);
        BarrierRule::whereIn('codigo', $codes)->update(['ativo' => true]);
    }

    /**
     * Função (role) com o papel da oficina. Por padrão com permissão total;
     * passe $permissions para uma função personalizada.
     */
    protected function makeRole(string $perfil, ?array $permissions = null): Role
    {
        $role = Role::create([
            'name' => ucfirst($perfil).' '.Str::random(5),
            'description' => 'teste',
            'permission_type' => $permissions === null ? 'all' : 'custom',
            'permissions' => $permissions,
        ]);

        DB::table('roles')->where('id', $role->id)->update(['perfil_oficina' => $perfil]);

        return $role->refresh();
    }

    protected function makeUser(string $perfil, ?array $permissions = null, array $extra = []): User
    {
        $role = $this->makeRole($perfil, $permissions);

        return User::create(array_merge([
            'name' => ucfirst($perfil).' '.Str::random(4),
            'email' => Str::random(8).'@teste.local',
            'password' => bcrypt('senha-teste'),
            'status' => 1,
            'role_id' => $role->id,
            'view_permission' => 'global',
        ], $extra));
    }

    protected function makeMechanic(string $nome = 'Técnico Teste'): Mechanic
    {
        return Mechanic::create(['nome' => $nome, 'especialidade' => 'Diesel', 'status' => 'Ativo']);
    }

    protected function makePerson(array $override = [])
    {
        return app(PersonRepository::class)->create(array_merge([
            'entity_type' => 'persons',
            'name' => 'Transportadora Teste',
            'cpf_cnpj' => '11.222.333/0001-81',
            'emails' => [['value' => Str::random(6).'@cliente.local', 'label' => 'Trabalho']],
            'contact_numbers' => [['value' => '+55 (11) 98765-4321', 'label' => 'WhatsApp']],
        ], $override));
    }

    protected function makeVehicle(int $personId, array $override = [])
    {
        return app(VehicleRepository::class)->create(array_merge([
            'person_id' => $personId,
            'marca' => 'Scania',
            'modelo' => 'R450',
            'placa' => strtoupper(Str::random(3)).rand(1000, 9999),
            'ano' => 2020,
            'tipo' => 'caminhoes',
            'status' => 'Ativo',
        ], $override));
    }

    protected function makeOs(array $override = []): ServiceOrder
    {
        $person = $this->makePerson();
        $vehicle = $this->makeVehicle($person->id);

        return app(ServiceOrderRepository::class)->create(array_merge([
            'person_id' => $person->id,
            'vehicle_id' => $vehicle->id,
            'subject' => 'Revisão geral',
            'workshop_status' => ServiceOrder::STAGE_CADASTRO,
            // Sem usuário logado nos testes: usa o administrador do seed.
            'user_id' => 1,
        ], $override))->refresh();
    }

    protected function makeServiceItem(ServiceOrder $os, float $hours = 1.0, ?int $mechanicId = null, string $name = 'Troca de embreagem'): ServiceOrderItem
    {
        $item = new ServiceOrderItem;
        $item->quote_id = $os->id;
        $item->product_id = 0;
        $item->tipo = ServiceOrderItem::TIPO_SERVICO;
        $item->name = $name;
        $item->price = 100;
        $item->quantity = 1;
        $item->hours = $hours;
        $item->funcionario_id = $mechanicId;
        $item->total = 100 * $hours;
        $item->save();

        return $item;
    }

    protected function at(string $dateTime): Carbon
    {
        $moment = Carbon::parse($dateTime, 'America/Sao_Paulo');
        Carbon::setTestNow($moment);

        return $moment;
    }
}
