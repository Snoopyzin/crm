<?php

use Illuminate\Support\Facades\DB;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\User\Models\Role;
use Webkul\User\Models\User;

// Quadro de pátio (pedido do patrão, 19/11/2026): a placa do veículo desenhada como placa de verdade,
// o nome do cliente em destaque, o clique abrindo a OS e a busca ao vivo por placa ou cliente.

function usuarioDoPatio(): User
{
    $role = Role::create(['name' => 'Admin pátio '.uniqid(), 'description' => 'x', 'permission_type' => 'all', 'permissions' => null]);

    return User::create([
        'name' => 'Admin pátio',
        'email' => uniqid('patio').'@teste.local',
        'password' => bcrypt('senha-teste'),
        'status' => 1,
        'role_id' => $role->id,
        'view_permission' => 'global',
    ]);
}

it('cada caminhão do quadro mostra a placa desenhada (Mercosul e modelo antigo) e o nome do cliente, e abre a OS', function () {
    $mercosul = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_DIAGNOSTICO]);
    $mercosul->vehicle->forceFill(['placa' => 'QWE4R56'])->save();
    $mercosul->person->forceFill(['name' => 'Fretes Horizonte LTDA'])->save();

    $antiga = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_ORCAMENTO]);
    $antiga->vehicle->forceFill(['placa' => 'abc-1234'])->save();
    $antiga->person->forceFill(['name' => 'Transportes Aurora SA'])->save();

    $this->actingAs(usuarioDoPatio(), 'user');

    $quadro = $this->get(route('admin.rotina.patio'));

    $quadro->assertOk()
        ->assertSee('class="placa-veiculo"', false)
        ->assertSee('title="Placa QWE4R56"', false)
        ->assertSee('title="Placa ABC-1234"', false)
        ->assertSee('BRASIL')
        ->assertSee('Fretes Horizonte LTDA')
        ->assertSee('Transportes Aurora SA')
        ->assertSee(route('admin.service_orders.view', $mercosul->id), false)
        ->assertSee('id="patio-busca"', false);

    // Mercosul usa a faixa azul; o modelo antigo, a cinza.
    $html = $quadro->getContent();

    expect($html)->toContain('background:#1d4ed8')->and($html)->toContain('background:#6b7280');
});

it('o texto de busca de cada caminhão leva placa sem hífen, cliente e veículo, sem acento e em minúsculas', function () {
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_EM_EXECUCAO]);
    $os->vehicle->forceFill(['placa' => 'MMM-2299'])->save();
    $os->person->forceFill(['name' => 'Kezianne Vieira Moráis Campos'])->save();

    $this->actingAs(usuarioDoPatio(), 'user');

    $this->get(route('admin.rotina.patio'))
        ->assertOk()
        ->assertSee('data-busca="mmm2299 mmm-2299 kezianne vieira morais campos', false)
        ->assertSee('data-patio-card', false);
});

it('o quadro mostra o nome da etapa como o resto do sistema ("Cadastro do cliente") e a placa vazia não quebra a tela', function () {
    $semPlaca = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_CADASTRO]);
    DB::table('vehicles')->where('id', $semPlaca->vehicle_id)->update(['placa' => '']);

    $this->actingAs(usuarioDoPatio(), 'user');

    $this->get(route('admin.rotina.patio'))
        ->assertOk()
        ->assertSee('Cadastro do cliente')
        ->assertSee('sem placa');
});

it('a placa desenhada não depende de classe nova de estilo: o desenho vem todo no próprio HTML', function () {
    $html = view('service_order::components.placa', ['placa' => 'ABC1D23'])->render();

    expect($html)->toContain('border:2px solid #111827')
        ->and($html)->toContain('ABC1D23')
        ->and($html)->toContain('BRASIL');

    $antiga = view('service_order::components.placa', ['placa' => 'xyz1234', 'escala' => 2])->render();

    expect($antiga)->toContain('XYZ-1234')->and($antiga)->toContain('font-size:36px');

    expect(trim(view('service_order::components.placa', ['placa' => null])->render()))->toContain('sem placa');
});
