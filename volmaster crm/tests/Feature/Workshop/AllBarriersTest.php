<?php

use Illuminate\Support\Facades\DB;
use Webkul\Mechanic\Models\Mechanic;
use Webkul\ServiceOrder\Models\BarrierInstance;
use Webkul\ServiceOrder\Models\BarrierRule;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Models\ServiceOrderItem;
use Webkul\ServiceOrder\Models\WorkshopAlert;
use Webkul\ServiceOrder\Services\BarrierEngine;
use Webkul\ServiceOrder\Services\OperationService;
use Webkul\ServiceOrder\Services\StageAdvanceService;
use Webkul\ServiceOrder\Services\StageGate;
use Webkul\ServiceOrder\Services\WorkflowActions;
use Webkul\ServiceOrder\Services\WorkflowService;
use Webkul\User\Models\User;

/**
 * Barreiras do capítulo 11.2 (as 20 da mentoria). Horário comercial padrão:
 * segunda a sexta 8h-18h, sábado 8h-12h. 18/11/2026 é uma quarta-feira.
 */
function team(): array
{
    $mechanic = test()->makeMechanic('Zé Técnico');

    return [
        'consultor' => test()->makeUser('consultor'),
        'chefe' => test()->makeUser('chefe'),
        'gerente' => test()->makeUser('gerente'),
        'dono' => test()->makeUser('proprietario'),
        'financeiro' => test()->makeUser('financeiro'),
        'pecas' => test()->makeUser('pecas'),
        'tecnico' => test()->makeUser('tecnico', null, ['mechanic_id' => $mechanic->id]),
        'mechanic' => $mechanic,
    ];
}

function alertedAt(string $code, int $nivel): array
{
    return WorkshopAlert::where('barrier_code', $code)->where('nivel', $nivel)->pluck('user_id')->sort()->values()->all();
}

function ids(...$users): array
{
    return collect($users)->pluck('id')->sort()->values()->all();
}

function emit(ServiceOrder $os, string $type, string $when, array $data = []): ServiceOrderEvent
{
    return ServiceOrderEvent::record($os, $type, $data, null, null, test()->at($when));
}

function dueOf(ServiceOrder $os, string $code): ?BarrierInstance
{
    return BarrierInstance::where('service_order_id', $os->id)->where('barrier_code', $code)->latest('id')->first();
}

it('traz as 20 barreiras padrão da mentoria, com os níveis do documento', function () {
    $this->enablePilot([], 'todas');

    expect(BarrierRule::count())->toBe(20);

    $reclamacao = BarrierRule::where('codigo', 'reclamacao')->first();
    $prazo = BarrierRule::where('codigo', 'prazo_prometido')->first();

    // O dono só entra no nível 3 (regra do dono blindado).
    expect($reclamacao->destinos(1))->toBe(['gerente'])
        ->and($reclamacao->destinos(3))->toBe(['proprietario'])
        ->and($prazo->destinos(1))->toBe(['chefe', 'consultor'])
        ->and($prazo->destinos(3))->toBe(['proprietario']);

    foreach (BarrierRule::where('critico', false)->get() as $rule) {
        expect($rule->destinos(2))->not->toContain('proprietario');
    }
});

it('recepção: consultor aos 10 min, gerente aos +10 min; recepção a tempo não alerta ninguém', function () {
    $this->enablePilot([], ['recepcao']);
    $t = team();
    $engine = app(BarrierEngine::class);

    $os = $this->makeOs(['user_id' => $t['consultor']->id]);
    emit($os, 'chegada_veiculo', '2026-11-18 08:00:00');

    expect($engine->tick($this->at('2026-11-18 08:09:00')))->toBe(0);

    $engine->tick($this->at('2026-11-18 08:10:00'));
    expect(alertedAt('recepcao', 1))->toBe(ids($t['consultor']));

    $engine->tick($this->at('2026-11-18 08:20:00'));
    expect(alertedAt('recepcao', 2))->toBe(ids($t['gerente']));

    // Outra OS, recepcionada em 6 minutos: nenhum alerta.
    $rapida = $this->makeOs(['user_id' => $t['consultor']->id]);
    emit($rapida, 'chegada_veiculo', '2026-11-18 09:00:00');
    emit($rapida, 'recepcao', '2026-11-18 09:06:00');
    $engine->tick($this->at('2026-11-18 09:30:00'));

    expect(WorkshopAlert::where('service_order_id', $rapida->id)->count())->toBe(0)
        ->and(dueOf($rapida, 'recepcao')->cumprida_no_prazo)->toBeTrue();
});

