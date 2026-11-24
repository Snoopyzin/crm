<?php

use Webkul\ServiceOrder\Models\BarrierInstance;
use Webkul\ServiceOrder\Models\BarrierRule;
use Webkul\ServiceOrder\Models\Colaborador;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Services\ModoTeste;
use Webkul\ServiceOrder\Services\PilotoRequisitos;
use Webkul\ServiceOrder\Services\StageAdvanceService;
use Webkul\ServiceOrder\Services\WorkshopSettings;
use Webkul\User\Models\Role;

/**
 * A5 (condições para ligar o piloto) e A6 (modo de teste ÷ 10 e OS sem diagnóstico) do roteiro do patrão.
 */
function pilotoDadosGerais(array $extra = []): array
{
    return array_merge([
        'silencio_inicio' => '21:00', 'silencio_fim' => '06:00', 'agrupar_alertas_minutos' => 5,
        'sms_provedor' => 'log', 'sms_limite_mensal' => 100, 'sms_custo_unitario' => 0.1, 'sms_nao_aberto_minutos' => 10,
        'tecnico_margem_percentual' => 120, 'tecnico_meta_eficiencia' => 85,
    ], $extra);
}

function pilotoEntrarComoDono(): void
{
    $dono = test()->makeUser('proprietario');
    $dono->update(['role_id' => Role::create(['name' => 'Admin teste', 'permission_type' => 'all', 'permissions' => null])->id]);

    test()->actingAs($dono->refresh(), 'user');
}

// ---- A5: o que precisa estar preenchido para ligar o piloto ----------------------------------

it('A5: ligar o piloto com a Ficha incompleta NÃO liga e mostra a lista do que falta', function () {
    pilotoEntrarComoDono();

    $this->put(route('admin.workshop.settings.general'), pilotoDadosGerais(['piloto_ativo' => 1, 'agrupar_alertas_minutos' => 9]))
        ->assertRedirect()
        ->assertSessionHas('warning');

    $aviso = session('warning');

    expect($aviso)->toContain('NÃO foi ligado')
        ->toContain('Valor da hora')
        ->toContain('Impostos')
        ->toContain('Data de início da mentoria')
        ->toContain('Gerente ou Proprietário');

    WorkshopSettings::flush();

    // O piloto continua desligado, mas o resto das configurações foi salvo.
    expect(WorkshopSettings::get('piloto_ativo'))->toBeFalse()
        ->and(WorkshopSettings::get('agrupar_alertas_minutos'))->toBe(9);

    // A tela mostra a mesma lista, com link para onde preencher.
    $this->get(route('admin.workshop.settings.edit'))
        ->assertOk()
        ->assertSee('Para ligar o piloto, falta:')
        ->assertSee(route('admin.ficha.edit'), false);
});

it('A5: com valor da hora, impostos, início da mentoria e um gerente ativo o piloto liga', function () {
    pilotoEntrarComoDono();
    $this->completeFichaForPilot();

    $this->put(route('admin.workshop.settings.general'), pilotoDadosGerais(['piloto_ativo' => 1]))
        ->assertRedirect()
        ->assertSessionMissing('warning');

    WorkshopSettings::flush();

    expect(WorkshopSettings::get('piloto_ativo'))->toBeTrue();
});

it('A5: piloto que já está ligado nunca é desligado por falta de requisito; desmarcar desliga sem exigir nada', function () {
    pilotoEntrarComoDono();
    WorkshopSettings::set('piloto_ativo', true);

    expect(PilotoRequisitos::faltando())->not->toBeEmpty();

    $this->put(route('admin.workshop.settings.general'), pilotoDadosGerais(['piloto_ativo' => 1]))->assertRedirect()->assertSessionMissing('warning');
    WorkshopSettings::flush();
    expect(WorkshopSettings::get('piloto_ativo'))->toBeTrue();

    $this->put(route('admin.workshop.settings.general'), pilotoDadosGerais())->assertRedirect();
    WorkshopSettings::flush();
    expect(WorkshopSettings::get('piloto_ativo'))->toBeFalse();
});

it('A5: cada requisito é conferido sozinho', function () {
    $chaves = fn () => collect(PilotoRequisitos::faltando())->pluck('chave')->all();

    expect($chaves())->toBe(['valor_hora_mao_obra', 'pct_impostos', 'inicio_mentoria', 'colaborador_direcao']);

    WorkshopSettings::set('valor_hora_mao_obra', 0);
    expect($chaves())->toContain('valor_hora_mao_obra');
    WorkshopSettings::set('valor_hora_mao_obra', 150);

    // Impostos 0% é um valor válido; só "em branco" não vale.
    WorkshopSettings::set('pct_impostos', 0);
    expect($chaves())->not->toContain('pct_impostos');

    WorkshopSettings::set('inicio_mentoria', '2026-11-01');

    // Técnico, ou gerente desligado, não conta; proprietário ativo conta.
    Colaborador::create(['nome' => 'Técnico', 'cargos' => 'tecnico', 'classificacao' => 'oficina', 'ativo' => true]);
    Colaborador::create(['nome' => 'Gerente que saiu', 'cargos' => 'gerente', 'classificacao' => 'administracao', 'ativo' => false]);
    expect($chaves())->toBe(['colaborador_direcao']);

    Colaborador::create(['nome' => 'Dono', 'cargos' => 'proprietario', 'classificacao' => 'administracao', 'ativo' => true]);
    expect($chaves())->toBe([])
        ->and(PilotoRequisitos::completo())->toBeTrue();
});

// ---- A6: modo de teste das barreiras ---------------------------------------------------------

