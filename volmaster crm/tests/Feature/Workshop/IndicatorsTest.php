<?php

use Webkul\ServiceOrder\Models\IndicatorDefinition;
use Webkul\ServiceOrder\Models\MentoriaRegistro;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Services\BarrierEngine;
use Webkul\ServiceOrder\Services\BusinessHours;
use Webkul\ServiceOrder\Services\Indicators\IndicatorCalculator;
use Webkul\ServiceOrder\Services\Indicators\IndicatorCatalog;
use Webkul\ServiceOrder\Services\Indicators\IndicatorService;
use Webkul\ServiceOrder\Services\Indicators\IndicatorStatus;
use Webkul\ServiceOrder\Services\OperationService;
use Webkul\ServiceOrder\Services\WorkshopSettings;

const MES = '2026-11';

function indicador(string $codigo, array $scope = [], string $periodo = MES): array
{
    return app(IndicatorService::class)->evaluate($codigo, $periodo, $scope);
}

function realizado(string $codigo, array $scope = []): ?float
{
    return indicador($codigo, $scope)['resultado']->realizado;
}

beforeEach(function () {
    IndicatorDefinition::forgetSeeded();
    WorkshopSettings::flush();
});

it('semeia o catálogo da mentoria com fórmula, meta, tipo e quem valida', function () {
    $service = app(IndicatorService::class);
    $todos = $service->definitions(apenasAtivos: false);

    expect($todos)->toHaveCount(count(IndicatorCatalog::all()))
        ->and($todos->count())->toBeGreaterThanOrEqual(110);

    $cargos = $todos->groupBy('cargo')->map->count();

    expect($cargos['consultor'])->toBe(17)
        ->and($cargos['chefe'])->toBe(16)
        ->and($cargos['tecnico'])->toBe(15)
        ->and($cargos['pecas'])->toBe(13)
        ->and($cargos['financeiro'])->toBe(13)
        ->and($cargos['gerente'])->toBe(20)
        ->and($cargos['zeladoria'])->toBe(6)
        ->and($cargos['dono'])->toBe(6)
        ->and($cargos['dre'])->toBe(10);

    $con04 = $service->definition('CON-04');

    expect($con04->nome)->toBe('Orçamento em até 2 h')
        ->and($con04->tipo_meta)->toBe('min')
        ->and($con04->meta())->toBe(90.0)
        ->and($con04->valida)->toBe('Gerente')
        ->and($service->definition('CHE-10')->tipo_meta)->toBe('teto')
        ->and($service->definition('TEC-14')->tipo_meta)->toBe('zero')
        ->and($service->definition('TEC-13')->tipo_meta)->toBe('nota');
});

it('situação: no alvo, atenção (80% / 1,5× / uma ocorrência), abaixo, e sem cor com meta em branco', function () {
    expect(IndicatorStatus::of('min', 90, 90))->toBe('verde')
        ->and(IndicatorStatus::of('min', 90, 72))->toBe('amarelo')
        ->and(IndicatorStatus::of('min', 90, 71.9))->toBe('vermelho')
        ->and(IndicatorStatus::of('teto', 2, 2))->toBe('verde')
        ->and(IndicatorStatus::of('teto', 2, 3))->toBe('amarelo')
        ->and(IndicatorStatus::of('teto', 2, 3.1))->toBe('vermelho')
        ->and(IndicatorStatus::of('zero', 0, 0))->toBe('verde')
        ->and(IndicatorStatus::of('zero', 0, 1))->toBe('amarelo')
        ->and(IndicatorStatus::of('zero', 0, 2))->toBe('vermelho')
        ->and(IndicatorStatus::of('nota', 8, 6.4))->toBe('amarelo')
        ->and(IndicatorStatus::of('min', null, 50))->toBe('sem_meta')
        ->and(IndicatorStatus::of('min', 90, null))->toBe('sem_dado')
        ->and(IndicatorStatus::fraction('verde'))->toBe(1.0)
        ->and(IndicatorStatus::fraction('amarelo'))->toBe(0.5)
        ->and(IndicatorStatus::fraction('vermelho'))->toBe(0.0)
        ->and(IndicatorStatus::fraction('sem_dado'))->toBeNull();
});

it('meta da empresa fica sem cor até o dono preencher; mudar a meta muda a cor sem mexer no código', function () {
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_POS_VENDA]);
    $os->forceFill(['nota_recomendacao' => 7])->save();
    ServiceOrderEvent::record($os, 'nota_cliente', ['nota' => 7], null, null, $this->at('2026-11-18 10:00:00'));

    // CON-15 (nota de recomendação) é "meta da empresa".
    expect(realizado('CON-15'))->toBe(7.0)
        ->and(indicador('CON-15')['situacao'])->toBe('sem_meta');

    IndicatorDefinition::whereKey('CON-15')->update(['meta_oficina' => 8]);
    app()->forgetInstance(IndicatorService::class);

    expect(indicador('CON-15')['meta'])->toBe(8.0);

    $service = new IndicatorService(app(IndicatorCalculator::class));

    expect($service->evaluate('CON-15', MES)['situacao'])->toBe('amarelo');

    IndicatorDefinition::whereKey('CON-15')->update(['meta_oficina' => 7]);
    $outro = new IndicatorService(app(IndicatorCalculator::class));

    expect($outro->evaluate('CON-15', MES)['situacao'])->toBe('verde');
});

