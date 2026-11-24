<?php

use Webkul\ServiceOrder\Models\BarrierInstance;
use Webkul\ServiceOrder\Models\BarrierRule;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Models\WorkshopAlert;
use Webkul\ServiceOrder\Services\BarrierEngine;

/**
 * Oficina de teste: um usuário por papel + uma OS cujo diagnóstico termina
 * às 9h de uma quarta-feira (horário comercial 8h-18h).
 */
function budgetScenario(): array
{
    $consultor = test()->makeUser('consultor');
    $chefe = test()->makeUser('chefe');
    $gerente = test()->makeUser('gerente');
    $dono = test()->makeUser('proprietario');

    $os = test()->makeOs(['user_id' => $consultor->id]);

    return compact('consultor', 'chefe', 'gerente', 'dono', 'os');
}

function recipientsOf(string $code, int $nivel): array
{
    return WorkshopAlert::where('barrier_code', $code)
        ->where('nivel', $nivel)
        ->pluck('user_id')
        ->sort()
        ->values()
        ->all();
}

it('registra eventos que nunca podem ser alterados nem apagados', function () {
    $os = $this->makeOs();

    $event = ServiceOrderEvent::record($os, 'chegada_veiculo', ['obs' => 'teste']);

    expect(fn () => $event->update(['tipo_evento' => 'saida_veiculo']))->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class);

    $correcao = ServiceOrderEvent::correct($event, 'Registrado no horário errado');

    expect($correcao->tipo_evento)->toBe('correcao')
        ->and($correcao->dados_json['corrige_evento_id'])->toBe($event->id)
        ->and(fn () => ServiceOrderEvent::correct($event, '  '))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ServiceOrderEvent::record($os, 'evento_inventado'))->toThrow(InvalidArgumentException::class);
});

it('grava a mudança de etapa de qualquer caminho, com a etapa de origem e destino', function () {
    $os = $this->makeOs();

    expect(ServiceOrderEvent::where('service_order_id', $os->id)->where('tipo_evento', 'mudanca_etapa')->count())->toBe(1);

    $os->update(['workshop_status' => ServiceOrder::STAGE_ORDEM_SERVICO]);

    $evento = ServiceOrderEvent::where('service_order_id', $os->id)->where('tipo_evento', 'mudanca_etapa')->latest('id')->first();

    expect($evento->etapa_origem)->toBe(ServiceOrder::STAGE_CADASTRO)
        ->and($evento->etapa_destino)->toBe(ServiceOrder::STAGE_ORDEM_SERVICO)
        ->and($evento->ocorrido_em)->not->toBeNull();
});

it('critério 1: diagnóstico finalizado às 9h sem orçamento alerta às 11h, 12h e 13h, uma vez por nível', function () {
    $this->enablePilot();
    ['consultor' => $consultor, 'chefe' => $chefe, 'gerente' => $gerente, 'dono' => $dono, 'os' => $os] = budgetScenario();
    $engine = app(BarrierEngine::class);

    $this->at('2026-11-18 09:00:00');
    ServiceOrderEvent::record($os, 'diagnostico_fim');

    $instance = BarrierInstance::where('service_order_id', $os->id)->first();
    expect($instance->vence_em->format('H:i'))->toBe('11:00')
        ->and($instance->nivel2_em->format('H:i'))->toBe('12:00')
        ->and($instance->nivel3_em->format('H:i'))->toBe('13:00');

    // Antes do vencimento: nada.
    expect($engine->tick($this->at('2026-11-18 10:59:00')))->toBe(0);

    // 11h: consultor e chefe.
    $engine->tick($this->at('2026-11-18 11:00:00'));
    expect(recipientsOf('orcamento_2h', 1))->toBe(collect([$consultor->id, $chefe->id])->sort()->values()->all());

    // O job roda de novo a cada minuto: não repete o nível 1.
    $engine->tick($this->at('2026-11-18 11:01:00'));
    $engine->tick($this->at('2026-11-18 11:30:00'));
    expect(WorkshopAlert::where('barrier_code', 'orcamento_2h')->count())->toBe(2);

    // 12h: gerente. O dono ainda não recebe nada.
    $engine->tick($this->at('2026-11-18 12:00:00'));
    expect(recipientsOf('orcamento_2h', 2))->toBe([$gerente->id])
        ->and(WorkshopAlert::where('user_id', $dono->id)->count())->toBe(0);

    // 13h: proprietário (nível 3).
    $engine->tick($this->at('2026-11-18 13:00:00'));
    $engine->tick($this->at('2026-11-18 13:05:00'));
    expect(recipientsOf('orcamento_2h', 3))->toBe([$dono->id])
        ->and(WorkshopAlert::where('barrier_code', 'orcamento_2h')->count())->toBe(4)
        ->and(ServiceOrderEvent::where('service_order_id', $os->id)->where('tipo_evento', 'alerta_enviado')->count())->toBe(3);
});

