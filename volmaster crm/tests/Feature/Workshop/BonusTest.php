<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Webkul\ServiceOrder\Models\BonusApuracao;
use Webkul\ServiceOrder\Models\BonusMarcador;
use Webkul\ServiceOrder\Models\BonusTrava;
use Webkul\ServiceOrder\Models\Colaborador;
use Webkul\ServiceOrder\Models\IndicatorDefinition;
use Webkul\ServiceOrder\Models\MentoriaRegistro;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Services\Bonus\BonusCatalog;
use Webkul\ServiceOrder\Services\Bonus\BonusService;
use Webkul\ServiceOrder\Services\Bonus\FechamentoService;
use Webkul\ServiceOrder\Services\Bonus\TravaService;
use Webkul\ServiceOrder\Services\BusinessHours;
use Webkul\ServiceOrder\Services\WorkshopSettings;

const PERIODO = '2026-10';

beforeEach(function () {
    IndicatorDefinition::forgetSeeded();
    BonusMarcador::forgetSeeded();
    // Estamos em novembro: outubro já está fechado para o calendário.
    test()->at('2026-11-02 10:00:00');
    WorkshopSettings::set('bonus_cheio_percentual', ['zeladoria' => 10, 'gerente' => 20, 'consultor' => 15, 'chefe' => 15]);
});

/** Zeladora com a rotina de outubro toda registrada. */
function zeladoraCompleta(array $lacunas = []): Colaborador
{
    $zeladora = Colaborador::create(['nome' => 'Dona Maria', 'cargos' => 'zeladoria', 'classificacao' => 'administracao', 'salario_base' => 2000, 'ativo' => true]);

    $dias = [];
    $dia = Carbon::parse('2026-10-01');

    while ($dia->month === 10) {
        if ((new BusinessHours)->isBusinessDay($dia)) {
            $dias[] = $dia->copy();
        }
        $dia->addDay();
    }

    foreach ($dias as $indice => $dia) {
        $ok = fn (string $tipo) => ! in_array($tipo.':'.$indice, $lacunas, true);

        MentoriaRegistro::record('zel_limpeza', ['ok' => $ok('limpeza')], $dia->copy()->setTime(18, 0));
        MentoriaRegistro::record('zel_cafe', ['ok' => true], $dia->copy()->setTime(9, 0));

        foreach (range(1, 3) as $vez) {
            MentoriaRegistro::record('zel_banheiro', ['ok' => true], $dia->copy()->setTime(8 + $vez * 2, 0));
        }
    }

    MentoriaRegistro::record('zel_auditoria', ['valor' => 9], Carbon::parse('2026-10-10 10:00'));

    return $zeladora;
}

it('traz os marcadores padrão do capítulo 6.2, cada fase somando 100 pontos', function () {
    foreach (BonusCatalog::marcadores() as $cargo => $fases) {
        foreach ($fases as $fase => $linhas) {
            expect(collect($linhas)->sum(fn ($l) => $l[1]))->toBe(100);
        }
    }

    BonusMarcador::ensureDefaults();

    expect(BonusMarcador::where('cargo', 'consultor')->where('fase', 1)->count())->toBe(6)
        ->and(BonusMarcador::where('cargo', 'zeladoria')->count())->toBe(6)
        ->and(BonusMarcador::where('cargo', 'zeladoria')->where('fase', 2)->count())->toBe(0)
        ->and(BonusMarcador::where('cargo', 'financeiro')->where('fase', 2)->where('codigos', 'FIN-12,FIN-13')->exists())->toBeTrue()
        ->and(BonusMarcador::where('cargo', 'gerente')->where('fase', 2)->where('codigos', 'GER-14,GER-15,GER-16')->exists())->toBeTrue()
        // Marcadores de resultado só na fase 2.
        ->and(BonusMarcador::where('fase', 1)->where('resultado', true)->count())->toBe(0)
        ->and(BonusMarcador::where('cargo', 'consultor')->where('fase', 2)->where('resultado', true)->pluck('codigos')->sort()->values()->all())->toBe(['CON-09', 'CON-10', 'CON-11']);
});

it('faixas de pagamento: 90+ paga 100%, 80+ paga 70%, 70+ paga 40%, abaixo zera', function () {
    $servico = app(BonusService::class);

    expect($servico->faixa(100))->toBe(100)
        ->and($servico->faixa(90))->toBe(100)
        ->and($servico->faixa(89.9))->toBe(70)
        ->and($servico->faixa(80))->toBe(70)
        ->and($servico->faixa(79.9))->toBe(40)
        ->and($servico->faixa(70))->toBe(40)
        ->and($servico->faixa(69.9))->toBe(0);
});

