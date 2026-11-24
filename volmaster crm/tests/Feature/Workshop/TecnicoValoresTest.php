<?php

use Illuminate\Support\Facades\Log;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Services\ServiceOrderItemService;
use Webkul\ServiceOrder\Services\ValoresFinanceiros;

const PRECO_SECRETO = 7777.77;
const TOTAL_SECRETO = 9999.99;

/**
 * Oficina com piloto ligado, uma OS em execução cheia de valores, um usuário
 * Técnico (função personalizada, sem a permissão de valores) e um Gerente.
 */
function valuesScenario(): array
{
    test()->enablePilot();

    $mechanic = test()->makeMechanic('Zé Mecânico');

    $tecnico = test()->makeUser('tecnico', ['service_orders', 'service_orders.view', 'service_orders.edit', 'tecnico'], [
        'mechanic_id' => $mechanic->id,
        'view_permission' => 'individual',
    ]);

    $gerente = test()->makeUser('gerente', [
        'service_orders', 'service_orders.view', 'service_orders.edit', 'ver_valores_financeiros', 'credito_cliente', 'comissoes', 'marketing',
    ]);

    $os = test()->makeOs(['mechanic_id' => $mechanic->id, 'workshop_status' => ServiceOrder::STAGE_EM_EXECUCAO, 'user_id' => $gerente->id]);

    app(ServiceOrderItemService::class)->addItem($os, [
        'tipo' => 'Serviço', 'descricao' => 'Troca de embreagem', 'preco_unitario' => PRECO_SECRETO, 'hours' => 2, 'funcionario_id' => $mechanic->id,
    ]);

    $os->refresh()->forceFill([
        'grand_total' => TOTAL_SECRETO, 'cor_credito' => 'amarelo', 'condicao_pagamento' => 'Boleto 30 dias', 'negociacao_observacoes' => 'Cliente pediu abatimento',
    ])->save();

    return compact('mechanic', 'tecnico', 'gerente', 'os');
}

function noMoney(string $content): void
{
    foreach (['7.777,77', '7777.77', '7777,77', '9.999,99', '9999.99', '9999,99', 'Boleto 30 dias', 'Cliente pediu abatimento'] as $segredo) {
        expect($content)->not->toContain($segredo);
    }
}

it('critério 6: o Técnico abre a OS e não vê preço, desconto, total, negociação, cobrança nem crédito', function () {
    ['tecnico' => $tecnico, 'os' => $os] = valuesScenario();

    $html = $this->actingAs($tecnico, 'user')
        ->get(route('admin.service_orders.view', $os->id))
        ->assertOk()
        ->getContent();

    noMoney($html);

    expect($html)->toContain('Troca de embreagem')
        ->not->toContain('Valor unitário')
        ->not->toContain('Descontos')
        ->not->toContain('Boleto')
        ->not->toContain('Cor de crédito')
        ->not->toContain('Negociação salva')
        ->not->toContain('orcamento/whatsapp')
        ->not->toContain('boletos');
});

it('critério 6: o gerente, com a permissão, continua vendo os valores', function () {
    ['gerente' => $gerente, 'os' => $os] = valuesScenario();

    $html = $this->actingAs($gerente, 'user')
        ->get(route('admin.service_orders.view', $os->id))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('7.777,77')->toContain('Cor de crédito');
});

it('critério 6: a impressão da OS do Técnico sai sem valores, e a do gerente com valores', function () {
    ['tecnico' => $tecnico, 'gerente' => $gerente, 'os' => $os] = valuesScenario();
    $os->load(['person', 'vehicle', 'mechanic', 'items.part', 'user']);

    $this->actingAs($tecnico, 'user');
    $paraTecnico = view('service_order::print', ['serviceOrder' => $os])->render();

    noMoney($paraTecnico);
    expect($paraTecnico)->toContain('Troca de embreagem')->not->toContain('Valor unit.')->not->toContain('Condição de pagamento');

    $this->actingAs($gerente, 'user');
    $paraGerente = view('service_order::print', ['serviceOrder' => $os])->render();

    expect($paraGerente)->toContain('7.777,77')->toContain('Valor unit.');

    // A rota de impressão em si funciona para o Técnico e entrega um PDF.
    $resposta = $this->actingAs($tecnico, 'user')->get(route('admin.service_orders.print', $os->id));
    $resposta->assertOk();
    expect($resposta->headers->get('Content-Type'))->toContain('pdf');
});

