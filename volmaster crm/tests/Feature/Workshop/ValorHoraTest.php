<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Webkul\PartInventory\Models\Service;
use Webkul\ServiceOrder\Models\AlcadaPedido;
use Webkul\ServiceOrder\Models\ServiceOrderItem;
use Webkul\ServiceOrder\Services\Financeiro\DreService;
use Webkul\ServiceOrder\Services\OperationService;
use Webkul\ServiceOrder\Services\WorkshopSettings;

/**
 * B5 do roteiro do patrão: valor da hora vem da Ficha, horas vêm do catálogo (tempo padrão),
 * R$ 10.000/h mostra aviso e abaixo do preço mínimo da hora exige alçada.
 */
function horaPrecoMinimoDaDre(?float $minimo): void
{
    test()->partialMock(DreService::class, fn ($mock) => $mock->shouldReceive('precoMinimoHora')->andReturn(['preco_minimo' => $minimo]));
}

function horaNovoServico(array $extra = []): Service
{
    return Service::create(array_merge(['nome' => 'Troca de embreagem', 'preco_padrao' => 400, 'tempo_padrao_horas' => 2.5], $extra));
}

function horaLancar($teste, int $osId, array $dados): TestResponse
{
    return $teste->post(route('admin.service_orders.items.store', $osId), array_merge(['tipo' => 'Serviço', 'descricao' => 'Troca de embreagem'], $dados));
}

it('B5: o serviço do catálogo entra com o valor da hora da Ficha e as horas do tempo padrão', function () {
    $this->enablePilot(['valor_hora_mao_obra' => 150]);
    horaPrecoMinimoDaDre(null);
    $servico = horaNovoServico();
    $os = $this->makeOs();

    $this->actingAs($this->makeUser('gerente'), 'user');

    horaLancar($this, $os->id, ['service_id' => $servico->id])->assertRedirect(route('admin.service_orders.view', $os->id))->assertSessionHasNoErrors();

    $item = ServiceOrderItem::where('quote_id', $os->id)->first();

    expect((float) $item->price)->toBe(150.0)
        ->and((float) $item->hours)->toBe(2.5)
        // O tempo padrão do cronômetro do técnico é o das horas do item.
        ->and(app(OperationService::class)->padraoSeconds($item))->toBe(9000);

    // Valor ou horas digitados por quem lança não são trocados.
    horaLancar($this, $os->id, ['service_id' => $servico->id, 'preco_unitario' => 200, 'hours' => 1])->assertSessionHasNoErrors();

    $digitado = ServiceOrderItem::where('quote_id', $os->id)->latest('id')->first();

    expect((float) $digitado->price)->toBe(200.0)->and((float) $digitado->hours)->toBe(1.0);
});

it('B5 (teste do PDF): R$ 10.000 por hora mostra aviso, mas lança; valor normal não avisa', function () {
    $this->enablePilot(['valor_hora_mao_obra' => 150]);
    horaPrecoMinimoDaDre(null);
    $os = $this->makeOs();

    $this->actingAs($this->makeUser('gerente'), 'user');

    horaLancar($this, $os->id, ['preco_unitario' => 10000, 'hours' => 6])->assertSessionHas('warning');

    expect(session('warning'))->toContain('3 vezes o valor da Ficha')
        ->and(ServiceOrderItem::where('quote_id', $os->id)->count())->toBe(1);

    // Exatamente 3 vezes não avisa; só acima.
    horaLancar($this, $os->id, ['preco_unitario' => 450, 'hours' => 1])->assertSessionMissing('warning');
});

it('B5: o limite de "vezes a Ficha" vem da configuração da oficina', function () {
    $this->enablePilot(['valor_hora_mao_obra' => 100, 'valor_hora_limite_multiplo' => 2]);
    horaPrecoMinimoDaDre(null);
    $os = $this->makeOs();

    $this->actingAs($this->makeUser('gerente'), 'user');

    horaLancar($this, $os->id, ['preco_unitario' => 250, 'hours' => 1])->assertSessionHas('warning');
    horaLancar($this, $os->id, ['preco_unitario' => 200, 'hours' => 1])->assertSessionMissing('warning');
});

