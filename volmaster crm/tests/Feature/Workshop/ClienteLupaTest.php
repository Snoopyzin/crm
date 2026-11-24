<?php

use Webkul\User\Models\Role;
use Webkul\User\Models\User;

// Pedido do patrão (21/11/2026): o campo "Cliente" precisa ter uma lupa para clicar, digitar e pesquisar.
// Na abertura da OS o campo de pesquisa já existia (sem lupa); na edição ainda era a lista com todos os clientes.

function adminDaLupa(): User
{
    $role = Role::create(['name' => 'Admin lupa '.uniqid(), 'description' => 'x', 'permission_type' => 'all', 'permissions' => null]);

    return User::create([
        'name' => 'Admin lupa',
        'email' => uniqid('lupa').'@teste.local',
        'password' => bcrypt('senha-teste'),
        'status' => 1,
        'role_id' => $role->id,
        'view_permission' => 'global',
    ]);
}

it('na abertura da OS o campo Cliente tem a lupa, que pesquisa o que foi digitado', function () {
    $this->actingAs(adminDaLupa(), 'user');

    $this->get(route('admin.service_orders.create'))
        ->assertOk()
        ->assertSee('id="cliente-lupa"', false)
        ->assertSee('aria-label="Pesquisar cliente"', false)
        ->assertSee("alvo.closest('#cliente-lupa')", false)
        ->assertSee('id="cliente-busca"', false);
});

it('na edição da OS o Cliente é a pesquisa com lupa e não mais a lista com todos os clientes', function () {
    $os = $this->makeOs();
    $os->person()->first()->forceFill(['name' => 'Fretes do Zeca'])->save();
    $outro = $this->makePerson(['name' => 'Transportadora Que Nao Deve Aparecer']);

    $this->actingAs(adminDaLupa(), 'user');

    $edicao = $this->get(route('admin.service_orders.edit', $os->id))->assertOk();

    // O cliente da OS já vem escolhido, no campo escondido que o formulário envia.
    $edicao->assertSee('<input type="hidden" id="person_id" name="person_id" value="'.$os->person_id.'">', false)
        ->assertSee('Fretes do Zeca')
        ->assertSee('id="cliente-lupa"', false)
        ->assertSee('id="cliente-busca"', false)
        ->assertSee('Trocar cliente');

    // Os outros clientes não são mais despejados numa lista (com muitos clientes ficava impraticável).
    $edicao->assertDontSee($outro->name);

    expect($edicao->getContent())->not->toMatch('/<select[^>]*id="person_id"/s');
});

it('na edição o pop-up de cadastro vencido não abre sozinho, e o veículo atual da OS é mantido', function () {
    $os = $this->makeOs();

    $this->actingAs(adminDaLupa(), 'user');

    $this->get(route('admin.service_orders.edit', $os->id))
        ->assertOk()
        ->assertSee('const avisarAoAbrir = false;', false)
        // O campo avisa "mudou" ao abrir a página; a lista de veículos não pode apagar o veículo da OS.
        ->assertSee('vehicleSelect.value = atual;', false);

    // Na abertura da OS o aviso continua valendo (chegou pela aba "Buscar cliente" com cadastro vencido).
    $this->get(route('admin.service_orders.create'))
        ->assertOk()
        ->assertSee('const avisarAoAbrir = true;', false);
});

it('depois de um erro no formulário de edição, volta com o cliente que foi escolhido', function () {
    $os = $this->makeOs();
    $outro = $this->makePerson(['name' => 'Cliente Escolhido Depois']);

    $this->actingAs(adminDaLupa(), 'user');

    $this->withSession(['_old_input' => ['person_id' => $outro->id]])
        ->get(route('admin.service_orders.edit', $os->id))
        ->assertOk()
        ->assertSee('<input type="hidden" id="person_id" name="person_id" value="'.$outro->id.'">', false)
        ->assertSee('Cliente Escolhido Depois');
});

it('salvar a edição com o cliente pesquisado troca o cliente da OS', function () {
    $os = $this->makeOs();
    $novo = $this->makePerson(['name' => 'Cliente Novo da OS']);
    $veiculoNovo = $this->makeVehicle($novo->id);

    $this->actingAs(adminDaLupa(), 'user');

    $this->put(route('admin.service_orders.update', $os->id), [
        'person_id' => $novo->id,
        'vehicle_id' => $veiculoNovo->id,
        'servico' => 'Revisão geral',
    ])->assertRedirect(route('admin.service_orders.view', $os->id));

    expect((int) $os->refresh()->person_id)->toBe((int) $novo->id)
        ->and((int) $os->vehicle_id)->toBe((int) $veiculoNovo->id);
});
