<?php

use Illuminate\Validation\ValidationException;
use Webkul\ServiceOrder\Models\AlcadaPedido;
use Webkul\ServiceOrder\Models\AlcadaRegra;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Models\ServiceOrderItem;
use Webkul\ServiceOrder\Models\WorkshopAlert;
use Webkul\ServiceOrder\Services\Alcada\AlcadaService;
use Webkul\ServiceOrder\Services\Indicators\IndicatorService;

const NEGOCIACAO_PERMISSOES = ['service_orders', 'service_orders.view', 'service_orders.edit', 'ver_valores_financeiros', 'alcadas'];

function alcadasUsers(): array
{
    return [
        'consultor' => test()->makeUser('consultor', NEGOCIACAO_PERMISSOES),
        'gerente' => test()->makeUser('gerente', NEGOCIACAO_PERMISSOES),
        'dono' => test()->makeUser('proprietario', NEGOCIACAO_PERMISSOES),
    ];
}

function osComItem(float $bruto = 1000.0, array $override = []): ServiceOrder
{
    $os = test()->makeOs(array_merge(['workshop_status' => ServiceOrder::STAGE_NEGOCIACAO], $override));

    $item = new ServiceOrderItem;
    $item->forceFill(['quote_id' => $os->id, 'product_id' => 0, 'tipo' => 'Serviço', 'name' => 'Revisão geral', 'price' => $bruto, 'quantity' => 1, 'hours' => 1, 'total' => $bruto])->save();

    return $os->refresh();
}

function configurarDesconto(?float $sozinho, ?float $gerente): void
{
    AlcadaRegra::ensureDefaults();
    AlcadaRegra::whereKey('desconto')->update(['limite_sozinho' => $sozinho, 'limite_gerente' => $gerente]);
}

it('desconto: dentro da alçada do consultor passa; acima vira pedido ao gerente e bloqueia', function () {
    $this->enablePilot();
    ['consultor' => $consultor] = alcadasUsers();
    configurarDesconto(5, 10);
    $os = osComItem(1000);
    $alcadas = app(AlcadaService::class);

    $alcadas->authorize('desconto', $os, 4.5, 'Desconto de 4,5%', $consultor);
    expect(AlcadaPedido::count())->toBe(0);

    expect(fn () => $alcadas->authorize('desconto', $os, 8, 'Desconto de 8%', $consultor))->toThrow(ValidationException::class);

    $pedido = AlcadaPedido::firstOrFail();

    expect($pedido->tema)->toBe('desconto')
        ->and($pedido->status)->toBe('pendente')
        ->and($pedido->nivel_necessario)->toBe('gerente')
        ->and($pedido->solicitante_id)->toBe($consultor->id)
        ->and($pedido->service_order_id)->toBe($os->id)
        ->and(ServiceOrderEvent::where('service_order_id', $os->id)->where('tipo_evento', 'alcada_pedida')->exists())->toBeTrue();

    // Enquanto o pedido está aberto, repetir não cria outro.
    expect(fn () => $alcadas->authorize('desconto', $os, 8, 'Desconto de 8%', $consultor))->toThrow(ValidationException::class)
        ->and(AlcadaPedido::count())->toBe(1);
});