it('cor de crédito: financeiro aos 30 min e gerente aos +15 min', function () {
    $this->enablePilot([], ['cor_credito']);
    $t = team();
    $engine = app(BarrierEngine::class);
    $os = $this->makeOs();

    emit($os, 'chegada_veiculo', '2026-11-18 08:00:00');
    $engine->tick($this->at('2026-11-18 08:30:00'));
    $engine->tick($this->at('2026-11-18 08:45:00'));

    expect(alertedAt('cor_credito', 1))->toBe(ids($t['financeiro']))
        ->and(alertedAt('cor_credito', 2))->toBe(ids($t['gerente']));
});

it('técnico designado: passar a ficha ao chefe abre 30 min; devolver encerra e reenviar reabre', function () {
    $this->enablePilot([], ['tecnico_designado']);
    $t = team();
    $engine = app(BarrierEngine::class);
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_CADASTRO]);

    $this->at('2026-11-18 09:00:00');
    $os->update(['workshop_status' => ServiceOrder::STAGE_ORDEM_SERVICO]);

    expect(ServiceOrderEvent::where('service_order_id', $os->id)->where('tipo_evento', 'ficha_enviada_chefe')->exists())->toBeTrue()
        ->and(dueOf($os, 'tecnico_designado')->vence_em->format('H:i'))->toBe('09:30');

    emit($os, 'ficha_devolvida', '2026-11-18 09:10:00', ['motivo' => 'Falta o km']);
    expect(dueOf($os, 'tecnico_designado')->status)->toBe('cumprida');

    emit($os, 'ficha_enviada_chefe', '2026-11-18 09:20:00', ['reenvio' => true]);
    $engine->tick($this->at('2026-11-18 09:50:00'));
    $engine->tick($this->at('2026-11-18 10:05:00'));

    expect(alertedAt('tecnico_designado', 1))->toBe(ids($t['chefe']))
        ->and(alertedAt('tecnico_designado', 2))->toBe(ids($t['gerente']));
});

it('diagnóstico no tempo: prazo do tipo de queixa, gerente aos +20% do alvo', function () {
    $this->enablePilot([], ['diagnostico_tempo']);
    $t = team();
    $engine = app(BarrierEngine::class);
    $os = $this->makeOs(['mechanic_id' => $t['mechanic']->id]);

    emit($os, 'diagnostico_inicio', '2026-11-18 08:00:00', ['tempo_alvo_min' => 60]);

    expect(dueOf($os, 'diagnostico_tempo')->vence_em->format('H:i'))->toBe('09:00')
        ->and(dueOf($os, 'diagnostico_tempo')->nivel2_em->format('H:i'))->toBe('09:12');

    $engine->tick($this->at('2026-11-18 09:00:00'));
    $engine->tick($this->at('2026-11-18 09:12:00'));

    expect(alertedAt('diagnostico_tempo', 1))->toBe(ids($t['chefe'], $t['tecnico']))
        ->and(alertedAt('diagnostico_tempo', 2))->toBe(ids($t['gerente']));

    // Queixa sem tempo alvo: não há barreira.
    $semAlvo = $this->makeOs();
    emit($semAlvo, 'diagnostico_inicio', '2026-11-18 10:00:00', ['tempo_alvo_min' => null]);
    expect(dueOf($semAlvo, 'diagnostico_tempo'))->toBeNull();
});

it('relação de peças em 30 min e OS completa ao consultor em 1 h, as duas a partir do fim do diagnóstico', function () {
    $this->enablePilot([], ['relacao_pecas', 'os_completa_consultor']);
    $t = team();
    $engine = app(BarrierEngine::class);
    $os = $this->makeOs(['mechanic_id' => $t['mechanic']->id]);

    emit($os, 'diagnostico_fim', '2026-11-18 09:00:00');

    $engine->tick($this->at('2026-11-18 09:30:00'));
    expect(alertedAt('relacao_pecas', 1))->toBe(ids($t['chefe'], $t['tecnico']))
        ->and(WorkshopAlert::where('barrier_code', 'os_completa_consultor')->count())->toBe(0);

    $engine->tick($this->at('2026-11-18 10:00:00'));
    expect(alertedAt('relacao_pecas', 2))->toBe(ids($t['gerente']))
        ->and(alertedAt('os_completa_consultor', 1))->toBe(ids($t['chefe']));

    emit($os, 'os_completa_consultor', '2026-11-18 10:20:00');
    expect(dueOf($os, 'os_completa_consultor')->status)->toBe('cumprida')
        ->and(WorkshopAlert::where('barrier_code', 'os_completa_consultor')->where('status', 'resolvido')->count())->toBe(1);
});