it('critério 5 do indicador: CON-04 conta o orçamento fora do prazo e bate com a conta à mão', function () {
    $this->enablePilot([], ['orcamento_2h']);
    $this->makeUser('consultor');
    $engine = app(BarrierEngine::class);

    $dentro = $this->makeOs();
    $fora = $this->makeOs();
    $semResposta = $this->makeOs();

    foreach ([$dentro, $fora, $semResposta] as $os) {
        ServiceOrderEvent::record($os, 'diagnostico_fim', [], null, null, $this->at('2026-11-18 09:00:00'));
    }

    ServiceOrderEvent::record($dentro, 'orcamento_enviado', ['canal' => 'WhatsApp'], null, null, $this->at('2026-11-18 10:30:00'));

    $engine->tick($this->at('2026-11-18 11:00:00'));
    ServiceOrderEvent::record($fora, 'orcamento_enviado', ['canal' => 'WhatsApp'], null, null, $this->at('2026-11-18 11:20:00'));

    // O terceiro segue sem orçamento e já venceu: também conta como fora do prazo.
    $this->at('2026-11-30 12:00:00');

    $resultado = indicador('CON-04')['resultado'];

    // 1 no prazo de 3 = 33,3%.
    expect($resultado->numerador)->toBe(1.0)
        ->and($resultado->denominador)->toBe(3.0)
        ->and($resultado->realizado)->toBe(33.3)
        ->and($resultado->osIds)->toEqualCanonicalizing([$dentro->id, $fora->id, $semResposta->id])
        ->and(indicador('CON-04')['situacao'])->toBe('vermelho');
});

it('CON-01 recepção e FIN-01 crédito leem a mesma barreira que gerou os alertas', function () {
    $this->enablePilot([], ['recepcao', 'cor_credito']);
    $engine = app(BarrierEngine::class);

    $rapida = $this->makeOs();
    $lenta = $this->makeOs();

    foreach ([$rapida, $lenta] as $os) {
        ServiceOrderEvent::record($os, 'chegada_veiculo', [], null, null, $this->at('2026-11-18 08:00:00'));
    }

    ServiceOrderEvent::record($rapida, 'recepcao', [], null, null, $this->at('2026-11-18 08:07:00'));
    ServiceOrderEvent::record($rapida, 'cor_credito_registrada', ['cor' => 'verde'], null, null, $this->at('2026-11-18 08:20:00'));
    $engine->tick($this->at('2026-11-18 09:00:00'));
    ServiceOrderEvent::record($lenta, 'recepcao', [], null, null, $this->at('2026-11-18 09:05:00'));
    ServiceOrderEvent::record($lenta, 'cor_credito_registrada', ['cor' => 'verde'], null, null, $this->at('2026-11-18 09:10:00'));
    $this->at('2026-11-30 12:00:00');

    expect(realizado('CON-01'))->toBe(50.0)
        ->and(realizado('FIN-01'))->toBe(50.0);
});

it('CHE-10 retrabalho: retornos por falha de execução ÷ OS entregues (teto 2%)', function () {
    $this->enablePilot();

    $entregues = collect(range(1, 4))->map(function () {
        $os = $this->makeOs();
        ServiceOrderEvent::record($os, 'saida_veiculo', ['sem_nf' => false], null, null, $this->at('2026-11-18 16:00:00'));

        return $os;
    });

    $retorno = $this->makeOs();
    $retorno->forceFill(['tipo_os' => 'retorno', 'os_origem_id' => $entregues[0]->id, 'retorno_causa' => 'execucao'])->save();

    $outroRetorno = $this->makeOs();
    $outroRetorno->forceFill(['tipo_os' => 'garantia', 'os_origem_id' => $entregues[1]->id, 'retorno_causa' => 'peca'])->save();

    // 1 retorno de execução em 4 entregas = 25%; o de peça não conta.
    expect(realizado('CHE-10'))->toBe(25.0)
        ->and(realizado('GER-05'))->toBe(25.0)
        ->and(indicador('CHE-10')['situacao'])->toBe('vermelho')
        ->and(realizado('CHE-14'))->toBe(100.0);
});