it('o gerente aprova dentro da alçada dele; a aprovação libera a ação uma única vez', function () {
    $this->enablePilot();
    ['consultor' => $consultor, 'gerente' => $gerente] = alcadasUsers();
    configurarDesconto(5, 10);
    $os = osComItem(1000);
    $alcadas = app(AlcadaService::class);

    expect(fn () => $alcadas->authorize('desconto', $os, 8, 'Desconto de 8%', $consultor))->toThrow(ValidationException::class);

    $pedido = AlcadaPedido::firstOrFail();
    $alcadas->decide($pedido, $gerente, true, 'Cliente antigo');

    expect($pedido->refresh()->status)->toBe('aprovado')
        ->and($pedido->decidido_por)->toBe($gerente->id);

    // O consultor repete a ação: passa e consome a aprovação.
    $alcadas->authorize('desconto', $os, 8, 'Desconto de 8%', $consultor);

    expect($pedido->refresh()->status)->toBe('consumido');

    // A segunda tentativa já não tem aprovação para usar: novo pedido.
    expect(fn () => $alcadas->authorize('desconto', $os, 8, 'Desconto de 8%', $consultor))->toThrow(ValidationException::class)
        ->and(AlcadaPedido::count())->toBe(2);

    // Aprovação não cobre um valor maior que o aprovado.
    $outro = osComItem(1000);
    expect(fn () => $alcadas->authorize('desconto', $outro, 6, 'x', $consultor))->toThrow(ValidationException::class);
    $alcadas->decide(AlcadaPedido::where('service_order_id', $outro->id)->first(), $gerente, true);
    expect(fn () => $alcadas->authorize('desconto', $outro, 9, 'x', $consultor))->toThrow(ValidationException::class);
});

it('acima da alçada do gerente o pedido só se resolve com o dono, e o gerente encaminha', function () {
    $this->enablePilot();
    ['consultor' => $consultor, 'gerente' => $gerente, 'dono' => $dono] = alcadasUsers();
    configurarDesconto(5, 10);
    $os = osComItem(1000);
    $alcadas = app(AlcadaService::class);

    expect(fn () => $alcadas->authorize('desconto', $os, 15, 'Desconto de 15%', $consultor))->toThrow(ValidationException::class);

    $pedido = AlcadaPedido::firstOrFail();

    expect($pedido->nivel_necessario)->toBe('proprietario')
        ->and($pedido->status)->toBe('pendente')
        // O dono só enxerga o que o gerente encaminhou.
        ->and($alcadas->queueFor($dono))->toHaveCount(0)
        ->and($alcadas->queueFor($gerente))->toHaveCount(1);

    // O gerente não decide o que é do dono...
    expect(fn () => $alcadas->decide($pedido, $gerente, true))->toThrow(ValidationException::class);

    // ...ele encaminha. O consultor não encaminha.
    expect(fn () => $alcadas->forward($pedido, $consultor))->toThrow(ValidationException::class);

    $alcadas->forward($pedido, $gerente, 'Cliente estratégico');
    $pedido->refresh();

    expect($pedido->status)->toBe('encaminhado')
        ->and($pedido->encaminhado_em->diffInHours($pedido->prazo_dono_em))->toBe(24.0)
        ->and($alcadas->queueFor($dono))->toHaveCount(1);

    // A recusa exige motivo.
    expect(fn () => $alcadas->decide($pedido, $dono, false, ' '))->toThrow(ValidationException::class);

    $alcadas->decide($pedido, $dono, true, 'Pode');

    expect($pedido->refresh()->status)->toBe('aprovado')
        ->and($pedido->sem_gerente)->toBeFalse();
});

it('pedido que o gerente faz acima da alçada dele já nasce na fila do dono', function () {
    $this->enablePilot();
    ['gerente' => $gerente, 'dono' => $dono] = alcadasUsers();
    configurarDesconto(5, 10);
    $os = osComItem(1000);
    $alcadas = app(AlcadaService::class);

    expect(fn () => $alcadas->authorize('desconto', $os, 12, 'Desconto de 12%', $gerente))->toThrow(ValidationException::class);

    expect(AlcadaPedido::first()->status)->toBe('encaminhado')
        ->and($alcadas->queueFor($dono))->toHaveCount(1);

    // O gerente pode até 10% sozinho (alçada dele): nada é pedido.
    $alcadas->authorize('desconto', osComItem(1000), 9, 'Desconto de 9%', $gerente);
    expect(AlcadaPedido::count())->toBe(1);
});