it('precificação: 20 min com tudo em estoque ou 40 min com fornecedor; só fecha quando a última peça é precificada', function () {
    $this->enablePilot([], ['precificacao_peca']);
    $t = team();
    $engine = app(BarrierEngine::class);
    $os = $this->makeOs(['mechanic_id' => $t['mechanic']->id]);

    foreach (['Filtro de óleo', 'Bomba injetora'] as $nome) {
        $item = new ServiceOrderItem;
        $item->forceFill(['quote_id' => $os->id, 'product_id' => 0, 'tipo' => 'Peça', 'name' => $nome, 'price' => 10, 'quantity' => 1, 'total' => 10])->save();
    }

    $this->at('2026-11-18 09:00:00');
    app(WorkflowService::class)->deliverParts($os);

    // Peças sem cadastro no estoque contam como fornecedor: 40 min.
    expect(dueOf($os, 'precificacao_peca')->vence_em->format('H:i'))->toBe('09:40')
        ->and(dueOf($os, 'precificacao_peca')->nivel2_em->format('H:i'))->toBe('10:00');

    $engine->tick($this->at('2026-11-18 09:40:00'));
    expect(alertedAt('precificacao_peca', 1))->toBe(ids($t['pecas']));

    [$primeira, $segunda] = $os->items()->get();

    $this->actingAs($t['pecas'], 'user');
    $acoes = app(WorkflowActions::class);

    emit($os, 'peca_precificada', '2026-11-18 09:45:00', ['item_id' => $primeira->id]);
    $primeira->forceFill(['precificada_em' => now()])->save();
    // Falta uma: a barreira continua aberta.
    expect(dueOf($os, 'precificacao_peca')->status)->not->toBe('cumprida');

    $segunda->forceFill(['precificada_em' => now()])->save();
    emit($os, 'peca_precificada', '2026-11-18 09:50:00', ['item_id' => $segunda->id]);

    expect(dueOf($os, 'precificacao_peca')->status)->toBe('cumprida')
        ->and(dueOf($os, 'precificacao_peca')->cumprida_no_prazo)->toBeFalse();

    expect($acoes)->not->toBeNull();
});

it('resposta do cliente: 48 h em relógio corrido, gerente +24 h, e a aprovação parcial também fecha', function () {
    $this->enablePilot([], ['resposta_cliente']);
    $t = team();
    $engine = app(BarrierEngine::class);

    $os = $this->makeOs(['user_id' => $t['consultor']->id]);
    emit($os, 'orcamento_enviado', '2026-11-18 10:00:00', ['canal' => 'WhatsApp']);

    expect(dueOf($os, 'resposta_cliente')->vence_em->format('d/m H:i'))->toBe('20/11 10:00');

    $engine->tick($this->at('2026-11-20 10:00:00'));
    $engine->tick($this->at('2026-11-21 10:00:00'));

    expect(alertedAt('resposta_cliente', 1))->toBe(ids($t['consultor']))
        ->and(alertedAt('resposta_cliente', 2))->toBe(ids($t['gerente']));

    $outra = $this->makeOs(['user_id' => $t['consultor']->id]);
    emit($outra, 'orcamento_enviado', '2026-11-18 10:00:00');
    emit($outra, 'cliente_aprovou_parcial', '2026-11-19 09:00:00', ['tipo' => 'parcial']);

    expect(dueOf($outra, 'resposta_cliente')->status)->toBe('cumprida')
        ->and(dueOf($outra, 'resposta_cliente')->cumprida_no_prazo)->toBeTrue();
});

