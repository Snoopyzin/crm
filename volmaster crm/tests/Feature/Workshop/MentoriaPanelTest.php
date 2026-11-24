<?php

use Webkul\ServiceOrder\DataGrids\ServiceOrderDataGrid;
use Webkul\ServiceOrder\Models\IndicatorDefinition;
use Webkul\ServiceOrder\Models\MentoriaRegistro;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Services\BarrierEngine;
use Webkul\ServiceOrder\Services\Indicators\IndicatorService;
use Webkul\User\Models\User;

function gestor(array $extra = []): User
{
    return test()->makeUser('proprietario', array_merge(['painel_dono', 'indicadores_mentoria', 'registros_mentoria', 'settings', 'settings.metas', 'service_orders', 'service_orders.view'], $extra));
}

beforeEach(function () {
    IndicatorDefinition::forgetSeeded();
});

it('Painel do Dono: seis blocos no computador, com cartões, fluxo de 8 etapas e comparação com o mês anterior', function () {
    $this->enablePilot();
    $this->actingAs(gestor(), 'user');

    $this->get(route('admin.mentoria.painel', ['periodo' => '2026-11']))
        ->assertOk()
        ->assertSee('Painel do Dono')
        ->assertSee('Faturamento')
        ->assertSee('Caminhões entregues')
        ->assertSee('Fluxo do caminhão (8 etapas)')
        ->assertSee('01 Atendimento')
        ->assertSee('08 Pós-venda')
        ->assertSee('Peças e estoque')
        ->assertSee('Caixa e financeiro')
        ->assertSee('Gestão do gerente e RH')
        ->assertSee('O próprio dono');

    $this->get(route('admin.mentoria.painel', ['periodo' => '2026-11', 'comparar' => 1]))
        ->assertOk()
        ->assertSee('Comparação com');
});

it('Painel do celular: abas Hoje, Alertas e Indicadores em JSON, sem tabelas largas', function () {
    $this->enablePilot([], 'todas');
    $dono = gestor();
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_DIAGNOSTICO]);
    $this->makeUser('gerente');
    $this->actingAs($dono, 'user');

    // Uma barreira vencida aparece na aba "Hoje" e um alerta crítico na aba "Alertas".
    ServiceOrderEvent::record($os, 'diagnostico_fim', [], null, null, $this->at('2026-11-18 09:00:00'));
    app(BarrierEngine::class)->tick($this->at('2026-11-18 11:00:00'));
    ServiceOrderEvent::record($os, 'caixa_diferenca', [], null, null, $this->at('2026-11-18 20:00:00'));

    $this->get(route('admin.mentoria.painel.celular'))->assertOk()->assertSee('Painel do Dono')->assertSee('dono.webmanifest', false);

    $hoje = $this->getJson(route('admin.mentoria.painel.dados', ['aba' => 'hoje']))->assertOk();

    expect($hoje->json('no_patio'))->toBe(1)
        ->and($hoje->json('por_etapa'))->toHaveCount(9)
        ->and(collect($hoje->json('barreiras_vencidas'))->pluck('os')->all())->toContain($os->id)
        ->and($hoje->json('faturado_hoje_fmt'))->toStartWith('R$');

    $alertas = $this->getJson(route('admin.mentoria.painel.dados', ['aba' => 'alertas']))->assertOk();

    expect(collect($alertas->json('alertas'))->contains(fn ($a) => $a['critico'] === true))->toBeTrue();

    $indicadores = $this->getJson(route('admin.mentoria.painel.dados', ['aba' => 'indicadores', 'periodo' => '2026-11']))->assertOk();

    expect($indicadores->json('cartoes'))->toHaveCount(8)
        ->and($indicadores->json('passos'))->toHaveCount(8)
        ->and(collect($indicadores->json('blocos'))->pluck('titulo')->all())->toBe(['Peças e estoque', 'Caixa e financeiro', 'Gestão e RH', 'Dono']);
});