it('dono decidindo um pedido que o gerente não encaminhou conta como acionado fora da alçada (DON-05)', function () {
    $this->enablePilot();
    ['consultor' => $consultor, 'dono' => $dono] = alcadasUsers();
    configurarDesconto(5, 10);
    $os = osComItem(1000);
    $alcadas = app(AlcadaService::class);

    expect(fn () => $alcadas->authorize('desconto', $os, 8, 'x', $consultor))->toThrow(ValidationException::class);

    $this->at('2026-11-18 10:00:00');
    $alcadas->decide(AlcadaPedido::firstOrFail(), $dono, true);

    $pedido = AlcadaPedido::first();

    expect($pedido->sem_gerente)->toBeTrue()
        ->and(ServiceOrderEvent::where('service_order_id', $os->id)->where('tipo_evento', 'acao_dono_fora_fluxo')->count())->toBe(1)
        ->and(app(IndicatorService::class)->evaluate('DON-05', '2026-11')['resultado']->realizado)->toBe(1.0);
});

it('temas só do gerente ou só do dono: pedido manual, sem limite', function () {
    $this->enablePilot();
    ['consultor' => $consultor, 'gerente' => $gerente, 'dono' => $dono] = alcadasUsers();
    $alcadas = app(AlcadaService::class);

    $horas = $alcadas->request('horas_extras', 'Hora extra do técnico no sábado', null, null, $consultor);
    $contratacao = $alcadas->request('contratacao_desligamento', 'Contratar mais um técnico', null, null, $gerente);

    expect($horas->nivel_necessario)->toBe('gerente')
        ->and($horas->status)->toBe('pendente')
        ->and($contratacao->nivel_necessario)->toBe('proprietario')
        // O gerente propõe e o pedido vai direto ao dono.
        ->and($contratacao->status)->toBe('encaminhado');

    $alcadas->decide($horas, $gerente, true, 'Autorizado');
    expect($horas->refresh()->status)->toBe('aprovado');
});

it('condição de pagamento: a prazo para cliente amarelo vai ao gerente e para vermelho ao dono', function () {
    $this->enablePilot();
    ['consultor' => $consultor] = alcadasUsers();
    $alcadas = app(AlcadaService::class);

    $amarelo = osComItem(1000);
    $vermelho = osComItem(1000);

    expect(fn () => $alcadas->authorize('condicao_pagamento', $amarelo, 1, 'Boleto 30 dias', $consultor))->toThrow(ValidationException::class)
        ->and(fn () => $alcadas->authorize('condicao_pagamento', $vermelho, 2, 'Boleto 30 dias', $consultor))->toThrow(ValidationException::class);

    expect(AlcadaPedido::where('service_order_id', $amarelo->id)->first()->nivel_necessario)->toBe('gerente')
        ->and(AlcadaPedido::where('service_order_id', $vermelho->id)->first()->nivel_necessario)->toBe('proprietario');

    // Cliente verde: nada a pedir.
    $alcadas->authorize('condicao_pagamento', osComItem(1000), 0, 'Boleto 30 dias', $consultor);
    expect(AlcadaPedido::count())->toBe(2);
});

it('alçada sem limite preenchido não bloqueia; tema desativado também não', function () {
    $this->enablePilot();
    ['consultor' => $consultor] = alcadasUsers();
    $alcadas = app(AlcadaService::class);
    $os = osComItem(1000);

    expect($alcadas->required('desconto', 50))->toBe(['nivel' => 0, 'configurada' => false]);
    $alcadas->authorize('desconto', $os, 50, 'x', $consultor);

    configurarDesconto(5, 10);
    AlcadaRegra::whereKey('desconto')->update(['ativo' => false]);
    $alcadas->authorize('desconto', $os, 50, 'x', $consultor);

    expect(AlcadaPedido::count())->toBe(0);
});

it('piloto desligado: as alçadas não bloqueiam nada', function () {
    ['consultor' => $consultor] = alcadasUsers();
    configurarDesconto(5, 10);

    app(AlcadaService::class)->authorize('desconto', osComItem(1000), 40, 'x', $consultor);

    expect(AlcadaPedido::count())->toBe(0);
});