it('compra da peça: até o fim do dia para Peças, gerente no dia seguinte; só abre se há peça de fornecedor', function () {
    $this->enablePilot([], ['compra_peca']);
    $t = team();
    $engine = app(BarrierEngine::class);

    $comFornecedor = $this->makeOs();
    $peca = new ServiceOrderItem;
    $peca->forceFill(['quote_id' => $comFornecedor->id, 'product_id' => 0, 'tipo' => 'Peça', 'name' => 'Turbina', 'price' => 10, 'quantity' => 1, 'total' => 10, 'origem_preco' => 'fornecedor'])->save();

    $semCompra = $this->makeOs();

    emit($comFornecedor, 'cliente_aprovou', '2026-11-18 10:00:00', ['tipo' => 'total']);
    emit($semCompra, 'cliente_aprovou', '2026-11-18 10:00:00', ['tipo' => 'total']);

    expect(dueOf($semCompra, 'compra_peca'))->toBeNull()
        ->and(dueOf($comFornecedor, 'compra_peca')->vence_em->format('d/m H:i'))->toBe('18/11 18:00')
        ->and(dueOf($comFornecedor, 'compra_peca')->nivel2_em->format('d/m H:i'))->toBe('19/11 18:00');

    $engine->tick($this->at('2026-11-18 17:59:00'));
    expect(WorkshopAlert::where('barrier_code', 'compra_peca')->count())->toBe(0);

    $engine->tick($this->at('2026-11-18 18:00:00'));
    $engine->tick($this->at('2026-11-19 18:00:00'));

    expect(alertedAt('compra_peca', 1))->toBe(ids($t['pecas']))
        ->and(alertedAt('compra_peca', 2))->toBe(ids($t['gerente']));

    // A compra fecha a barreira quando não sobra peça por comprar.
    $peca->forceFill(['comprada_em' => now()])->save();
    emit($comFornecedor, 'compra_peca', '2026-11-19 18:10:00', ['item_id' => $peca->id]);

    expect(dueOf($comFornecedor, 'compra_peca')->status)->toBe('cumprida');
});

it('atualização ao cliente: a cada 24 h; cada atualização reinicia a contagem', function () {
    $this->enablePilot([], ['atualizacao_cliente']);
    $t = team();
    $engine = app(BarrierEngine::class);
    $os = $this->makeOs(['user_id' => $t['consultor']->id]);

    emit($os, 'execucao_liberada', '2026-11-18 09:00:00');
    emit($os, 'cliente_atualizado', '2026-11-18 15:00:00', ['canal' => 'WhatsApp']);

    // O prazo vale 24 h a partir da última atualização, não da liberação.
    expect($engine->tick($this->at('2026-11-19 09:00:00')))->toBe(0)
        ->and(dueOf($os, 'atualizacao_cliente')->vence_em->format('d/m H:i'))->toBe('19/11 15:00');

    $engine->tick($this->at('2026-11-19 15:00:00'));
    $engine->tick($this->at('2026-11-20 15:00:00'));

    expect(alertedAt('atualizacao_cliente', 1))->toBe(ids($t['consultor']))
        ->and(alertedAt('atualizacao_cliente', 2))->toBe(ids($t['gerente']));

    // Ao chegar no teste, a barreira deixa de valer.
    emit($os, 'teste_inicio', '2026-11-20 16:00:00');
    expect(BarrierInstance::abertas()->where('barrier_code', 'atualizacao_cliente')->count())->toBe(0);
});

it('prazo prometido: avisa 24 h antes, gerente no vencimento, proprietário 24 h depois; o teste encerra', function () {
    $this->enablePilot([], ['prazo_prometido']);
    $t = team();
    $engine = app(BarrierEngine::class);

    $this->at('2026-11-18 09:00:00');
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_EM_EXECUCAO]);
    $os->update(['data_previsao' => '2026-11-20']);

    $prazo = ServiceOrderEvent::where('service_order_id', $os->id)->where('tipo_evento', 'prazo_informado')->first();

    expect($prazo->dados_json['prazo_em'])->toBe('2026-11-20 18:00:00')
        ->and($os->refresh()->prazo_prometido_em->format('d/m H:i'))->toBe('20/11 18:00')
        ->and(dueOf($os, 'prazo_prometido')->vence_em->format('d/m H:i'))->toBe('19/11 18:00');

    $engine->tick($this->at('2026-11-19 18:00:00'));
    expect(alertedAt('prazo_prometido', 1))->toBe(ids($t['chefe'], $t['consultor']));

    $engine->tick($this->at('2026-11-20 18:00:00'));
    expect(alertedAt('prazo_prometido', 2))->toBe(ids($t['gerente']))
        ->and(alertedAt('prazo_prometido', 3))->toBe([]);

    $engine->tick($this->at('2026-11-21 18:00:00'));
    expect(alertedAt('prazo_prometido', 3))->toBe(ids($t['dono']));

    // Uma OS que já está no teste não abre a barreira.
    $noTeste = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_VEICULO_TESTE]);
    $noTeste->update(['data_previsao' => '2026-11-25']);
    expect(dueOf($noTeste, 'prazo_prometido'))->toBeNull();
});