it('o app do dono tem manifesto próprio que abre no painel do celular', function () {
    $manifesto = $this->get('/dono.webmanifest')->assertOk()->json();

    expect($manifesto['name'])->toBe('Painel do Dono')
        ->and($manifesto['start_url'])->toEndWith('/mentoria/painel-celular')
        ->and($manifesto['display'])->toBe('standalone');
});

it('quem não tem a permissão da mentoria não entra no painel, nos indicadores, nos registros nem nas metas', function () {
    $this->enablePilot();
    $tecnico = $this->makeUser('tecnico', ['service_orders', 'service_orders.view', 'tecnico']);
    $this->actingAs($tecnico, 'user');

    foreach (['admin.mentoria.painel', 'admin.mentoria.painel.celular', 'admin.mentoria.painel.dados', 'admin.mentoria.indicadores', 'admin.mentoria.registros', 'admin.mentoria.metas'] as $rota) {
        $this->get(route($rota))->assertForbidden();
    }

    $this->put(route('admin.mentoria.metas.update'), ['meta' => ['CON-04' => '50']])->assertForbidden();
    expect(IndicatorDefinition::find('CON-04')?->meta_oficina)->toBeNull();
});

it('indicadores por cargo, com detalhe, fórmula e as OS que formam o número', function () {
    $this->enablePilot([], ['recepcao']);
    $this->actingAs(gestor(), 'user');
    $os = $this->makeOs();
    ServiceOrderEvent::record($os, 'chegada_veiculo', [], null, null, $this->at('2026-11-18 08:00:00'));
    ServiceOrderEvent::record($os, 'recepcao', [], null, null, $this->at('2026-11-18 08:05:00'));
    $this->at('2026-11-30 12:00:00');

    $this->get(route('admin.mentoria.indicadores', ['cargo' => 'consultor', 'periodo' => '2026-11']))
        ->assertOk()
        ->assertSee('CON-01')
        ->assertSee('Recepção em até 10 min')
        ->assertSee('100,0%');

    $this->get(route('admin.mentoria.indicador', ['CON-01', 'periodo' => '2026-11']))
        ->assertOk()
        ->assertSee('OS com (recepção − chegada) ≤ 10 min ÷ OS')
        ->assertSee('#'.$os->id)
        ->assertSee('No alvo');

    $this->get(route('admin.mentoria.indicador', ['XXX-99']))->assertNotFound();
});

it('registros do dia a dia: nota fora de 0 a 10 é recusada; correção exige justificativa e preserva o original', function () {
    $this->actingAs(gestor(), 'user');
    $mecanico = $this->makeMechanic('Zé');

    $this->post(route('admin.mentoria.registros.store'), ['tipo' => 'auditoria_box', 'mechanic_id' => $mecanico->id, 'valor' => 11])->assertSessionHasErrors('valor');
    $this->post(route('admin.mentoria.registros.store'), ['tipo' => 'auditoria_box', 'mechanic_id' => $mecanico->id])->assertSessionHasErrors('valor');
    $this->post(route('admin.mentoria.registros.store'), ['tipo' => 'tipo_inventado'])->assertSessionHasErrors('tipo');

    $this->post(route('admin.mentoria.registros.store'), ['tipo' => 'auditoria_box', 'mechanic_id' => $mecanico->id, 'valor' => 6.5, 'observacao' => 'Box bagunçado'])
        ->assertRedirect(route('admin.mentoria.registros'));

    $registro = MentoriaRegistro::where('tipo', 'auditoria_box')->firstOrFail();

    expect($registro->valor)->toBe(6.5)
        ->and($registro->registrado_por)->not->toBeNull();

    $this->post(route('admin.mentoria.registros.correct', $registro->id), ['valor' => 8])->assertSessionHasErrors('justificativa');
    $this->post(route('admin.mentoria.registros.correct', $registro->id), ['valor' => 8, 'justificativa' => 'Nota lançada errada'])->assertSessionHas('success');

    expect(MentoriaRegistro::where('tipo', 'auditoria_box')->count())->toBe(2)
        ->and(MentoriaRegistro::vigentes()->where('tipo', 'auditoria_box')->first()->valor)->toBe(8.0);

    $this->get(route('admin.mentoria.registros'))->assertOk()->assertSee('Auditoria semanal de box e postura')->assertSee('correção do #'.$registro->id);
});