it('avisos de alçada: gerente recebe o pedido, o dono só o que exige o dono, e o pedido decidido volta a quem pediu', function () {
    $this->enablePilot();
    ['consultor' => $consultor, 'gerente' => $gerente, 'dono' => $dono] = alcadasUsers();
    configurarDesconto(5, 10);
    $os = osComItem(1000);
    $alcadas = app(AlcadaService::class);

    expect(fn () => $alcadas->authorize('desconto', $os, 8, 'x', $consultor))->toThrow(ValidationException::class);

    expect(WorkshopAlert::where('user_id', $gerente->id)->where('barrier_code', 'alcada_pedido')->count())->toBe(1)
        ->and(WorkshopAlert::where('user_id', $dono->id)->count())->toBe(0);

    $os2 = osComItem(1000);
    expect(fn () => $alcadas->authorize('desconto', $os2, 15, 'x', $consultor))->toThrow(ValidationException::class);
    $alcadas->forward(AlcadaPedido::where('service_order_id', $os2->id)->first(), $gerente);

    $paraDono = WorkshopAlert::where('user_id', $dono->id)->get();

    expect($paraDono)->toHaveCount(1)
        ->and($paraDono->first()->critico)->toBeTrue()
        // Nada de valores nem de crédito nos avisos.
        ->and($paraDono->first()->mensagem)->not->toContain('R$')->not->toContain('%')
        ->and(WorkshopAlert::where('barrier_code', 'alcada_pedido')->pluck('mensagem')->implode(' '))->not->toContain('%')->not->toContain('R$');

    $alcadas->decide(AlcadaPedido::where('service_order_id', $os->id)->first(), $gerente, true);

    expect(WorkshopAlert::where('user_id', $consultor->id)->where('barrier_code', 'alcada_decisao')->count())->toBe(1);
});

it('um pedido decidido não é apagado nem muda de decisão', function () {
    $this->enablePilot();
    ['consultor' => $consultor, 'gerente' => $gerente] = alcadasUsers();
    configurarDesconto(5, 10);
    $alcadas = app(AlcadaService::class);
    $os = osComItem(1000);

    expect(fn () => $alcadas->authorize('desconto', $os, 8, 'x', $consultor))->toThrow(ValidationException::class);

    $pedido = AlcadaPedido::firstOrFail();
    $alcadas->decide($pedido, $gerente, false, 'Margem apertada');

    expect(fn () => $pedido->refresh()->update(['status' => 'aprovado']))->toThrow(LogicException::class)
        ->and(fn () => $pedido->delete())->toThrow(LogicException::class)
        ->and(fn () => $alcadas->decide($pedido, $gerente, true))->toThrow(ValidationException::class);
});

// ---- Pelas telas -------------------------------------------------------------

it('negociação: salvar desconto acima da alçada não grava nada e abre o pedido; depois de aprovado, grava', function () {
    $this->enablePilot();
    ['consultor' => $consultor, 'gerente' => $gerente] = alcadasUsers();
    configurarDesconto(5, 10);
    $os = osComItem(1000, ['user_id' => $consultor->id]);
    $item = $os->items()->first();

    $this->actingAs($consultor, 'user')
        ->put(route('admin.service_orders.negociacao.update', $os->id), ['item_discounts' => [$item->id => 80], 'condicao_pagamento' => 'PIX à vista'])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect((float) $item->refresh()->discount_amount)->toBe(0.0)
        ->and(AlcadaPedido::count())->toBe(1);

    app(AlcadaService::class)->decide(AlcadaPedido::first(), $gerente, true, 'Ok');

    $this->actingAs($consultor, 'user')
        ->put(route('admin.service_orders.negociacao.update', $os->id), ['item_discounts' => [$item->id => 80], 'condicao_pagamento' => 'PIX à vista'])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect((float) $item->refresh()->discount_amount)->toBe(80.0)
        ->and(AlcadaPedido::first()->status)->toBe('consumido');

    // Desconto dentro da alçada passa direto.
    $outra = osComItem(1000, ['user_id' => $consultor->id]);
    $this->actingAs($consultor, 'user')
        ->put(route('admin.service_orders.negociacao.update', $outra->id), ['item_discounts' => [$outra->items()->first()->id => 40]])
        ->assertSessionHas('success');
});