it('pronto e não faturado: consultor e financeiro no fim do dia, gerente no dia seguinte', function () {
    $this->enablePilot([], ['pronto_nao_faturado']);
    $t = team();
    $engine = app(BarrierEngine::class);
    $os = $this->makeOs();

    emit($os, 'checklist_saida', '2026-11-18 15:00:00');
    $engine->tick($this->at('2026-11-18 18:00:00'));
    $engine->tick($this->at('2026-11-19 18:00:00'));

    expect(alertedAt('pronto_nao_faturado', 1))->toBe(ids($t['consultor'], $t['financeiro']))
        ->and(alertedAt('pronto_nao_faturado', 2))->toBe(ids($t['gerente']));

    $enviada = $this->makeOs();
    emit($enviada, 'checklist_saida', '2026-11-18 15:00:00');
    emit($enviada, 'os_enviada_financeiro', '2026-11-18 16:00:00');
    expect(dueOf($enviada, 'pronto_nao_faturado')->cumprida_no_prazo)->toBeTrue();
});

it('pós-venda em 7 dias: consultor, gerente +2 dias; o contato encerra', function () {
    $this->enablePilot([], ['posvenda_7_dias']);
    $t = team();
    $engine = app(BarrierEngine::class);
    $os = $this->makeOs(['user_id' => $t['consultor']->id]);

    emit($os, 'saida_veiculo', '2026-11-18 12:00:00', ['sem_nf' => false]);

    expect($engine->tick($this->at('2026-11-25 11:59:00')))->toBe(0);

    $engine->tick($this->at('2026-11-25 12:00:00'));
    $engine->tick($this->at('2026-11-27 12:00:00'));

    expect(alertedAt('posvenda_7_dias', 1))->toBe(ids($t['consultor']))
        ->and(alertedAt('posvenda_7_dias', 2))->toBe(ids($t['gerente']));

    $outra = $this->makeOs();
    emit($outra, 'saida_veiculo', '2026-11-18 12:00:00', ['sem_nf' => false]);
    emit($outra, 'posvenda_contato', '2026-11-20 09:00:00');

    expect(dueOf($outra, 'posvenda_7_dias')->cumprida_no_prazo)->toBeTrue();
});

it('diagnóstico de retorno: chefe em 2 h, gerente +1 h', function () {
    $this->enablePilot([], ['diagnostico_retorno']);
    $t = team();
    $engine = app(BarrierEngine::class);

    $this->at('2026-11-18 09:00:00');
    $original = $this->makeOs();
    $retorno = $this->makeOs();
    $retorno->forceFill(['tipo_os' => 'retorno', 'os_origem_id' => $original->id])->save();
    emit($retorno, 'retorno_aberto', '2026-11-18 09:00:00', ['tipo' => 'retorno']);

    $engine->tick($this->at('2026-11-18 11:00:00'));
    $engine->tick($this->at('2026-11-18 12:00:00'));

    expect(alertedAt('diagnostico_retorno', 1))->toBe(ids($t['chefe']))
        ->and(alertedAt('diagnostico_retorno', 2))->toBe(ids($t['gerente']));
});

it('reclamação: gerente com 36 h sem solução e proprietário aos 48 h, sem nível 2', function () {
    $this->enablePilot([], ['reclamacao']);
    $t = team();
    $engine = app(BarrierEngine::class);
    $os = $this->makeOs();

    emit($os, 'reclamacao_aberta', '2026-11-18 10:00:00', ['referencia_id' => 7]);
    // Outra reclamação na mesma OS corre à parte.
    emit($os, 'reclamacao_aberta', '2026-11-18 11:00:00', ['referencia_id' => 8]);
    emit($os, 'reclamacao_fechada', '2026-11-18 12:00:00', ['referencia_id' => 8]);

    $engine->tick($this->at('2026-11-19 21:59:00'));
    expect(WorkshopAlert::where('barrier_code', 'reclamacao')->count())->toBe(0);

    $engine->tick($this->at('2026-11-19 22:00:00'));
    expect(alertedAt('reclamacao', 1))->toBe(ids($t['gerente']));

    $engine->tick($this->at('2026-11-20 10:00:00'));
    expect(alertedAt('reclamacao', 2))->toBe([])
        ->and(alertedAt('reclamacao', 3))->toBe(ids($t['dono']))
        ->and(BarrierInstance::where('barrier_code', 'reclamacao')->where('referencia_id', 8)->first()->status)->toBe('cumprida');
});

it('crítico: diferença de caixa vai na hora ao gerente e ao proprietário', function () {
    $this->enablePilot([], ['critico_diferenca_caixa']);
    $t = team();
    $os = $this->makeOs();

    emit($os, 'caixa_diferenca', '2026-11-18 20:00:00', ['diferenca_centavos' => 1500]);

    $alertas = WorkshopAlert::where('barrier_code', 'critico_diferenca_caixa')->get();

    expect($alertas->pluck('user_id')->sort()->values()->all())->toBe(ids($t['gerente'], $t['dono']))
        ->and($alertas->every(fn ($a) => $a->critico))->toBeTrue()
        // Alertas da equipe nunca levam valores em reais.
        ->and($alertas->pluck('mensagem')->implode(' '))->not->toContain('R$');
});

