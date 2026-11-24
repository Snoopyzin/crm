<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Webkul\ServiceOrder\Models\AlcadaPedido;
use Webkul\ServiceOrder\Models\BonusMarcador;
use Webkul\ServiceOrder\Models\IndicatorDefinition;
use Webkul\ServiceOrder\Models\MentoriaRegistro;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Models\ServiceOrderItem;
use Webkul\ServiceOrder\Models\WorkshopAlert;
use Webkul\ServiceOrder\Services\BarrierEngine;
use Webkul\ServiceOrder\Services\PeriodicAlerts;
use Webkul\ServiceOrder\Services\Rotina\RotinaService;
use Webkul\ServiceOrder\Services\WorkshopSettings;
use Webkul\User\Models\User;

beforeEach(function () {
    IndicatorDefinition::forgetSeeded();
    BonusMarcador::forgetSeeded();
});

function papel(string $perfil, array $permissoes = []): User
{
    return test()->makeUser($perfil, array_merge(['rotina_patio', 'rotina_painel', 'rotina_agenda', 'service_orders', 'service_orders.view'], $permissoes));
}

it('quadro de pátio: caminhões por etapa, tempo na etapa e a cor da pior barreira', function () {
    $this->enablePilot([], ['orcamento_2h']);
    $engine = app(BarrierEngine::class);

    $atrasado = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_ORCAMENTO]);
    $noPrazo = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_ORCAMENTO]);
    $diagnostico = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_DIAGNOSTICO]);

    ServiceOrderEvent::record($atrasado, 'diagnostico_fim', [], null, null, $this->at('2026-11-18 09:00:00'));
    ServiceOrderEvent::record($noPrazo, 'diagnostico_fim', [], null, null, $this->at('2026-11-18 10:30:00'));
    $engine->tick($this->at('2026-11-18 11:05:00'));

    DB::table('service_order_events')->where('service_order_id', $diagnostico->id)->where('tipo_evento', 'mudanca_etapa')->update(['ocorrido_em' => '2026-11-14 10:00:00']);

    $etapas = collect(app(RotinaService::class)->quadroDePatio())->keyBy('etapa');

    $orcamento = collect($etapas[ServiceOrder::STAGE_ORCAMENTO]['caminhoes'])->keyBy('os');

    expect(array_keys($etapas->all()))->toBe(ServiceOrder::stagesFlow())
        ->and($orcamento[$atrasado->id]['cor'])->toBe('vermelho')
        ->and($orcamento[$noPrazo->id]['cor'])->toBe('verde')
        ->and($etapas[ServiceOrder::STAGE_DIAGNOSTICO]['caminhoes'][0]['dias_parado'])->toBe(4);

    $this->actingAs(papel('gerente'), 'user')->get(route('admin.rotina.patio'))->assertOk()->assertSee('Quadro de pátio')->assertSee('Diagnóstico');
});

it('painel do chefe: diagnósticos a auditar, operações acima do tempo, test drives pendentes e auditoria da semana', function () {
    $this->enablePilot();
    $chefe = papel('chefe');
    $this->at('2026-11-18 11:00:00');

    $aAuditar = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_DIAGNOSTICO]);
    $aAuditar->forceFill(['diagnostico_concluido_em' => now()->subMinutes(75)])->save();

    $teste = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_VEICULO_TESTE]);

    $secoes = collect(app(RotinaService::class)->doChefe())->keyBy('titulo');

    expect($secoes['Diagnósticos a auditar']['itens'])->toHaveCount(1)
        ->and($secoes['Diagnósticos a auditar']['itens'][0]['os'])->toBe($aAuditar->id)
        // 75 min de um limite de 60: atrasado.
        ->and($secoes['Diagnósticos a auditar']['itens'][0]['cor'])->toBe('vermelho')
        ->and($secoes['Test drives pendentes']['itens'][0]['texto'])->toBe('test drive pendente')
        ->and($secoes['Auditoria semanal de box e postura']['itens'])->toHaveCount(1);

    MentoriaRegistro::record('auditoria_box', ['valor' => 8], now());
    $depois = collect(app(RotinaService::class)->doChefe())->keyBy('titulo');

    expect($depois['Auditoria semanal de box e postura']['itens'])->toBe([]);

    $this->actingAs($chefe, 'user')->get(route('admin.rotina.painel'))->assertOk()->assertSee('Diagnósticos a auditar')->assertSee('#'.$aAuditar->id);
});