it('B5: abaixo do preço mínimo da hora o consultor abre pedido de alçada e o serviço NÃO entra; o gerente lança direto', function () {
    $this->enablePilot(['valor_hora_mao_obra' => 150]);
    horaPrecoMinimoDaDre(120.0); // piso = o menor entre o mínimo da DRE (120) e a Ficha (150)
    $os = $this->makeOs();
    $consultor = $this->makeUser('consultor', ['service_orders', 'ver_valores_financeiros']);

    $this->actingAs($consultor, 'user');

    horaLancar($this, $os->id, ['preco_unitario' => 100, 'hours' => 1])->assertSessionHasErrors('alcada');

    $pedido = AlcadaPedido::where('tema', 'hora_abaixo_minimo')->first();

    expect($pedido)->not->toBeNull()
        ->and($pedido->service_order_id)->toBe($os->id)
        ->and(ServiceOrderItem::where('quote_id', $os->id)->count())->toBe(0);

    // O valor da Ficha (ou mais) nunca pede alçada, mesmo com o mínimo da DRE acima dele.
    horaPrecoMinimoDaDre(200.0);
    horaLancar($this, $os->id, ['preco_unitario' => 150, 'hours' => 1])->assertSessionHasNoErrors();

    expect(ServiceOrderItem::where('quote_id', $os->id)->count())->toBe(1);

    // O gerente tem alçada para isso.
    $this->actingAs($this->makeUser('gerente'), 'user');
    horaLancar($this, $os->id, ['preco_unitario' => 100, 'hours' => 1])->assertSessionHasNoErrors();

    expect(ServiceOrderItem::where('quote_id', $os->id)->count())->toBe(2);
});

it('B5: com o piloto desligado a tela de itens funciona como sempre (sem Ficha, sem aviso, sem alçada)', function () {
    WorkshopSettings::set('valor_hora_mao_obra', 150);
    horaPrecoMinimoDaDre(120.0);
    $os = $this->makeOs();

    $this->actingAs($this->makeUser('consultor', ['service_orders', 'ver_valores_financeiros']), 'user');

    // Valor digitado, mesmo absurdo ou abaixo do mínimo: entra como sempre.
    horaLancar($this, $os->id, ['preco_unitario' => 10000, 'hours' => 6])->assertSessionMissing('warning')->assertSessionHasNoErrors();
    horaLancar($this, $os->id, ['preco_unitario' => 50, 'hours' => 1])->assertSessionHasNoErrors();

    expect(ServiceOrderItem::where('quote_id', $os->id)->count())->toBe(2)
        ->and(AlcadaPedido::count())->toBe(0);

    // Sem valor, o serviço não entra (a Ficha só preenche com o piloto ligado).
    horaLancar($this, $os->id, [])->assertSessionHasErrors('preco_unitario');
});

it('B5: o Técnico não lança item com preço e não recebe a Ficha, o aviso nem o catálogo de valores', function () {
    $this->enablePilot(['valor_hora_mao_obra' => 150]);
    $os = $this->makeOs();

    $this->actingAs($this->makeUser('tecnico', ['tecnico']), 'user');

    horaLancar($this, $os->id, ['preco_unitario' => 100])->assertForbidden();

    expect(ServiceOrderItem::where('quote_id', $os->id)->count())->toBe(0);
});

it('B5: o catálogo de serviços ganha o campo "Tempo padrão (horas)" e a tela da OS leva o valor e o limite da Ficha', function () {
    $this->enablePilot(['valor_hora_mao_obra' => 150]);
    $servico = horaNovoServico();
    $os = $this->makeOs();

    $this->actingAs($this->makeUser('gerente'), 'user');

    $this->get(route('admin.services.create'))->assertOk()->assertSee('Tempo padrão (horas)');
    $this->get(route('admin.services.edit', $servico->id))->assertOk()->assertSee('Tempo padrão (horas)')->assertSee('2.50');

    $this->get(route('admin.service_orders.view', $os->id))
        ->assertOk()
        ->assertSee('data-ficha="150"', false)
        ->assertSee('data-limite="450"', false)
        ->assertSee('data-horas="2.50"', false);

    expect(DB::table('services')->where('id', $servico->id)->value('tempo_padrao_horas'))->toEqual(2.5);
});