it('barreira desativada na configuração não abre nem alerta', function () {
    $this->enablePilot([], ['recepcao']);
    BarrierRule::where('codigo', 'recepcao')->update(['ativo' => false]);
    $os = $this->makeOs();

    emit($os, 'chegada_veiculo', '2026-11-18 08:00:00');

    expect(dueOf($os, 'recepcao'))->toBeNull();
});

// ---------------------------------------------------------------------------
// Bloqueios de etapa do capítulo 11 (Passo 2) que o piloto não cobria.
// ---------------------------------------------------------------------------

it('Orçamento -> Negociação exige todas as peças precificadas e o orçamento enviado', function () {
    $this->enablePilot();
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_ORCAMENTO]);

    $peca = new ServiceOrderItem;
    $peca->forceFill(['quote_id' => $os->id, 'product_id' => 0, 'tipo' => 'Peça', 'name' => 'Turbina', 'price' => 10, 'quantity' => 1, 'total' => 10])->save();

    $resultado = app(StageAdvanceService::class)->advance($os, ServiceOrder::STAGE_NEGOCIACAO);

    expect($resultado['avancou'])->toBeFalse()
        ->and($resultado['faltando'])->toBe(['Peça sem preço definido por Peças: Turbina', 'Orçamento enviado ao cliente']);

    $peca->forceFill(['precificada_em' => now()])->save();
    $os->forceFill(['orcamento_enviado_em' => now()])->save();

    expect(app(StageAdvanceService::class)->advance($os->refresh(), ServiceOrder::STAGE_NEGOCIACAO)['avancou'])->toBeTrue();
});

it('Test drive -> Faturamento exige autorização, condutor com CNH da categoria, horário, km e conferência do chefe', function () {
    $this->enablePilot();
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_VEICULO_TESTE]);
    $gate = app(StageGate::class);

    expect($gate->missing($os, ServiceOrder::STAGE_FATURAMENTO))->toBe([
        'Autorização de rodagem do cliente',
        'Condutor do test drive',
        'Horário de saída e volta do test drive',
        'Km de saída e de volta do test drive',
        'Conferência final do chefe de oficina',
    ]);

    // Condutor sem CNH cadastrada, ou com categoria B para um caminhão, não vale.
    $condutor = $this->makeMechanic('Condutor');
    $os->forceFill(['teste_mechanic_id' => $condutor->id, 'autorizacao_rodagem' => true])->save();

    expect($gate->missing($os->refresh(), ServiceOrder::STAGE_FATURAMENTO))
        ->toContain('Condutor sem CNH na categoria exigida (cadastre a categoria da CNH do funcionário)');

    Mechanic::whereKey($condutor->id)->update(['cnh_categoria' => 'B']);
    expect($gate->missing($os->refresh(), ServiceOrder::STAGE_FATURAMENTO))->toContain('Condutor sem CNH na categoria exigida (cadastre a categoria da CNH do funcionário)');

    Mechanic::whereKey($condutor->id)->update(['cnh_categoria' => 'E']);
    $os->forceFill([
        'teste_iniciado_em' => now()->subHour(), 'teste_finalizado_em' => now(),
        'teste_km_saida' => 1000, 'teste_km_volta' => 1012, 'conferencia_final_em' => now(),
    ])->save();

    expect($gate->missing($os->refresh(), ServiceOrder::STAGE_FATURAMENTO))->toBe([]);
});

it('Execução -> Teste exige o checklist de montagem em serviço de motor ou transmissão', function () {
    $this->enablePilot();
    $motorista = Mechanic::create(['nome' => 'Mestre do Motor', 'especialidade' => 'motor', 'status' => 'Ativo']);
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_EM_EXECUCAO, 'mechanic_id' => $motorista->id]);
    $this->makeServiceItem($os, 8.0, $motorista->id, 'Retífica do motor');
    $gate = app(StageGate::class);

    expect($gate->missing($os, ServiceOrder::STAGE_VEICULO_TESTE))->toContain('Checklist de montagem (serviço de motor ou transmissão)');

    ServiceOrderEvent::record($os, 'checklist_montagem');

    expect($gate->missing($os->refresh(), ServiceOrder::STAGE_VEICULO_TESTE))->not->toContain('Checklist de montagem (serviço de motor ou transmissão)');
});

// ---------------------------------------------------------------------------
// Ações de tela (WorkflowActions) pelas rotas.
// ---------------------------------------------------------------------------