it('TEC-07 eficiência e TEC-08 aderência: serviço de 1 h feito em 1h30 rende 66,7% e fica fora da margem', function () {
    $this->enablePilot();
    $mecanico = $this->makeMechanic('Zé');
    $operacoes = app(OperationService::class);

    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_EM_EXECUCAO, 'mechanic_id' => $mecanico->id]);
    $lenta = $this->makeServiceItem($os, 1.0, $mecanico->id, 'Troca de embreagem');
    $boa = $this->makeServiceItem($os, 2.0, $mecanico->id, 'Alinhamento');

    $this->at('2026-11-18 08:00:00');
    $operacoes->start($lenta);
    $this->at('2026-11-18 09:30:00');
    $operacoes->finish($lenta, 'Serviço mais complexo que o previsto', 'obs');

    $this->at('2026-11-18 10:00:00');
    $operacoes->start($boa);
    $this->at('2026-11-18 11:50:00');
    $operacoes->finish($boa);

    // Padrão 3 h; reais 1h30 + 1h50 = 3h20 => 90,0%.
    expect(realizado('TEC-07', ['mechanic_id' => $mecanico->id]))->toBe(90.0)
        // Só a 2ª operação ficou dentro de 120% do padrão: 1 de 2.
        ->and(realizado('TEC-08', ['mechanic_id' => $mecanico->id]))->toBe(50.0)
        ->and(realizado('CHE-06'))->toBe(50.0)
        ->and(realizado('TEC-12', ['mechanic_id' => $mecanico->id]))->toBeNull();

    // Outro técnico não enxerga as operações do primeiro.
    $outro = $this->makeMechanic('Outro');
    expect(realizado('TEC-07', ['mechanic_id' => $outro->id]))->toBeNull();
});

it('CON-09 taxa de aprovação: valor aprovado ÷ valor orçado; e CON-12/CON-13 por OS', function () {
    $this->enablePilot();

    $aprovada = $this->makeOs();
    $aprovada->forceFill(['grand_total' => 8000, 'aprovacao_tipo' => 'total', 'termo_assinado_em' => now(), 'autorizacao_rodagem' => true])->save();
    $recusada = $this->makeOs();
    $recusada->forceFill(['grand_total' => 2000, 'aprovacao_tipo' => 'recusa'])->save();

    foreach ([$aprovada, $recusada] as $os) {
        ServiceOrderEvent::record($os, 'orcamento_enviado', ['canal' => 'WhatsApp'], null, null, $this->at('2026-11-18 10:00:00'));
        ServiceOrderEvent::record($os, 'chegada_veiculo', [], null, null, $this->at('2026-11-18 08:00:00'));
    }

    ServiceOrderEvent::record($recusada, 'cliente_recusou', ['meio' => 'WhatsApp', 'motivo' => 'Caro'], null, null, $this->at('2026-11-18 14:00:00'));
    ServiceOrderEvent::record($recusada, 'recontato_recusa', ['canal' => 'Telefone'], null, null, $this->at('2026-11-23 09:00:00'));

    expect(realizado('CON-09'))->toBe(80.0)
        ->and(realizado('CON-12'))->toBe(100.0)
        // Só uma das duas entradas tem termo com autorização.
        ->and(realizado('CON-13'))->toBe(50.0);
});

it('escopo: o indicador do consultor só conta as OS dele', function () {
    $this->enablePilot([], ['recepcao']);
    $ana = $this->makeUser('consultor');
    $beto = $this->makeUser('consultor');
    $engine = app(BarrierEngine::class);

    $daAna = $this->makeOs(['user_id' => $ana->id]);
    $doBeto = $this->makeOs(['user_id' => $beto->id]);

    foreach ([$daAna, $doBeto] as $os) {
        ServiceOrderEvent::record($os, 'chegada_veiculo', [], null, null, $this->at('2026-11-18 08:00:00'));
    }

    ServiceOrderEvent::record($daAna, 'recepcao', [], null, null, $this->at('2026-11-18 08:05:00'));
    $engine->tick($this->at('2026-11-18 09:00:00'));
    ServiceOrderEvent::record($doBeto, 'recepcao', [], null, null, $this->at('2026-11-18 09:00:00'));
    $this->at('2026-11-30 12:00:00');

    expect(realizado('CON-01', ['user_id' => $ana->id]))->toBe(100.0)
        ->and(realizado('CON-01', ['user_id' => $beto->id]))->toBe(0.0)
        ->and(realizado('CON-01'))->toBe(50.0);
});