it('A6: modo de teste divide os prazos por 10 (2 h vira 12 min) e desligado devolve o prazo real', function () {
    $this->enablePilot([], ['orcamento_2h']);

    $os = $this->makeOs();
    $this->at('2026-11-18 09:00:00'); // quarta-feira, dentro do expediente
    ServiceOrderEvent::record($os, 'diagnostico_fim');

    $real = BarrierInstance::where('service_order_id', $os->id)->first();
    expect($real->vence_em->format('H:i'))->toBe('11:00')
        ->and($real->nivel2_em->format('H:i'))->toBe('12:00')
        ->and($real->nivel3_em->format('H:i'))->toBe('13:00');

    // Com o modo de teste ligado: 2 h = 12 min; +1 h = +6 min; +2 h = +12 min.
    WorkshopSettings::set('modo_teste_barreiras', true);

    $outra = $this->makeOs();
    ServiceOrderEvent::record($outra, 'diagnostico_fim');

    $teste = BarrierInstance::where('service_order_id', $outra->id)->first();
    expect($teste->vence_em->format('H:i'))->toBe('09:12')
        ->and($teste->nivel2_em->format('H:i'))->toBe('09:18')
        ->and($teste->nivel3_em->format('H:i'))->toBe('09:24');

    // A instância que já existia mantém o prazo real; o modo vale para as próximas.
    expect($real->fresh()->vence_em->format('H:i'))->toBe('11:00');
});

it('A6: o modo de teste só vale com o piloto ligado, por oficina, e desligá-lo não mexe em nenhum dado', function () {
    WorkshopSettings::set('modo_teste_barreiras', true);

    expect(ModoTeste::ativo())->toBeFalse()
        ->and(ModoTeste::minutos(120))->toBe(120)
        ->and(ModoTeste::selo())->toBeNull();

    $this->enablePilot();

    expect(ModoTeste::ativo())->toBeTrue()
        ->and(ModoTeste::minutos(120))->toEqual(12)
        ->and(ModoTeste::selo())->toBe('MODO DE TESTE (prazos ÷ 10)')
        ->and(ModoTeste::duracao(ModoTeste::minutos(1440)))->toBe('2 h 24 min')
        ->and(ModoTeste::duracao(144))->toBe('2 h 24 min')
        ->and(ModoTeste::numero(4.5))->toBe('4,5')
        ->and(ModoTeste::numero(60.0))->toBe('60');

    // Tempo padrão dos serviços: sub-opção separada, desligada por padrão.
    expect(ModoTeste::tempoPadraoAtivo())->toBeFalse();
    WorkshopSettings::set('modo_teste_tempo_padrao', true);
    expect(ModoTeste::tempoPadraoAtivo())->toBeTrue();

    // O prazo gravado na regra continua sendo o real.
    expect((int) BarrierRule::where('codigo', 'orcamento_2h')->value('prazo_minutos'))->toBe(120);
});

it('A6: a tela grava o modo de teste e o selo aparece em todas as telas enquanto ele estiver ligado', function () {
    pilotoEntrarComoDono();
    $this->completeFichaForPilot();

    $this->put(route('admin.workshop.settings.general'), pilotoDadosGerais(['piloto_ativo' => 1, 'modo_teste_barreiras' => 1]))->assertRedirect();
    WorkshopSettings::flush();

    expect(WorkshopSettings::get('modo_teste_barreiras'))->toBeTrue();

    $this->get(route('admin.workshop.settings.edit'))->assertOk()->assertSee('MODO DE TESTE (prazos ÷ 10)');

    $this->put(route('admin.workshop.settings.general'), pilotoDadosGerais(['piloto_ativo' => 1]))->assertRedirect();
    WorkshopSettings::flush();

    expect(WorkshopSettings::get('modo_teste_barreiras'))->toBeFalse();

    $this->get(route('admin.workshop.settings.edit'))->assertOk()->assertDontSee('MODO DE TESTE (prazos');
});

it('A6 (teste do PDF): com o piloto ligado, uma OS sem mecânico e sem diagnóstico NÃO avança para Orçamento', function () {
    $this->enablePilot();

    // Sem técnico designado a OS nem entra no Diagnóstico...
    $ordem = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_ORDEM_SERVICO]);
    $resultado = app(StageAdvanceService::class)->advance($ordem, ServiceOrder::STAGE_DIAGNOSTICO);

    expect($resultado['avancou'])->toBeFalse()
        ->and($resultado['faltando'])->toContain('Técnico designado');

    // ...e, sem laudo, visto do chefe e relação de peças, não sai do Diagnóstico para o Orçamento.
    $diagnostico = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_DIAGNOSTICO]);
    $resultado = app(StageAdvanceService::class)->advance($diagnostico, ServiceOrder::STAGE_ORCAMENTO);

    expect($resultado['avancou'])->toBeFalse()
        ->and($resultado['faltando'])->toContain('Laudo do diagnóstico')
        ->and($diagnostico->refresh()->workshop_status)->toBe(ServiceOrder::STAGE_DIAGNOSTICO);
});

it('A6: restaurar as barreiras padrão recria só as que faltam e não mexe no prazo que a oficina editou', function () {
    pilotoEntrarComoDono();
    $this->enablePilot([], 'todas');

    BarrierRule::where('codigo', 'orcamento_2h')->delete();
    $editada = BarrierRule::where('codigo', '!=', 'orcamento_2h')->whereNotNull('prazo_minutos')->first();
    $editada->update(['prazo_minutos' => 77]);

    $this->post(route('admin.workshop.settings.restore_rules'))->assertRedirect();

    expect(BarrierRule::where('codigo', 'orcamento_2h')->exists())->toBeTrue()
        ->and((int) $editada->fresh()->prazo_minutos)->toBe(77);
});