it('critério 2: orçamento enviado às 11h20 fecha a barreira, avisa "resolvido" e conta como fora do prazo', function () {
    $this->enablePilot();
    ['consultor' => $consultor, 'chefe' => $chefe, 'os' => $os] = budgetScenario();
    $engine = app(BarrierEngine::class);

    $this->at('2026-11-18 09:00:00');
    ServiceOrderEvent::record($os, 'diagnostico_fim');

    $engine->tick($this->at('2026-11-18 11:00:00'));

    $this->at('2026-11-18 11:20:00');
    ServiceOrderEvent::record($os, 'orcamento_enviado', ['canal' => 'whatsapp']);

    $instance = BarrierInstance::where('service_order_id', $os->id)->first();

    expect($instance->status)->toBe('cumprida')
        ->and($instance->cumprida_no_prazo)->toBeFalse()
        ->and($instance->fechada_em->format('H:i'))->toBe('11:20');

    $alerts = WorkshopAlert::where('barrier_code', 'orcamento_2h')->get();

    expect($alerts)->toHaveCount(2)
        ->and($alerts->pluck('status')->unique()->all())->toBe(['resolvido'])
        ->and($alerts->first()->mensagem_resolvido)->toBe("RESOLVIDO - Orçamento em 2 horas da OS #{$os->id} às 11:20.");

    // Já resolvida: os níveis seguintes nunca disparam.
    $engine->tick($this->at('2026-11-18 14:00:00'));
    expect(WorkshopAlert::where('barrier_code', 'orcamento_2h')->count())->toBe(2);
});

it('orçamento enviado dentro do prazo conta como cumprido no prazo e não alerta', function () {
    $this->enablePilot();
    ['os' => $os] = budgetScenario();

    $this->at('2026-11-18 09:00:00');
    ServiceOrderEvent::record($os, 'diagnostico_fim');

    $this->at('2026-11-18 10:30:00');
    ServiceOrderEvent::record($os, 'orcamento_enviado', ['canal' => 'email']);

    $instance = BarrierInstance::where('service_order_id', $os->id)->first();

    expect($instance->cumprida_no_prazo)->toBeTrue()
        ->and(WorkshopAlert::count())->toBe(0);
});

it('critério 7: mudar o prazo na configuração muda o alerta sem alterar código', function () {
    $this->enablePilot();
    ['os' => $os] = budgetScenario();

    BarrierRule::where('codigo', 'orcamento_2h')->update(['prazo_minutos' => 60]);

    $this->at('2026-11-18 09:00:00');
    ServiceOrderEvent::record($os, 'diagnostico_fim');

    expect(BarrierInstance::where('service_order_id', $os->id)->first()->vence_em->format('H:i'))->toBe('10:00');
});

it('o prazo conta só o horário comercial: diagnóstico às 17h vence no dia seguinte', function () {
    $this->enablePilot();
    ['os' => $os] = budgetScenario();

    $this->at('2026-11-18 17:00:00');
    ServiceOrderEvent::record($os, 'diagnostico_fim');

    // 1h até as 18h + 1h no dia seguinte a partir das 8h = 9h.
    expect(BarrierInstance::where('service_order_id', $os->id)->first()->vence_em->format('Y-m-d H:i'))->toBe('2026-11-19 09:00');
});

it('regra do dono blindado: proprietário configurado no nível 1 não recebe, só no nível 3', function () {
    $this->enablePilot();
    ['dono' => $dono, 'os' => $os] = budgetScenario();

    BarrierRule::where('codigo', 'orcamento_2h')->update(['nivel1_destino' => 'consultor,proprietario']);

    $this->at('2026-11-18 09:00:00');
    ServiceOrderEvent::record($os, 'diagnostico_fim');

    app(BarrierEngine::class)->tick($this->at('2026-11-18 11:00:00'));

    expect(WorkshopAlert::where('user_id', $dono->id)->count())->toBe(0);
});

it('com o piloto desligado nenhuma barreira abre e nenhum alerta sai', function () {
    ['os' => $os] = budgetScenario();

    $this->at('2026-11-18 09:00:00');
    ServiceOrderEvent::record($os, 'diagnostico_fim');

    expect(BarrierInstance::count())->toBe(0)
        ->and(app(BarrierEngine::class)->tick($this->at('2026-11-18 14:00:00')))->toBe(0);
});

it('texto do alerta segue o modelo e nunca leva valores em reais nem crédito', function () {
    $this->enablePilot();
    ['os' => $os, 'consultor' => $consultor] = budgetScenario();

    $os->forceFill(['grand_total' => 98765.43, 'cor_credito' => 'vermelho'])->save();

    $this->at('2026-11-18 09:00:00');
    ServiceOrderEvent::record($os, 'diagnostico_fim');
    app(BarrierEngine::class)->tick($this->at('2026-11-18 11:00:00'));

    $alert = WorkshopAlert::where('user_id', $consultor->id)->first();
    $os->refresh()->load(['vehicle', 'person']);

    expect($alert->mensagem)->toStartWith("ALERTA - Orçamento em 2 horas. OS #{$os->id} - {$os->vehicle->placa} - Transportadora Teste. Prazo venceu às 11:00. Responsável: {$consultor->name}")
        ->and($alert->mensagem)->not->toContain('R$')
        ->and($alert->mensagem)->not->toContain('98765')
        ->and(strtolower($alert->mensagem))->not->toContain('vermelho');
});