it('compra de peça sem autorização do cliente passa pela alçada de compra', function () {
    $this->enablePilot();
    ['consultor' => $pecas] = alcadasUsers();
    AlcadaRegra::ensureDefaults();
    AlcadaRegra::whereKey('compra_pecas')->update(['limite_sozinho' => 500, 'limite_gerente' => 2000]);

    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_NEGOCIACAO]);
    $peca = new ServiceOrderItem;
    $peca->forceFill(['quote_id' => $os->id, 'product_id' => 0, 'tipo' => 'Peça', 'name' => 'Turbina', 'price' => 5000, 'quantity' => 1, 'custo_unitario' => 3500, 'total' => 5000])->save();

    $this->actingAs($pecas, 'user')
        ->post(route('admin.service_orders.workflow.action', [$os->id, 'comprar_peca']), ['item_id' => $peca->id])
        ->assertSessionHas('error');

    expect($peca->refresh()->comprada_em)->toBeNull()
        ->and(AlcadaPedido::first()->nivel_necessario)->toBe('proprietario');

    // Com o cliente já tendo autorizado o serviço, Peças compra sozinha.
    $os->forceFill(['aprovacao_em' => now(), 'aprovacao_tipo' => 'total'])->save();

    $this->post(route('admin.service_orders.workflow.action', [$os->id, 'comprar_peca']), ['item_id' => $peca->id])->assertSessionHas('success');

    expect($peca->refresh()->comprada_em)->not->toBeNull();
});

it('dono blindado: ação do dono dentro do fluxo da OS gera o registro; consultar e decidir alçada não', function () {
    $this->enablePilot();
    $dono = $this->makeUser('proprietario', NEGOCIACAO_PERMISSOES);
    $os = osComItem(1000, ['workshop_status' => ServiceOrder::STAGE_EM_EXECUCAO]);

    $this->actingAs($dono, 'user')->get(route('admin.service_orders.view', $os->id))->assertOk();

    expect(ServiceOrderEvent::where('service_order_id', $os->id)->where('tipo_evento', 'acao_dono_fora_fluxo')->count())->toBe(0);

    $this->post(route('admin.service_orders.workflow.action', [$os->id, 'atualizar_cliente']), ['canal' => 'WhatsApp'])->assertRedirect();
    $this->post(route('admin.service_orders.workflow.action', [$os->id, 'atualizar_cliente']), ['canal' => 'Canal inválido'])->assertSessionHasErrors('canal');

    $registros = ServiceOrderEvent::where('service_order_id', $os->id)->where('tipo_evento', 'acao_dono_fora_fluxo')->get();

    // Só a ação que de fato aconteceu foi registrada (a de validação falha não).
    expect($registros)->toHaveCount(1)
        ->and($registros->first()->dados_json['rota'])->toBe('admin.service_orders.workflow.action')
        ->and($registros->first()->usuario_id)->toBe($dono->id);

    // O mesmo gesto feito pelo gerente não gera nada.
    $gerente = $this->makeUser('gerente', NEGOCIACAO_PERMISSOES);
    $this->actingAs($gerente, 'user')->post(route('admin.service_orders.workflow.action', [$os->id, 'atualizar_cliente']), ['canal' => 'WhatsApp']);

    expect(ServiceOrderEvent::where('service_order_id', $os->id)->where('tipo_evento', 'acao_dono_fora_fluxo')->count())->toBe(1);
});

