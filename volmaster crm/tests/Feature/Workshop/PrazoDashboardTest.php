<?php

use Illuminate\Support\Facades\DB;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Services\PrazoOs;

function abrirBarreiraPrazo(int $osId, string $vence, array $extra = []): void
{
    DB::table('barrier_instances')->insert(array_merge([
        'service_order_id' => $osId,
        'barrier_code' => 'orcamento_2h',
        'referencia_id' => 0,
        'aberta_em' => now()->subHours(2)->format('Y-m-d H:i:s'),
        'vence_em' => $vence,
        'nivel_atual' => 0,
        'status' => 'no_prazo',
        'created_at' => now(),
        'updated_at' => now(),
    ], $extra));
}

function estadosDoPrazo(): array
{
    $ordens = PrazoOs::aplicar(
        ServiceOrder::whereNotIn('workshop_status', [ServiceOrder::STATUS_CONCLUIDA, ServiceOrder::STATUS_CANCELADA])->get()
    );

    return $ordens->mapWithKeys(fn ($os) => [$os->id => $os->prazo['estado']])->all();
}

it('piloto ligado: a barreira aberta decide a cor (verde no prazo, vermelho estourado)', function () {
    $this->enablePilot();
    $this->at('2026-11-18 10:00:00');

    $noPrazo = $this->makeOs();
    $estourada = $this->makeOs();
    $semPrazo = $this->makeOs();
    $cumprida = $this->makeOs();
    $duas = $this->makeOs();

    abrirBarreiraPrazo($noPrazo->id, now()->addHours(2)->format('Y-m-d H:i:s'));
    // O relógio de 1 minuto ainda não marcou como vencida, mas o prazo já passou.
    abrirBarreiraPrazo($estourada->id, now()->subMinutes(5)->format('Y-m-d H:i:s'));
    abrirBarreiraPrazo($cumprida->id, now()->subHours(3)->format('Y-m-d H:i:s'), ['status' => 'cumprida']);
    // Com duas barreiras abertas vale a que vence primeiro.
    abrirBarreiraPrazo($duas->id, now()->addHours(5)->format('Y-m-d H:i:s'), ['barrier_code' => 'operacao_tempo']);
    abrirBarreiraPrazo($duas->id, now()->subMinutes(1)->format('Y-m-d H:i:s'));

    $estados = estadosDoPrazo();

    expect($estados[$noPrazo->id])->toBe(PrazoOs::NO_PRAZO)
        ->and($estados[$estourada->id])->toBe(PrazoOs::ESTOURADO)
        ->and($estados[$semPrazo->id])->toBe(PrazoOs::SEM_PRAZO)
        ->and($estados[$cumprida->id])->toBe(PrazoOs::SEM_PRAZO)
        ->and($estados[$duas->id])->toBe(PrazoOs::ESTOURADO);
});

it('barreira já marcada como vencida (ou com aviso de nível 1) fica vermelha mesmo se o prazo foi prorrogado', function () {
    $this->enablePilot();
    $this->at('2026-11-18 10:00:00');

    $vencida = $this->makeOs();
    $comAviso = $this->makeOs();

    abrirBarreiraPrazo($vencida->id, now()->addHour()->format('Y-m-d H:i:s'), ['status' => 'vencida']);
    abrirBarreiraPrazo($comAviso->id, now()->addHour()->format('Y-m-d H:i:s'), ['nivel_atual' => 1]);

    $estados = estadosDoPrazo();

    expect($estados[$vencida->id])->toBe(PrazoOs::ESTOURADO)
        ->and($estados[$comAviso->id])->toBe(PrazoOs::ESTOURADO);
});

it('sem barreira, vale a previsão de entrega até o fim do dia marcado', function () {
    $this->enablePilot();
    $this->at('2026-11-18 18:30:00');

    $hoje = $this->makeOs(['data_previsao' => '2026-11-18']);
    $amanha = $this->makeOs(['data_previsao' => '2026-11-19']);
    $ontem = $this->makeOs(['data_previsao' => '2026-11-17']);
    $semData = $this->makeOs();

    $estados = estadosDoPrazo();

    expect($estados[$hoje->id])->toBe(PrazoOs::NO_PRAZO)
        ->and($estados[$amanha->id])->toBe(PrazoOs::NO_PRAZO)
        ->and($estados[$ontem->id])->toBe(PrazoOs::ESTOURADO)
        ->and($estados[$semData->id])->toBe(PrazoOs::SEM_PRAZO);
});

it('piloto desligado: barreiras não contam, só a previsão de entrega', function () {
    $this->at('2026-11-18 10:00:00');

    $comBarreira = $this->makeOs();
    $atrasada = $this->makeOs(['data_previsao' => '2026-11-10']);

    abrirBarreiraPrazo($comBarreira->id, now()->subHours(4)->format('Y-m-d H:i:s'));

    $estados = estadosDoPrazo();

    expect($estados[$comBarreira->id])->toBe(PrazoOs::SEM_PRAZO)
        ->and($estados[$atrasada->id])->toBe(PrazoOs::ESTOURADO);
});

it('o card do Início recebe o estado e o texto do prazo de cada OS', function () {
    $this->enablePilot();
    $this->at('2026-11-18 10:00:00');

    $user = $this->makeUser('consultor', ['dashboard']);

    $estourada = $this->makeOs();
    $noPrazo = $this->makeOs();

    abrirBarreiraPrazo($estourada->id, now()->subHour()->format('Y-m-d H:i:s'));
    abrirBarreiraPrazo($noPrazo->id, now()->addHour()->format('Y-m-d H:i:s'));

    $resposta = $this->actingAs($user, 'user')
        ->getJson(route('admin.dashboard.stats', ['type' => 'pending-service-orders']))
        ->assertOk()
        ->json('statistics');

    $porId = collect($resposta)->keyBy('id');

    expect($porId[$estourada->id]['prazo']['estado'])->toBe('estourado')
        ->and($porId[$estourada->id]['prazo']['texto'])->toContain('prazo estourado em')
        ->and($porId[$noPrazo->id]['prazo']['estado'])->toBe('no_prazo')
        ->and($porId[$noPrazo->id]['prazo']['texto'])->toContain('vence em');
});