it('nota 100: zeladoria com a rotina completa recebe o valor cheio (salário × % do cargo × faixa)', function () {
    $zeladora = zeladoraCompleta();

    $calculo = app(BonusService::class)->calcular($zeladora, 'zeladoria', PERIODO);

    expect($calculo['nota'])->toBe(100.0)
        ->and($calculo['faixa_percentual'])->toBe(100)
        ->and($calculo['fase'])->toBe(1)
        ->and(collect($calculo['detalhe'])->pluck('peso')->sum())->toBe(100);

    $apuracoes = app(BonusService::class)->apurar(PERIODO);
    $apuracao = $apuracoes->first();

    // 2.000 × 10% × 100% = 200.
    expect($apuracoes)->toHaveCount(1)
        ->and($apuracao->valor)->toBe(200.0)
        ->and($apuracao->status)->toBe('calculado')
        ->and($apuracao->percentual_cheio)->toBe(10.0)
        ->and($apuracao->detalhe_json)->toHaveCount(6);
});

it('meio ponto: indicador em "atenção" vale metade do peso', function () {
    // Falta o checklist de limpeza em 2 dias: ~92% (≥ 80% da meta de 100%) => atenção.
    $zeladora = zeladoraCompleta(['limpeza:0', 'limpeza:1']);

    $calculo = app(BonusService::class)->calcular($zeladora, 'zeladoria', PERIODO);
    $limpeza = collect($calculo['detalhe'])->firstWhere('codigos', ['ZEL-01']);

    expect($limpeza['situacoes'])->toBe(['amarelo'])
        ->and($limpeza['pontos'])->toEqual(12.5)
        // 100 - 12,5 pontos perdidos.
        ->and($calculo['nota'])->toBe(87.5)
        ->and($calculo['faixa_percentual'])->toBe(70);
});

it('sem dado no mês o marcador sai e os pesos dos demais são reescalonados para 100', function () {
    $zeladora = zeladoraCompleta();
    // Sem auditoria do ambiente no mês: ZEL-03 (peso 25) fica sem dado.
    MentoriaRegistro::where('tipo', 'zel_auditoria')->get()->each(fn () => null);
    DB::table('mentoria_registros')->where('tipo', 'zel_auditoria')->delete();

    $calculo = app(BonusService::class)->calcular($zeladora, 'zeladoria', PERIODO);
    $auditoria = collect($calculo['detalhe'])->firstWhere('codigos', ['ZEL-03']);

    expect($auditoria['fracao'])->toBeNull()
        ->and($auditoria['pontos'])->toBeNull()
        ->and($calculo['nota'])->toBe(100.0)
        // 25 pontos saíram: os 75 restantes viram 100 (cada peso × 100/75).
        ->and(collect($calculo['detalhe'])->firstWhere('codigos', ['ZEL-01'])['peso_ajustado'])->toBe(33.3);
});

it('trava confirmada zera o bônus de qualquer nota; pendente e rejeitada não', function () {
    $zeladora = zeladoraCompleta();
    $gerente = test()->makeUser('gerente');
    $travas = app(TravaService::class);
    $bonus = app(BonusService::class);

    $bonus->apurar(PERIODO);

    $trava = $travas->registrar($zeladora, 'zeladoria', 'regra_seguranca_zel', 'Fumou no box', 'Foto de 12/10, 14h, box 2', PERIODO, $gerente);

    expect($trava->status)->toBe('pendente')
        ->and($trava->origem)->toBe('manual')
        ->and($bonus->calcular($zeladora, 'zeladoria', PERIODO)['zerado_por_trava'])->toBeFalse();

    $travas->decidir($trava, $gerente, true, 'Confirmado');

    $apuracao = BonusApuracao::first();

    // A apuração já calculada é refeita na hora da confirmação.
    expect($apuracao->nota)->toBe(100.0)
        ->and($apuracao->zerado_por_trava)->toBeTrue()
        ->and($apuracao->faixa_percentual)->toBe(0)
        ->and($apuracao->valor)->toBe(0.0);

    $outra = $travas->registrar($zeladora, 'zeladoria', 'falta_injustificada', 'Faltou dia 20', 'Ponto', '2026-09', $gerente);
    $travas->decidir($outra, $gerente, false, 'Estava com atestado');

    expect($outra->refresh()->status)->toBe('rejeitada')
        ->and($bonus->calcular($zeladora, 'zeladoria', '2026-09')['zerado_por_trava'])->toBeFalse();
});