it('critério 6: a API (listagem e Minhas tarefas) não devolve valor, desconto, crédito nem comissão', function () {
    ['tecnico' => $tecnico, 'mechanic' => $mechanic, 'os' => $os] = valuesScenario();

    $lista = $this->actingAs($tecnico, 'user')
        ->getJson(route('admin.service_orders.index'), ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertOk()
        ->getContent();

    noMoney($lista);
    expect($lista)->not->toContain('grand_total');

    $tarefas = $this->actingAs($tecnico, 'user')->getJson(route('admin.tecnico.tasks'))->assertOk();
    $json = $tarefas->getContent();

    noMoney($json);

    foreach (['preco', 'price', 'valor_unitario', 'total', 'desconto', 'discount', 'credito', 'cor_credito', 'comissao', 'grand'] as $campo) {
        expect(strtolower($json))->not->toContain('"'.$campo);
    }

    // E a tarefa do técnico está lá, com o tempo padrão.
    expect($tarefas->json('vinculado'))->toBeTrue()
        ->and($tarefas->json('operacoes.0.servico'))->toBe('Troca de embreagem')
        ->and($tarefas->json('operacoes.0.padrao_seg'))->toBe(7200);
});

it('critério 6: o Técnico leva 403 nas rotas de valores, mesmo chamando direto por URL', function () {
    ['tecnico' => $tecnico, 'os' => $os] = valuesScenario();
    $this->actingAs($tecnico, 'user');

    $this->put(route('admin.service_orders.negociacao.update', $os->id), ['condicao_pagamento' => 'x'])->assertForbidden();
    $this->post(route('admin.service_orders.orcamento.whatsapp', $os->id))->assertForbidden();
    $this->post(route('admin.service_orders.orcamento.mail', $os->id))->assertForbidden();
    $this->post(route('admin.service_orders.boletos.store', $os->id), ['valor' => 10])->assertForbidden();
    $this->post(route('admin.service_orders.boletos.asaas', $os->id), ['valor' => 10])->assertForbidden();
    $this->post(route('admin.service_orders.items.store', $os->id), ['tipo' => 'Serviço', 'descricao' => 'x', 'preco_unitario' => 1])->assertForbidden();
    $this->post(route('admin.service_orders.workflow.cor_credito', $os->id), ['cor_credito' => 'verde'])->assertForbidden();
    $this->post(route('admin.service_orders.workflow.nf', $os->id), ['numero' => '1'])->assertForbidden();
    $this->post(route('admin.service_orders.workflow.payment', $os->id))->assertForbidden();

    // Comissões e Marketing nem estão no perfil dele: o controle de permissões barra antes (403).
    $this->get(route('admin.commissions.index'))->assertForbidden();
    $this->get(route('admin.marketing.index'))->assertForbidden();
});

it('mesmo se a função do Técnico for liberada por engano em Comissões e Marketing, o servidor barra', function () {
    ['mechanic' => $mechanic] = valuesScenario();

    $descuidado = $this->makeUser('tecnico', ['service_orders', 'service_orders.view', 'comissoes', 'marketing', 'tecnico'], [
        'mechanic_id' => $mechanic->id,
        'view_permission' => 'individual',
    ]);

    $this->actingAs($descuidado, 'user');

    $this->get(route('admin.commissions.index'))->assertForbidden();
    $this->get(route('admin.commissions.export'))->assertForbidden();
    $this->get(route('admin.marketing.index'))->assertForbidden();
});

it('quem acumula Técnico com outro papel que tem a permissão passa a ver os valores', function () {
    ['mechanic' => $mechanic, 'os' => $os] = valuesScenario();

    $acumulado = $this->makeUser('tecnico', ['service_orders', 'service_orders.view', 'ver_valores_financeiros'], [
        'mechanic_id' => $mechanic->id,
        'view_permission' => 'global',
    ]);

    expect(ValoresFinanceiros::can($acumulado))->toBeTrue();
});

it('a tentativa de acesso a valores sem permissão fica registrada no log', function () {
    ['tecnico' => $tecnico, 'os' => $os] = valuesScenario();

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn ($message, $context = []) => $message === 'Acesso negado a valores financeiros' && ($context['usuario_id'] ?? null) === $tecnico->id);

    $this->actingAs($tecnico, 'user')->post(route('admin.service_orders.workflow.nf', $os->id), ['numero' => '1'])->assertForbidden();
});

it('o Técnico não mexe em tarefa de outro técnico', function () {
    ['tecnico' => $tecnico, 'os' => $os] = valuesScenario();

    $outro = $this->makeMechanic('Outro Mecânico');
    $item = $this->makeServiceItem($os, 1.0, $outro->id, 'Alinhamento');

    $this->actingAs($tecnico, 'user')
        ->postJson(route('admin.tecnico.operation', [$item->id, 'iniciar']))
        ->assertForbidden();
});

it('o Técnico inicia, pausa, retoma e conclui a operação pela tela dele, uma por vez', function () {
    ['tecnico' => $tecnico, 'mechanic' => $mechanic, 'os' => $os] = valuesScenario();
    $item = $os->items()->first();
    $segunda = $this->makeServiceItem($os, 1.0, $mechanic->id, 'Troca de óleo');

    $this->actingAs($tecnico, 'user');

    $this->at('2026-11-18 08:00:00');
    $this->postJson(route('admin.tecnico.operation', [$item->id, 'iniciar']))->assertOk()->assertJson(['ok' => true]);

    // Uma operação em andamento por vez.
    $this->postJson(route('admin.tecnico.operation', [$segunda->id, 'iniciar']))
        ->assertStatus(422)
        ->assertJson(['ok' => false]);

    $this->at('2026-11-18 08:30:00');
    $this->postJson(route('admin.tecnico.operation', [$item->id, 'pausar']), ['motivo' => 'Almoço'])->assertOk();
    $this->postJson(route('admin.tecnico.operation', [$item->id, 'pausar']), ['motivo' => ''])->assertStatus(422);

    $this->at('2026-11-18 09:30:00');
    $this->postJson(route('admin.tecnico.operation', [$item->id, 'retomar']))->assertOk();

    $this->at('2026-11-18 10:00:00');
    $this->postJson(route('admin.tecnico.operation', [$item->id, 'concluir']))->assertOk();

    $tarefas = $this->getJson(route('admin.tecnico.tasks'))->json();
    $concluida = collect($tarefas['operacoes'])->firstWhere('id', $item->id);

    // 30 min + 30 min trabalhados de 2h padrão = 50%: pausa não conta.
    expect($concluida['situacao'])->toBe('concluida')
        ->and($concluida['trabalhado_seg'])->toBe(3600)
        ->and($tarefas['indicadores']['eficiencia']['valor'])->toEqual(200)
        ->and($tarefas['indicadores']['operacoes_na_margem']['valor'])->toEqual(100);
});