it('fila de alçadas pelas telas: o gerente aprova, o dono vê só o encaminhado e decide pelo celular em JSON', function () {
    $this->enablePilot();
    ['consultor' => $consultor, 'gerente' => $gerente, 'dono' => $dono] = alcadasUsers();
    configurarDesconto(5, 10);
    $alcadas = app(AlcadaService::class);

    $os = osComItem(1000);
    expect(fn () => $alcadas->authorize('desconto', $os, 8, 'x', $consultor))->toThrow(ValidationException::class);
    $os2 = osComItem(1000);
    expect(fn () => $alcadas->authorize('desconto', $os2, 20, 'x', $consultor))->toThrow(ValidationException::class);

    $pedidoGerente = AlcadaPedido::where('service_order_id', $os->id)->first();
    $pedidoDono = AlcadaPedido::where('service_order_id', $os2->id)->first();

    $this->actingAs($gerente, 'user')->get(route('admin.mentoria.alcadas'))->assertOk()->assertSee('Fila de alçadas')->assertSee('Desconto em orçamento');

    // O gerente não decide o que é do dono, mas encaminha.
    $this->post(route('admin.mentoria.alcadas.decidir', $pedidoDono->id), ['decisao' => 'aprovar'])->assertSessionHas('error');
    $this->post(route('admin.mentoria.alcadas.decidir', $pedidoDono->id), ['decisao' => 'encaminhar'])->assertSessionHas('success');
    $this->post(route('admin.mentoria.alcadas.decidir', $pedidoGerente->id), ['decisao' => 'recusar'])->assertSessionHas('error');
    $this->post(route('admin.mentoria.alcadas.decidir', $pedidoGerente->id), ['decisao' => 'recusar', 'comentario' => 'Sem margem'])->assertSessionHas('success');

    expect($pedidoGerente->refresh()->status)->toBe('recusado');

    $fila = $this->actingAs($dono, 'user')->getJson(route('admin.mentoria.alcadas.dados'))->assertOk();

    expect($fila->json('pedidos'))->toHaveCount(1)
        ->and($fila->json('pedidos.0.tema'))->toBe('Desconto em orçamento')
        ->and($fila->json('pedidos.0.valor_fmt'))->toBe('20,0%')
        ->and($fila->json('nivel'))->toBe(2);

    $this->postJson(route('admin.mentoria.alcadas.decidir', $pedidoDono->id), ['decisao' => 'aprovar', 'comentario' => 'Ok'])->assertOk()->assertJson(['ok' => true]);

    expect($pedidoDono->refresh()->status)->toBe('aprovado')
        ->and($pedidoDono->sem_gerente)->toBeFalse();

    // DON-06: decidiu dentro das 24 h.
    expect(app(IndicatorService::class)->evaluate('DON-06', now()->format('Y-m'))['resultado']->realizado)->toBe(100.0);
});

it('matriz de alçadas: salvar os limites muda o que é bloqueado', function () {
    $this->enablePilot();
    $gerente = $this->makeUser('gerente', array_merge(NEGOCIACAO_PERMISSOES, ['settings', 'settings.alcadas']));
    $this->actingAs($gerente, 'user');

    $this->get(route('admin.mentoria.alcadas.matriz'))->assertOk()->assertSee('Matriz de alçadas')->assertSee('Falta preencher');

    $this->put(route('admin.mentoria.alcadas.matriz.update'), [
        'sozinho' => ['desconto' => '5', 'compra_pecas' => 'R$ 1.500,00'],
        'gerente' => ['desconto' => '10,5'],
        'ativo' => ['desconto' => '1', 'compra_pecas' => '1'],
    ])->assertRedirect(route('admin.mentoria.alcadas.matriz'));

    expect(AlcadaRegra::find('desconto')->limite_sozinho)->toBe(5.0)
        ->and(AlcadaRegra::find('desconto')->limite_gerente)->toBe(10.5)
        ->and(AlcadaRegra::find('compra_pecas')->limite_sozinho)->toBe(1500.0)
        ->and(AlcadaRegra::find('compra_pecas')->limite_gerente)->toBeNull()
        // Tema que não veio marcado como ativo é desativado.
        ->and(AlcadaRegra::find('despesas')->ativo)->toBeFalse();
});