it('trava exige evidência, cargo válido e a decisão certa: gerente confirma as dos outros e o dono a do gerente', function () {
    $zeladora = zeladoraCompleta();
    $gerente = test()->makeUser('gerente');
    $dono = test()->makeUser('proprietario');
    $consultor = test()->makeUser('consultor');
    $travas = app(TravaService::class);

    expect(fn () => $travas->registrar($zeladora, 'zeladoria', 'falta_injustificada', 'x', '  ', PERIODO, $gerente))->toThrow(ValidationException::class)
        ->and(fn () => $travas->registrar($zeladora, 'zeladoria', 'trava_inexistente', 'x', 'prova', PERIODO, $gerente))->toThrow(ValidationException::class)
        ->and(fn () => $travas->registrar($zeladora, 'chefe', 'execucao_sem_autorizacao', 'x', 'prova', PERIODO, $gerente))->toThrow(ValidationException::class);

    $deZeladora = $travas->registrar($zeladora, 'zeladoria', 'falta_injustificada', 'x', 'prova', PERIODO, $gerente);

    expect(fn () => $travas->decidir($deZeladora, $consultor, true))->toThrow(ValidationException::class)
        ->and(fn () => $travas->decidir($deZeladora, $gerente, false, ''))->toThrow(ValidationException::class);

    $travas->decidir($deZeladora, $gerente, true);

    expect(fn () => $travas->decidir($deZeladora, $gerente, true))->toThrow(ValidationException::class);

    // Trava do próprio gerente: só o dono decide.
    $chefeDaOficina = Colaborador::create(['nome' => 'Gerente Geral', 'cargos' => 'gerente', 'salario_base' => 6000, 'ativo' => true]);
    $doGerente = $travas->registrar($chefeDaOficina, 'gerente', 'indicador_falso', 'Informou número falso', 'Print', PERIODO, $dono);

    expect(fn () => $travas->decidir($doGerente, $gerente, true))->toThrow(ValidationException::class);

    $travas->decidir($doGerente, $dono, true);

    expect($doGerente->refresh()->status)->toBe('confirmada')
        ->and(fn () => $doGerente->delete())->toThrow(LogicException::class);
});

it('travas automáticas: avanço forçado, diferença de caixa, saída de estoque sem OS e RH; sem duplicar', function () {
    $dono = test()->makeUser('proprietario');
    $consultorUser = test()->makeUser('consultor');
    $consultor = Colaborador::create(['nome' => 'Cris', 'cargos' => 'consultor', 'user_id' => $consultorUser->id, 'ativo' => true]);
    $financeiro = Colaborador::create(['nome' => 'Fátima', 'cargos' => 'financeiro', 'ativo' => true]);
    $gerente = Colaborador::create(['nome' => 'Gil', 'cargos' => 'gerente', 'ativo' => true]);
    Colaborador::create(['nome' => 'Novo', 'cargos' => 'tecnico', 'admissao' => '2026-10-05', 'registro_antes_inicio' => false, 'ferias_vencimento' => '2026-09-01', 'ativo' => true]);

    DB::statement('SET FOREIGN_KEY_CHECKS=0');

    $os = test()->makeOs();

    // Cris forçou a saída do veículo sem nota (Faturamento -> Pós-Venda).
    ServiceOrderEvent::create([
        'service_order_id' => $os->id, 'tipo_evento' => 'bloqueio_forcado', 'etapa_origem' => 'Faturamento', 'etapa_destino' => 'Pós-Venda',
        'usuario_id' => $consultorUser->id, 'ocorrido_em' => '2026-10-14 15:00:00', 'dados_json' => ['faltava' => ['Nota fiscal emitida'], 'justificativa' => 'Cliente com pressa'],
    ]);

    MentoriaRegistro::record('caixa_dia', ['valor' => 35.5, 'ok' => false], Carbon::parse('2026-10-20 18:00'));
    DB::table('part_stock_movements')->insert(['part_inventory_id' => 1, 'type' => 'saida', 'quantity' => 1, 'reference_id' => null, 'created_at' => '2026-10-21 10:00:00', 'updated_at' => '2026-10-21 10:00:00']);

    $travas = app(TravaService::class);

    $novas = $travas->detectar(PERIODO);

    $porTipo = BonusTrava::pluck('cargo', 'tipo');

    expect($porTipo)->toHaveKeys(['liberado_sem_quitacao', 'caixa_sem_justificativa', 'ferias_vencidas', 'colaborador_sem_registro'])
        ->and(BonusTrava::where('tipo', 'liberado_sem_quitacao')->first()->colaborador_id)->toBe($consultor->id)
        ->and(BonusTrava::where('tipo', 'caixa_sem_justificativa')->first()->colaborador_id)->toBe($financeiro->id)
        ->and(BonusTrava::whereIn('tipo', ['ferias_vencidas', 'colaborador_sem_registro'])->pluck('colaborador_id')->unique()->all())->toBe([$gerente->id])
        ->and(BonusTrava::where('status', 'pendente')->count())->toBe($novas)
        ->and(BonusTrava::where('origem', 'automatica')->count())->toBe($novas);

    // Rodar de novo não duplica.
    expect($travas->detectar(PERIODO))->toBe(0);

    DB::statement('SET FOREIGN_KEY_CHECKS=1');

    expect($dono->exists)->toBeTrue();
});