it('registro com dados extras (NF de entrada) guarda as duas datas', function () {
    $this->actingAs(gestor(), 'user');

    $this->post(route('admin.mentoria.registros.store'), [
        'tipo' => 'nf_entrada',
        'dados' => ['recebida_em' => '2026-11-17 09:00', 'lancada_em' => '2026-11-17 15:00'],
    ])->assertSessionHasNoErrors();

    $registro = MentoriaRegistro::where('tipo', 'nf_entrada')->firstOrFail();

    expect($registro->dados_json)->toBe(['recebida_em' => '2026-11-17 09:00', 'lancada_em' => '2026-11-17 15:00']);
});

it('tabela de metas: salvar muda a meta e desativar tira o indicador do painel', function () {
    $this->actingAs(gestor(), 'user');

    $this->get(route('admin.mentoria.metas'))->assertOk()->assertSee('CON-04')->assertSee('Meta da empresa');

    $payload = [
        'meta' => ['CON-04' => '95', 'CON-15' => '8,5', 'CHE-10' => ''],
        'ativo' => ['CON-04' => '1', 'CON-15' => '1', 'CHE-10' => '1'],
    ];

    $this->put(route('admin.mentoria.metas.update'), $payload)->assertRedirect(route('admin.mentoria.metas'));

    expect(IndicatorDefinition::find('CON-04')->meta())->toBe(95.0)
        ->and(IndicatorDefinition::find('CON-15')->meta())->toBe(8.5)
        // Em branco = volta ao padrão da mentoria.
        ->and(IndicatorDefinition::find('CHE-10')->meta())->toBe(2.0)
        // Os que não foram marcados como ativos saem do painel.
        ->and(IndicatorDefinition::find('CON-01')->ativo)->toBeFalse()
        ->and(app(IndicatorService::class)->definitions()->pluck('codigo')->all())->toBe(['CON-04', 'CON-15', 'CHE-10']);
});

it('as listas de etapa mostram os cartões de indicadores e o semáforo de prazo por OS', function () {
    $this->enablePilot([], ['orcamento_2h']);
    // A rota da lista de etapas pertence a várias chaves de permissão; vale a última registrada.
    $this->actingAs(gestor(['cadastro', 'ordem_servico', 'diagnostico', 'orcamento', 'negociacao', 'em_execucao', 'veiculo_teste', 'faturamento', 'pos_venda']), 'user');

    $this->get(route('admin.service_orders.stage.index', ServiceOrder::STAGE_ORCAMENTO))
        ->assertOk()
        ->assertSee('Precificação no prazo')
        ->assertSee('OS completa ao consultor em até 1 h')
        ->assertSee('Orçamento em até 2 h');

    $this->get(route('admin.service_orders.stage.index', ServiceOrder::STAGE_FATURAMENTO))->assertOk()->assertSee('Prontos e não faturados');

    $linha = fn (array $campos) => (object) $campos;

    expect(ServiceOrderDataGrid::semaforo($linha(['prazo_vence_em' => null])))->toContain('—');

    $this->at('2026-11-18 10:00:00');

    $vencida = ServiceOrderDataGrid::semaforo($linha(['prazo_vence_em' => '2026-11-18 09:00:00', 'prazo_aberta_em' => '2026-11-18 07:00:00', 'prazo_nivel' => 0]));
    $aVencer = ServiceOrderDataGrid::semaforo($linha(['prazo_vence_em' => '2026-11-18 10:10:00', 'prazo_aberta_em' => '2026-11-18 08:10:00', 'prazo_nivel' => 0]));
    $noPrazo = ServiceOrderDataGrid::semaforo($linha(['prazo_vence_em' => '2026-11-18 14:00:00', 'prazo_aberta_em' => '2026-11-18 08:00:00', 'prazo_nivel' => 0]));

    expect($vencida)->toContain('Vencida')
        ->and($aVencer)->toContain('A vencer')
        ->and($noPrazo)->toContain('No prazo');
});