it('auditoria com nota e ocorrências vêm de registros; correção cria novo registro e o original deixa de contar', function () {
    $mecanico = $this->makeMechanic('Zé');

    $primeiro = MentoriaRegistro::record('auditoria_box', ['mechanic_id' => $mecanico->id, 'valor' => 6], $this->at('2026-11-10 10:00:00'));
    MentoriaRegistro::record('auditoria_box', ['mechanic_id' => $mecanico->id, 'valor' => 9], $this->at('2026-11-17 10:00:00'));
    MentoriaRegistro::record('celular_execucao', ['mechanic_id' => $mecanico->id], $this->at('2026-11-12 10:00:00'));

    expect(realizado('TEC-13', ['mechanic_id' => $mecanico->id]))->toBe(7.5)
        ->and(realizado('TEC-14', ['mechanic_id' => $mecanico->id]))->toBe(1.0)
        ->and(indicador('TEC-14', ['mechanic_id' => $mecanico->id])['situacao'])->toBe('amarelo');

    expect(fn () => $primeiro->update(['valor' => 10]))->toThrow(LogicException::class)
        ->and(fn () => $primeiro->delete())->toThrow(LogicException::class)
        ->and(fn () => MentoriaRegistro::correct($primeiro, ['valor' => 8], ' '))->toThrow(InvalidArgumentException::class)
        ->and(fn () => MentoriaRegistro::record('tipo_inventado'))->toThrow(InvalidArgumentException::class);

    $correcao = MentoriaRegistro::correct($primeiro, ['valor' => 8], 'Nota digitada errada');

    app()->forgetInstance(IndicatorService::class);

    expect($correcao->corrige_id)->toBe($primeiro->id)
        ->and($correcao->justificativa)->toBe('Nota digitada errada')
        // O original (6) deixa de contar: média de 8 e 9.
        ->and(realizado('TEC-13', ['mechanic_id' => $mecanico->id]))->toBe(8.5)
        ->and(MentoriaRegistro::where('tipo', 'auditoria_box')->count())->toBe(3);
});

it('GER-01: indicadores no alvo ÷ indicadores com meta e dado', function () {
    $this->makeMechanic('Zé');
    MentoriaRegistro::record('celular_execucao', [], $this->at('2026-11-12 10:00:00'));
    MentoriaRegistro::record('celular_execucao', [], $this->at('2026-11-13 10:00:00'));
    app()->forgetInstance(IndicatorService::class);

    $linhas = app(IndicatorService::class)->month(MES);
    $comMeta = $linhas->filter(fn ($l) => in_array($l['situacao'], ['verde', 'amarelo', 'vermelho'], true))->except(['GER-01', 'GER-07']);
    $verdes = $comMeta->where('situacao', 'verde')->count();

    expect($linhas['GER-01']['resultado']->realizado)->toBe(round($verdes / $comMeta->count() * 100, 1))
        // Duas ocorrências de celular na execução: TEC-14 fica vermelho.
        ->and($linhas['TEC-14']['situacao'])->toBe('vermelho');
});

it('GER-07: indicador vermelho com plano de ação conta; sem plano não conta', function () {
    MentoriaRegistro::record('celular_execucao', [], $this->at('2026-11-12 10:00:00'));
    MentoriaRegistro::record('celular_execucao', [], $this->at('2026-11-13 10:00:00'));
    app()->forgetInstance(IndicatorService::class);

    $semPlano = app(IndicatorService::class)->evaluate('GER-07', MES)['resultado'];

    MentoriaRegistro::record('plano_acao', ['dados_json' => ['indicador' => 'TEC-14', 'prazo' => '2026-12-05']], $this->at('2026-11-14 10:00:00'));
    app()->forgetInstance(IndicatorService::class);

    $comPlano = app(IndicatorService::class)->evaluate('GER-07', MES)['resultado'];

    expect($comPlano->numerador)->toBe($semPlano->numerador + 1);
});

it('GER-06 reuniões: realizadas ÷ previstas (diárias + semanais + mensal) no mês fechado', function () {
    // Mês passado inteiro: 21 dias úteis (nov/2026: seg-sáb conforme o horário padrão).
    $this->at('2026-12-02 10:00:00');
    $dias = (new BusinessHours)->businessDaysBetween(Carbon\Carbon::parse('2026-11-01'), Carbon\Carbon::parse('2026-11-30'));
    $previstas = $dias + 5 + 1;

    foreach (range(1, 4) as $dia) {
        MentoriaRegistro::record('reuniao_diaria', ['ok' => true], Carbon\Carbon::parse("2026-11-0{$dia} 08:00"));
    }

    MentoriaRegistro::record('reuniao_mensal', ['ok' => true], Carbon\Carbon::parse('2026-11-28 17:00'));
    app()->forgetInstance(IndicatorService::class);

    $resultado = indicador('GER-06')['resultado'];

    expect($resultado->denominador)->toBe((float) $previstas)
        ->and($resultado->numerador)->toBe(5.0);
});

it('indicadores que dependem de módulos futuros devolvem "sem dado" em vez de erro', function () {
    foreach (['GER-03', 'DRE-01', 'DRE-06', 'DON-06'] as $codigo) {
        expect(realizado($codigo))->toBeNull();
    }
});