it('fluxo: calcula, o gerente revisa, o dono aprova só na janela (dia 1 ao 5º dia útil), e o estorno é justificado', function () {
    $zeladora = zeladoraCompleta();
    $gerente = test()->makeUser('gerente');
    $dono = test()->makeUser('proprietario');
    $servico = app(BonusService::class);

    $apuracao = $servico->apurar(PERIODO)->first();

    // Sem revisão do gerente, o dono não aprova.
    expect(fn () => $servico->aprovar($apuracao, $dono, Carbon::parse('2026-11-04 10:00')))->toThrow(ValidationException::class);

    // O dono não revisa por engano com feedback vazio; o feedback é obrigatório.
    expect(fn () => $servico->revisar($apuracao, $gerente, '  '))->toThrow(ValidationException::class);

    $servico->revisar($apuracao, $gerente, 'Ótimo mês, só reforçar o aviso de materiais.');

    expect($apuracao->refresh()->status)->toBe('revisado')
        ->and($apuracao->feedback)->toContain('Ótimo mês');

    // Fora da janela (depois do 5º dia útil): bloqueado. 5º dia útil de novembro/2026 = 6/11 (sex).
    expect(fn () => $servico->aprovar($apuracao, $dono, Carbon::parse('2026-11-09 10:00')))->toThrow(ValidationException::class)
        // Gerente não aprova.
        ->and(fn () => $servico->aprovar($apuracao, $gerente, Carbon::parse('2026-11-04 10:00')))->toThrow(ValidationException::class);

    $servico->aprovar($apuracao, $dono, Carbon::parse('2026-11-06 18:00'));

    expect($apuracao->refresh()->status)->toBe('aprovado')
        ->and($apuracao->aprovado_por)->toBe($dono->id);

    // Aprovado não muda por recálculo.
    expect($servico->apurar(PERIODO)->first()->status)->toBe('aprovado');

    // Estorno: só com justificativa, deixa registro e volta para conferência.
    expect(fn () => $servico->estornar($apuracao, $dono, ''))->toThrow(ValidationException::class);

    $servico->estornar($apuracao, $dono, 'Pago em duplicidade');

    expect($apuracao->refresh()->status)->toBe('calculado')
        ->and($apuracao->justificativa_estorno)->toBe('Pago em duplicidade')
        ->and(MentoriaRegistro::where('tipo', 'bonus_estorno')->count())->toBe(1);
});

it('exportação da folha traz só o bônus aprovado', function () {
    $zeladora = zeladoraCompleta();
    $gerente = test()->makeUser('gerente');
    $dono = test()->makeUser('proprietario');
    $servico = app(BonusService::class);

    $apuracao = $servico->apurar(PERIODO)->first();

    expect($servico->linhasDaFolha(PERIODO))->toBe([]);

    $servico->revisar($apuracao, $gerente, 'Bom');
    $servico->aprovar($apuracao, $dono, Carbon::parse('2026-11-03 10:00'));

    expect($servico->linhasDaFolha(PERIODO))->toBe([[
        'colaborador' => 'Dona Maria', 'cpf' => null, 'cargo' => 'zeladoria', 'nota' => 100.0, 'faixa' => 100, 'trava' => 'nao', 'valor' => 200.0,
    ]]);
});