it('painel de peças: cronômetro de 20 min (estoque) ou 40 min (fornecedor), compras do dia e entregas', function () {
    $this->enablePilot();
    $this->at('2026-11-18 10:00:00');
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_NEGOCIACAO]);
    $os->forceFill(['relacao_pecas_em' => now()->subMinutes(35), 'aprovacao_em' => now()->subHours(2), 'aprovacao_tipo' => 'total'])->save();

    $criar = function (string $nome, array $extra) use ($os) {
        $item = new ServiceOrderItem;
        $item->forceFill(array_merge(['quote_id' => $os->id, 'product_id' => 0, 'tipo' => 'Peça', 'name' => $nome, 'price' => 0, 'quantity' => 1, 'total' => 0], $extra))->save();

        return $item;
    };

    $criar('Filtro', ['origem_preco' => 'estoque']);
    $criar('Turbina', ['origem_preco' => 'fornecedor']);

    $secoes = collect(app(RotinaService::class)->dePecas())->keyBy('titulo');
    $precificar = collect($secoes['Pedidos a precificar']['itens'])->keyBy('texto');

    // 35 min: o filtro (limite 20) já passou; a turbina (limite 40) está a 87% do prazo.
    expect($precificar['Filtro']['cor'])->toBe('vermelho')
        ->and($precificar['Turbina']['cor'])->toBe('amarelo')
        ->and($precificar['Filtro']['limite'])->toBe('20 min')
        ->and($secoes['Compras do dia']['itens'])->toHaveCount(1)
        ->and($secoes['Compras do dia']['itens'][0]['texto'])->toBe('Turbina (comprar hoje)');
});

it('painel do financeiro e do consultor: créditos em 30 min, orçamentos a enviar em 2 h, sem resposta e diária de pátio', function () {
    $this->enablePilot();
    $this->at('2026-11-18 12:00:00');
    WorkshopSettings::set('diaria_patio_valor', 150);
    WorkshopSettings::set('diaria_patio_horas_nao_autorizado', 48);
    $consultor = papel('consultor', ['ver_valores_financeiros', 'service_orders.edit']);

    $semCredito = $this->makeOs(['user_id' => $consultor->id]);
    DB::table('quotes')->where('id', $semCredito->id)->update(['created_at' => now()->subMinutes(26)]);

    $paraEnviar = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_ORCAMENTO, 'user_id' => $consultor->id]);
    $paraEnviar->forceFill(['diagnostico_concluido_em' => now()->subMinutes(100)])->save();

    $semResposta = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_NEGOCIACAO, 'user_id' => $consultor->id]);
    $semResposta->forceFill(['orcamento_enviado_em' => now()->subHours(60)])->save();

    $financeiro = collect(app(RotinaService::class)->doFinanceiro())->keyBy('titulo');
    $creditos = collect($financeiro['Créditos a analisar (30 min)']['itens'])->keyBy('os');

    expect($creditos[$semCredito->id]['cor'])->toBe('amarelo');

    $consultorSecoes = collect(app(RotinaService::class)->doConsultor($consultor))->keyBy('titulo');

    expect($consultorSecoes['Orçamentos a enviar (2 h)']['itens'][0]['cor'])->toBe('amarelo')
        ->and($consultorSecoes['Orçamentos sem resposta há 48 h']['itens'][0]['os'])->toBe($semResposta->id)
        // 60 h depois do envio, com 48 h de carência: 12 h além = 1 dia de diária.
        ->and($consultorSecoes['Diária de pátio a cobrar']['itens'][0]['texto'])->toContain('orçamento não autorizado')->toContain('1 dia(s) de diária');

    $diarias = app(RotinaService::class)->diariasDePatio();

    expect($diarias)->toHaveCount(1)
        ->and($diarias[0]['valor'])->toBe(150.0);

    // Lançar a diária na OS: vira um serviço, uma única vez.
    $this->actingAs($consultor, 'user')->post(route('admin.rotina.diaria', $semResposta->id))->assertRedirect()->assertSessionHas('success');

    expect(ServiceOrderItem::where('quote_id', $semResposta->id)->where('name', 'like', 'Diária de pátio%')->count())->toBe(1);

    $this->post(route('admin.rotina.diaria', $semResposta->id))->assertSessionHas('error');
});

it('diária de pátio só é lançada por quem vê valores', function () {
    $this->enablePilot();
    $this->at('2026-11-18 12:00:00');
    WorkshopSettings::set('diaria_patio_valor', 150);
    WorkshopSettings::set('diaria_patio_horas_nao_autorizado', 24);
    $tecnico = papel('tecnico');
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_NEGOCIACAO]);
    $os->forceFill(['orcamento_enviado_em' => now()->subDays(3)])->save();

    $this->actingAs($tecnico, 'user')->post(route('admin.rotina.diaria', $os->id))->assertForbidden();

    expect(ServiceOrderItem::where('quote_id', $os->id)->count())->toBe(0);
});