function staffUser(string $perfil, array $extraPermissions = [], array $extra = []): User
{
    return test()->makeUser($perfil, array_merge(['service_orders', 'service_orders.view', 'service_orders.edit'], $extraPermissions), $extra);
}

it('registrar recepção grava o evento e fecha a barreira', function () {
    $this->enablePilot([], ['recepcao']);
    $consultor = staffUser('consultor');
    $os = $this->makeOs(['user_id' => $consultor->id]);
    emit($os, 'chegada_veiculo', '2026-11-18 08:00:00');
    $this->at('2026-11-18 08:05:00');

    $this->actingAs($consultor, 'user')
        ->post(route('admin.service_orders.workflow.action', [$os->id, 'recepcao']))
        ->assertRedirect(route('admin.service_orders.view', $os->id))
        ->assertSessionHas('success');

    expect(ServiceOrderEvent::where('service_order_id', $os->id)->where('tipo_evento', 'recepcao')->first()->usuario_id)->toBe($consultor->id)
        ->and(dueOf($os, 'recepcao')->cumprida_no_prazo)->toBeTrue();
});

it('precificar peça: só quem vê valores; o evento não carrega preço; o total da OS é recalculado', function () {
    $this->enablePilot();
    $tecnico = staffUser('tecnico');
    $pecas = staffUser('pecas', ['ver_valores_financeiros']);
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_ORCAMENTO]);

    $peca = new ServiceOrderItem;
    $peca->forceFill(['quote_id' => $os->id, 'product_id' => 0, 'tipo' => 'Peça', 'name' => 'Turbina', 'price' => 0, 'quantity' => 2, 'total' => 0])->save();

    $payload = ['item_id' => $peca->id, 'preco_unitario' => 500, 'custo_unitario' => 320, 'origem_preco' => 'fornecedor', 'fornecedor' => 'Auto Peças Sul'];
    $rota = route('admin.service_orders.workflow.action', [$os->id, 'precificar_peca']);

    $this->actingAs($tecnico, 'user')->post($rota, $payload)->assertForbidden();

    $this->actingAs($pecas, 'user')->post($rota, $payload)->assertSessionHas('success');

    $peca->refresh();
    $evento = ServiceOrderEvent::where('service_order_id', $os->id)->where('tipo_evento', 'peca_precificada')->first();

    expect((float) $peca->price)->toBe(500.0)
        ->and((float) $peca->custo_unitario)->toBe(320.0)
        ->and($peca->origem_preco)->toBe('fornecedor')
        ->and($peca->precificada_em)->not->toBeNull()
        ->and((float) $os->refresh()->grand_total)->toBe(1000.0)
        ->and(json_encode($evento->dados_json))->not->toContain('500')->not->toContain('320');
});

it('checklist de saída guarda horas reais e próxima revisão; enviar ao financeiro depende dele', function () {
    $this->enablePilot([], ['pronto_nao_faturado']);
    $consultor = staffUser('consultor');
    $mechanic = $this->makeMechanic();
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_FATURAMENTO, 'user_id' => $consultor->id, 'mechanic_id' => $mechanic->id]);
    $item = $this->makeServiceItem($os, 2.0, $mechanic->id);

    $this->at('2026-11-18 08:00:00');
    app(OperationService::class)->start($item);
    $this->at('2026-11-18 10:10:00');
    app(OperationService::class)->finish($item);
    $this->at('2026-11-18 15:00:00');

    $this->actingAs($consultor, 'user');

    // Sem checklist, não envia.
    $this->post(route('admin.service_orders.workflow.action', [$os->id, 'enviar_financeiro']))->assertSessionHas('error');

    // Revisão no passado é recusada.
    $this->post(route('admin.service_orders.workflow.action', [$os->id, 'checklist_saida']), ['proxima_revisao_data' => '2026-11-01'])->assertSessionHasErrors('proxima_revisao_data');

    $this->post(route('admin.service_orders.workflow.action', [$os->id, 'checklist_saida']), ['proxima_revisao_data' => '2027-02-18', 'proxima_revisao_km' => 150000])
        ->assertSessionHas('success');

    $os->refresh();
    $evento = ServiceOrderEvent::where('service_order_id', $os->id)->where('tipo_evento', 'checklist_saida')->first();

    // 2h10 trabalhadas em 08:00-10:10 contra 2h de tempo padrão.
    expect((float) $os->horas_reais_saida)->toBe(2.17)
        ->and($os->proxima_revisao_data->format('Y-m-d'))->toBe('2027-02-18')
        ->and($evento->dados_json['horas_padrao'])->toEqual(2)
        ->and($evento->dados_json['horas_reais'])->toEqual(2.17);

    $this->post(route('admin.service_orders.workflow.action', [$os->id, 'enviar_financeiro']))->assertSessionHas('success');

    expect($os->refresh()->enviada_financeiro_em)->not->toBeNull()
        ->and(dueOf($os, 'pronto_nao_faturado')->cumprida_no_prazo)->toBeTrue();
});