it('consultor e técnico sem vínculo com usuário/técnico não são medidos', function () {
    $consultor = Colaborador::create(['nome' => 'Sem vínculo', 'cargos' => 'consultor,tecnico', 'ativo' => true]);

    $apuracoes = app(BonusService::class)->apurar(PERIODO);

    expect($apuracoes)->toHaveCount(2)
        ->and($apuracoes->pluck('nota')->filter()->all())->toBe([])
        ->and($apuracoes->first()->motivo_resultado)->toContain('Ligue o colaborador');
});

it('fase 2: sem o gatilho de resultado atingido, os marcadores de resultado pontuam zero e o motivo aparece', function () {
    WorkshopSettings::set('inicio_mentoria', '2026-08-01');
    WorkshopSettings::set('meta_faturamento_mensal', 100000);
    $gerenteColab = Colaborador::create(['nome' => 'Gil', 'cargos' => 'gerente', 'salario_base' => 8000, 'ativo' => true]);

    // Faturamento de R$ 120.000 em outubro: GER-02 = 120% da meta (verde).
    $os = test()->makeOs();
    $os->forceFill(['grand_total' => 120000, 'faturado_em' => '2026-10-15 12:00:00'])->save();

    // Sem gatilho informado.
    $sem = app(BonusService::class)->calcular($gerenteColab, 'gerente', PERIODO);
    $ger02 = collect($sem['detalhe'])->firstWhere('codigos', ['GER-02']);

    expect($sem['fase'])->toBe(2)
        ->and($ger02['resultado'])->toBeTrue()
        ->and($ger02['situacoes'])->toBe(['verde'])
        ->and($ger02['pontos'])->toEqual(0.0)
        ->and($sem['motivo_resultado'])->toContain('Gatilho de resultado não informado');

    // Gatilho de R$ 200.000 não atingido.
    WorkshopSettings::set('gatilho_resultado', 200000);
    $nao = app(BonusService::class)->calcular($gerenteColab, 'gerente', PERIODO);

    expect(collect($nao['detalhe'])->firstWhere('codigos', ['GER-02'])['pontos'])->toEqual(0.0)
        ->and($nao['motivo_resultado'])->toContain('não atingido');

    // Gatilho de R$ 100.000 atingido: o marcador volta a pontuar (20 pontos × peso reescalonado).
    WorkshopSettings::set('gatilho_resultado', 100000);
    $sim = app(BonusService::class)->calcular($gerenteColab, 'gerente', PERIODO);

    expect(collect($sim['detalhe'])->firstWhere('codigos', ['GER-02'])['pontos'])->toEqual(20.0)
        ->and($sim['motivo_resultado'])->toBeNull();
});

it('fechamento do dia 1: congela indicadores, detecta travas, apura notas e só roda uma vez', function () {
    zeladoraCompleta();
    $fechamento = app(FechamentoService::class);

    expect($fechamento->fechado(PERIODO))->toBeFalse();

    $this->at('2026-11-01 00:05:00');
    expect($fechamento->runIfDue())->toBeTrue()
        ->and($fechamento->fechado(PERIODO))->toBeTrue()
        ->and(BonusApuracao::where('periodo', PERIODO)->count())->toBe(1)
        ->and(DB::table('indicator_snapshots')->where('periodo', PERIODO)->count())->toBeGreaterThanOrEqual(110)
        ->and((float) DB::table('indicator_snapshots')->where('periodo', PERIODO)->where('codigo', 'ZEL-01')->value('realizado'))->toBe(100.0);

    // Congelado: rodar de novo não refaz.
    expect($fechamento->runIfDue())->toBeFalse();

    // O gerente fecha o Painel do mês (GER-11).
    $registro = $fechamento->consolidarPainel(PERIODO);
    expect($registro->dados_json)->toBe(['periodo' => PERIODO]);
});

it('fechamento não roda sem colaboradores nem antes do início da mentoria', function () {
    $fechamento = app(FechamentoService::class);

    $this->at('2026-11-01 00:05:00');
    expect($fechamento->runIfDue())->toBeFalse()
        ->and($fechamento->fechado(PERIODO))->toBeFalse();

    Colaborador::create(['nome' => 'Alguém', 'cargos' => 'gerente', 'ativo' => true]);
    WorkshopSettings::set('inicio_mentoria', '2026-11-01');

    expect($fechamento->runIfDue())->toBeFalse()
        ->and(BonusApuracao::count())->toBe(0);

    // O mês anterior ao início foi marcado como "nada a fechar".
    expect(WorkshopSettings::get('fechamento_'.PERIODO))->toBe('anterior_a_mentoria');
});