it('painel do gerente: vermelhos, fila de alçadas, RH a vencer e pendências', function () {
    $this->enablePilot();
    $this->at('2026-11-18 12:00:00');
    MentoriaRegistro::record('celular_execucao', [], now());
    MentoriaRegistro::record('celular_execucao', [], now()->addMinute());
    AlcadaPedido::create(['tema' => 'desconto', 'descricao' => 'x', 'nivel_necessario' => 'gerente', 'status' => 'pendente']);
    DB::table('cargos_pendentes')->insert(['cargo' => 'chefe', 'acao' => 'contratar', 'prazo' => '2026-11-10', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('colaboradores')->insert(['nome' => 'Zé', 'cargos' => 'tecnico', 'ativo' => 1, 'classificacao' => 'oficina', 'exame_periodico_vencimento' => '2026-11-25', 'created_at' => now(), 'updated_at' => now()]);

    $secoes = collect(app(RotinaService::class)->doGerente())->keyBy('titulo');

    expect(collect($secoes['Indicadores vermelhos do mês']['itens'])->pluck('texto')->implode(' '))->toContain('TEC-14')
        ->and($secoes['Fila de alçadas']['itens'])->toHaveCount(1)
        ->and($secoes['RH a vencer (30 dias)']['itens'][0]['texto'])->toContain('Zé: exame periódico vence em 25/11/2026')
        ->and($secoes['Pendências de enquadramento']['itens'][0]['cor'])->toBe('vermelho');
});

it('zeladoria: checklist de um toque grava o registro com o usuário e conta no dia', function () {
    $zeladora = papel('zeladoria');
    $this->at('2026-11-18 14:00:00');
    $this->actingAs($zeladora, 'user');

    $this->post(route('admin.rotina.checklist'), ['tipo' => 'zel_limpeza'])->assertSessionHas('success');
    $this->post(route('admin.rotina.checklist'), ['tipo' => 'zel_banheiro'])->assertSessionHas('success');
    $this->post(route('admin.rotina.checklist'), ['tipo' => 'zel_banheiro'])->assertSessionHas('success');
    $this->post(route('admin.rotina.checklist'), ['tipo' => 'outro'])->assertSessionHas('error');

    $registro = MentoriaRegistro::where('tipo', 'zel_limpeza')->first();

    expect($registro->ok)->toBeTrue()->and($registro->user_id)->toBe($zeladora->id);

    $lista = collect(app(RotinaService::class)->daZeladoria()[0]['checklists'])->keyBy('tipo');

    expect($lista['zel_banheiro']['feitos'])->toBe(2)
        ->and($lista['zel_banheiro']['previstos'])->toBe(3);

    $this->get(route('admin.rotina.painel'))->assertOk()->assertSee('Meus checklists de hoje')->assertSee('2 de 3 hoje');
});

it('agenda: capacidade do dia, aviso acima de 80%, bloqueio na capacidade cheia e Chegou leva ao cadastro', function () {
    WorkshopSettings::set('agenda_capacidade_dia', 5);
    $consultor = papel('consultor');
    $this->actingAs($consultor, 'user');
    $this->at('2026-11-17 10:00:00');
    $servico = app(RotinaService::class);

    expect($servico->capacidadeDoDia())->toBe(5);

    foreach (range(1, 4) as $i) {
        $servico->agendar(['agendado_para' => sprintf('2026-11-18 %02d:00', 7 + $i), 'cliente' => "Cliente {$i}"], $consultor);
    }

    // O 5º (acima de 80% de 5 = 4) leva aviso, mas é agendado.
    $quinto = $servico->agendar(['agendado_para' => '2026-11-18 14:00', 'cliente' => 'Cliente 5'], $consultor);

    expect($quinto['aviso'])->toContain('80% da capacidade')
        ->and($quinto['ocupacao'])->toBe(100.0)
        ->and(fn () => $servico->agendar(['agendado_para' => '2026-11-18 15:00', 'cliente' => 'Cliente 6', 'encaixe' => true], $consultor))->toThrow(ValidationException::class);

    $this->get(route('admin.rotina.agenda', ['dia' => '2026-11-18']))->assertOk()->assertSee('Agenda de 18/11/2026')->assertSee('Cliente 3')->assertSee('100%');

    $this->post(route('admin.rotina.agendar'), ['agendado_para' => '2026-11-19 09:00', 'cliente' => 'Transportes Sul', 'placa' => 'abc1d23', 'queixa' => 'Barulho no câmbio'])->assertRedirect(route('admin.rotina.agenda', ['dia' => '2026-11-19']));

    $agendamento = DB::table('agendamentos')->where('cliente', 'Transportes Sul')->first();

    expect($agendamento->placa)->toBe('ABC1D23');

    $this->post(route('admin.rotina.agenda.status', $agendamento->id), ['status' => 'chegou'])
        ->assertRedirect(route('admin.service_orders.cadastro.create', ['placa' => 'ABC1D23', 'name' => 'Transportes Sul']));

    expect(DB::table('agendamentos')->find($agendamento->id)->status)->toBe('chegou');

    // Data no passado é recusada.
    $this->post(route('admin.rotina.agendar'), ['agendado_para' => '2026-11-01 09:00', 'cliente' => 'X'])->assertSessionHasErrors('agendado_para');
});

it('resumo opcional ao dono (desligado por padrão): barreiras vencidas a cada 2 horas, sem valores', function () {
    $this->enablePilot([], ['orcamento_2h']);
    $gerente = $this->makeUser('gerente');
    $dono = $this->makeUser('proprietario');
    $os = $this->makeOs();
    ServiceOrderEvent::record($os, 'diagnostico_fim', [], null, null, $this->at('2026-11-18 09:00:00'));
    app(BarrierEngine::class)->tick($this->at('2026-11-18 11:00:00'));

    $periodicos = app(PeriodicAlerts::class);

    // Desligado: nada para o dono.
    expect($periodicos->resumoDoDono($this->at('2026-11-18 11:00:00')))->toBe(0)
        ->and(WorkshopAlert::where('user_id', $dono->id)->where('barrier_code', 'resumo_barreiras')->count())->toBe(0);

    WorkshopSettings::set('dono_resumo_barreiras', true);

    expect($periodicos->resumoDoDono($this->at('2026-11-18 11:05:00')))->toBe(1);

    $resumo = WorkshopAlert::where('user_id', $dono->id)->where('barrier_code', 'resumo_barreiras')->first();

    expect($resumo->mensagem)->toContain('RESUMO - 1 barreira(s) vencida(s)')->toContain('Orçamento em 2 horas: 1')->not->toContain('R$');

    // A cada 2 horas, não antes.
    expect($periodicos->resumoDoDono($this->at('2026-11-18 12:30:00')))->toBe(0)
        ->and($periodicos->resumoDoDono($this->at('2026-11-18 13:06:00')))->toBe(1);
});

it('registro de acesso: telas financeiras e do painel gravam quem abriu; a lista é consultável', function () {
    $this->enablePilot();
    $dono = $this->makeUser('proprietario', ['painel_dono', 'financeiro_dre', 'settings', 'settings.ficha', 'settings.ficha.editar']);
    $this->actingAs($dono, 'user');

    $this->get(route('admin.mentoria.painel', ['periodo' => '2026-11']))->assertOk();
    $this->get(route('admin.financeiro.dre', ['periodo' => '2026-11']))->assertOk();
    $this->get(route('admin.service_orders.index'))->assertForbidden();

    $rotas = DB::table('acessos_sensiveis')->where('user_id', $dono->id)->pluck('rota')->all();

    expect($rotas)->toContain('admin.mentoria.painel', 'admin.financeiro.dre')
        ->and($rotas)->not->toContain('admin.service_orders.index');

    $this->get(route('admin.ficha.acessos'))->assertOk()->assertSee('Registro de acesso')->assertSee('admin.financeiro.dre');
});

it('PDF do Painel do Dono para o encontro de mentoria', function () {
    $this->enablePilot();
    $dono = $this->makeUser('proprietario', ['painel_dono']);
    $this->actingAs($dono, 'user');

    $resposta = $this->get(route('admin.mentoria.painel.pdf', ['periodo' => '2026-11']));

    $resposta->assertOk();
    expect($resposta->headers->get('Content-Type'))->toContain('pdf')
        ->and($resposta->headers->get('Content-Disposition'))->toContain('painel-do-dono-2026-11.pdf')
        ->and(substr($resposta->getContent(), 0, 4))->toBe('%PDF');
});

it('rotas de rotina barram quem não tem a permissão', function () {
    $semAcesso = $this->makeUser('consultor', ['service_orders']);
    $this->actingAs($semAcesso, 'user');

    foreach (['patio', 'painel', 'agenda'] as $rota) {
        $this->get(route('admin.rotina.'.$rota))->assertForbidden();
    }

    $this->post(route('admin.rotina.checklist'), ['tipo' => 'zel_limpeza'])->assertForbidden();
});