it('abrir retorno cria a OS vinculada, grava o evento e a causa é classificada pelo chefe', function () {
    $this->enablePilot([], ['diagnostico_retorno']);
    $consultor = staffUser('consultor');
    $chefe = staffUser('chefe', ['service_orders.auditar']);
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_POS_VENDA, 'user_id' => $consultor->id]);

    $resposta = $this->actingAs($consultor, 'user')
        ->post(route('admin.service_orders.workflow.action', [$os->id, 'abrir_retorno']), ['tipo' => 'retorno', 'motivo' => 'Voltou com ruído na caixa']);

    $retorno = ServiceOrder::where('os_origem_id', $os->id)->first();

    $resposta->assertRedirect(route('admin.service_orders.view', $retorno->id));

    expect($retorno->tipo_os)->toBe('retorno')
        ->and($retorno->vehicle_id)->toBe($os->vehicle_id)
        ->and(ServiceOrderEvent::where('service_order_id', $retorno->id)->where('tipo_evento', 'retorno_aberto')->count())->toBe(1)
        ->and(dueOf($retorno, 'diagnostico_retorno'))->not->toBeNull();

    // Consultor não classifica a causa (é do chefe).
    DB::table('quotes')->where('id', $retorno->id)->update(['workshop_status' => ServiceOrder::STAGE_DIAGNOSTICO]);
    $this->actingAs($consultor, 'user')->post(route('admin.service_orders.workflow.action', [$retorno->id, 'classificar_retorno']), ['causa' => 'peca'])->assertForbidden();

    $this->actingAs($chefe, 'user')->post(route('admin.service_orders.workflow.action', [$retorno->id, 'classificar_retorno']), ['causa' => 'execucao'])->assertSessionHas('success');

    expect($retorno->refresh()->retorno_causa)->toBe('execucao');
});

it('reclamação: abrir e fechar gravam eventos e a nota do cliente fica na OS', function () {
    $this->enablePilot([], ['reclamacao']);
    $consultor = staffUser('consultor');
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_POS_VENDA, 'user_id' => $consultor->id]);
    $this->actingAs($consultor, 'user');

    $this->post(route('admin.service_orders.workflow.action', [$os->id, 'abrir_reclamacao']), ['descricao' => 'Vazamento de óleo após o serviço'])->assertSessionHas('success');

    $reclamacao = DB::table('service_order_reclamacoes')->where('quote_id', $os->id)->first();

    expect($reclamacao->fechada_em)->toBeNull()
        ->and(BarrierInstance::abertas()->where('barrier_code', 'reclamacao')->where('referencia_id', $reclamacao->id)->exists())->toBeTrue();

    $this->post(route('admin.service_orders.workflow.action', [$os->id, 'fechar_reclamacao']), ['reclamacao_id' => $reclamacao->id, 'solucao' => 'Retrabalho sem custo'])->assertSessionHas('success');

    expect(DB::table('service_order_reclamacoes')->find($reclamacao->id)->fechada_em)->not->toBeNull()
        ->and(BarrierInstance::abertas()->where('barrier_code', 'reclamacao')->count())->toBe(0);

    $this->post(route('admin.service_orders.workflow.action', [$os->id, 'nota_cliente']), ['nota' => 9])->assertSessionHas('success');
    $this->post(route('admin.service_orders.workflow.action', [$os->id, 'nota_cliente']), ['nota' => 11])->assertSessionHasErrors('nota');

    expect($os->refresh()->nota_recomendacao)->toBe(9);
});

it('ações fora da etapa ou de outro perfil são recusadas', function () {
    $this->enablePilot();
    $consultor = staffUser('consultor');
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_CADASTRO, 'user_id' => $consultor->id]);
    $this->actingAs($consultor, 'user');

    $this->post(route('admin.service_orders.workflow.action', [$os->id, 'checklist_saida']), ['proxima_revisao_data' => '2027-02-18'])
        ->assertSessionHas('error');

    $this->post(route('admin.service_orders.workflow.action', [$os->id, 'acao_inventada']))->assertNotFound();

    expect($os->refresh()->checklist_saida_em)->toBeNull();
});
