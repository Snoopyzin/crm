<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Webkul\ServiceOrder\Models\AlcadaPedido;
use Webkul\ServiceOrder\Models\BonusMarcador;
use Webkul\ServiceOrder\Models\Colaborador;
use Webkul\ServiceOrder\Models\IndicatorDefinition;
use Webkul\ServiceOrder\Models\Lancamento;
use Webkul\ServiceOrder\Models\MentoriaRegistro;
use Webkul\ServiceOrder\Models\PlanoConta;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderBoleto;
use Webkul\ServiceOrder\Models\ServiceOrderItem;
use Webkul\ServiceOrder\Models\WorkshopAlert;
use Webkul\ServiceOrder\Services\Alcada\AlcadaService;
use Webkul\ServiceOrder\Services\Bonus\TravaService;
use Webkul\ServiceOrder\Services\Financeiro\DreService;
use Webkul\ServiceOrder\Services\Financeiro\FinanceiroService;
use Webkul\ServiceOrder\Services\Indicators\IndicatorService;
use Webkul\ServiceOrder\Services\WorkshopSettings;

beforeEach(function () {
    IndicatorDefinition::forgetSeeded();
    BonusMarcador::forgetSeeded();
    PlanoConta::forgetSeeded();
    PlanoConta::ensureDefaults();
});

function conta(string $codigo): PlanoConta
{
    return PlanoConta::where('codigo', $codigo)->firstOrFail();
}

function lancar(string $descricao, float $valor, string $codigo, string $vencimento = '2026-11-10', array $extra = []): Lancamento
{
    return app(FinanceiroService::class)->criar(array_merge(['descricao' => $descricao, 'valor' => $valor, 'vencimento' => $vencimento, 'conta_id' => conta($codigo)->id], $extra));
}

function osFaturada(string $dia, array $itens, float $total): ServiceOrder
{
    $os = test()->makeOs(['workshop_status' => ServiceOrder::STAGE_POS_VENDA]);

    foreach ($itens as $item) {
        $linha = new ServiceOrderItem;
        $linha->forceFill(array_merge(['quote_id' => $os->id, 'product_id' => 0, 'quantity' => 1, 'total' => $item['price']], $item))->save();
    }

    $os->forceFill(['grand_total' => $total, 'faturado_em' => $dia.' 12:00:00'])->save();

    return $os;
}

function cenarioDre(): void
{
    WorkshopSettings::set('pct_impostos', 10);
    WorkshopSettings::set('pct_comissoes', 5);

    // OS A: R$ 1.000 de mão de obra (dia 5). OS B: peça de R$ 500 com custo R$ 300 (dia 10).
    osFaturada('2026-11-05', [['tipo' => 'Serviço', 'name' => 'Retífica', 'price' => 1000, 'hours' => 1]], 1000);
    osFaturada('2026-11-10', [['tipo' => 'Peça', 'name' => 'Turbina', 'price' => 500, 'custo_unitario' => 300]], 500);

    lancar('Mecânico terceirizado', 100, '3.02');
    lancar('Salários da oficina', 200, '4.01');
    lancar('Aluguel', 150, '5.01');
}

it('plano de contas padrão com os seis grupos, e a oficina só cria subcontas', function () {
    expect(PlanoConta::where('padrao', true)->count())->toBe(22)
        ->and(PlanoConta::where('grupo', 'receitas')->count())->toBe(3)
        ->and(PlanoConta::where('grupo', 'abaixo_resultado')->count())->toBe(3);

    $sub = PlanoConta::criarSubconta(conta('5.01'), 'Aluguel do galpão 2');

    expect($sub->codigo)->toBe('5.01.01')
        ->and($sub->grupo)->toBe('despesas_fixas')
        ->and($sub->padrao)->toBeFalse()
        ->and($sub->pai_id)->toBe(conta('5.01')->id);
});

it('conta a pagar: classificar, pagar e alimentar FIN-06 e FIN-07', function () {
    $servico = app(FinanceiroService::class);
    $semConta = $servico->criar(['descricao' => 'Boleto da distribuidora', 'valor' => 800, 'vencimento' => '2026-11-12', 'pagamento_pecas' => true]);

    expect($semConta->conta_id)->toBeNull()
        ->and($semConta->competencia->format('Y-m-d'))->toBe('2026-11-01');

    expect(fn () => $servico->classificar($semConta, 99999))->toThrow(ValidationException::class);

    $servico->classificar($semConta, conta('3.01')->id);
    $servico->pagar($semConta->refresh(), ['pago_em' => '2026-11-12', 'forma' => 'pix', 'nf_entrada_conferida' => true]);

    $comJuros = lancar('Energia', 300, '5.02');
    $servico->pagar($comJuros, ['pago_em' => '2026-11-13', 'forma' => 'boleto', 'juros_multa' => true]);

    expect(fn () => $servico->pagar($comJuros->refresh(), []))->toThrow(ValidationException::class);

    $contas = MentoriaRegistro::where('tipo', 'conta_paga')->pluck('ok', 'observacao');

    expect($contas['Boleto da distribuidora'])->toBeTrue()
        ->and($contas['Energia'])->toBeFalse()
        ->and(MentoriaRegistro::where('tipo', 'pagamento_pecas')->first()->ok)->toBeTrue();

    // FIN-06: uma conta de duas paga sem juros; FIN-07: pagamento de peças com NF conferida.
    $this->at('2026-12-02 10:00:00');
    $indicadores = app(IndicatorService::class);

    expect($indicadores->evaluate('FIN-06', '2026-11')['resultado']->realizado)->toBe(50.0)
        ->and($indicadores->evaluate('FIN-07', '2026-11')['resultado']->realizado)->toBe(100.0);
});

it('conciliação: pagamento sem conta não entra; com todas as contas, o dia é conciliado (FIN-04)', function () {
    $servico = app(FinanceiroService::class);
    $this->at('2026-11-17 09:00:00');

    $semConta = $servico->criar(['descricao' => 'Pagamento sem classificar', 'valor' => 50, 'vencimento' => '2026-11-17']);
    $servico->pagar($semConta, ['pago_em' => '2026-11-17']);
    $comConta = lancar('Internet', 120, '5.02', '2026-11-17');
    $servico->pagar($comConta, ['pago_em' => '2026-11-17']);

    $resultado = $servico->conciliarDia(Carbon::parse('2026-11-17'));

    expect($resultado)->toBe(['conciliados' => 1, 'sem_conta' => 1])
        ->and($comConta->refresh()->conciliado_em)->not->toBeNull()
        ->and($semConta->refresh()->conciliado_em)->toBeNull()
        ->and(MentoriaRegistro::where('tipo', 'conciliacao_dia')->count())->toBe(0);

    $servico->classificar($semConta, conta('5.07')->id);
    $servico->conciliarDia(Carbon::parse('2026-11-17'));

    $registro = MentoriaRegistro::where('tipo', 'conciliacao_dia')->first();

    expect($semConta->refresh()->conciliado_em)->not->toBeNull()
        ->and($registro->ok)->toBeTrue();
});

it('caixa sem diferença: fecha, grava FIN-05 e não alerta ninguém', function () {
    $this->enablePilot([], []);
    $gerente = $this->makeUser('gerente');
    $servico = app(FinanceiroService::class);

    lancar('Café', 40, '5.07', '2026-11-17')->update(['pago_em' => '2026-11-17']);

    $resultado = $servico->fecharCaixa([
        'data' => '2026-11-17', 'saldo_inicial' => 1000, 'entradas' => ['dinheiro' => 300, 'pix' => 200, 'cartoes' => 0, 'boletos' => 100], 'saldo_contado' => 1560,
    ]);

    // 1000 + 600 − 40 = 1560.
    expect($resultado)->toBe(['saldo_sistema' => 1560.0, 'saldo_contado' => 1560.0, 'diferenca' => 0.0])
        ->and(WorkshopAlert::where('user_id', $gerente->id)->count())->toBe(0)
        ->and(MentoriaRegistro::where('tipo', 'caixa_dia')->first()->ok)->toBeTrue();

    expect(fn () => $servico->fecharCaixa(['data' => '2026-11-17', 'saldo_contado' => 1]))->toThrow(ValidationException::class);

    // O dia seguinte parte do saldo contado do anterior.
    expect($servico->saldoDoDiaAnterior(Carbon::parse('2026-11-18')))->toBe(1560.0);
});

it('diferença de caixa avisa gerente e dono na hora, sem o valor; sem justificativa também vira trava do financeiro', function () {
    $this->enablePilot([], []);
    $gerente = $this->makeUser('gerente');
    $dono = $this->makeUser('proprietario');
    $financeiro = Colaborador::create(['nome' => 'Fátima', 'cargos' => 'financeiro', 'ativo' => true]);
    $servico = app(FinanceiroService::class);

    $servico->fecharCaixa(['data' => '2026-11-17', 'saldo_inicial' => 500, 'entradas' => ['dinheiro' => 100], 'saldo_contado' => 575.5]);

    $alertas = WorkshopAlert::where('barrier_code', 'critico_diferenca_caixa')->get();

    expect($alertas->pluck('user_id')->sort()->values()->all())->toBe(collect([$gerente->id, $dono->id])->sort()->values()->all())
        ->and($alertas->every(fn ($a) => $a->critico))->toBeTrue()
        ->and($alertas->first()->mensagem)->toContain('sem justificativa')->not->toContain('R$')->not->toContain('24,5')
        ->and(MentoriaRegistro::where('tipo', 'caixa_dia')->first()->ok)->toBeFalse();

    // A trava automática do bônus do financeiro aparece no fechamento do mês.
    expect(app(TravaService::class)->detectar('2026-11'))->toBe(1);
    expect(DB::table('bonus_travas')->where('colaborador_id', $financeiro->id)->value('tipo'))->toBe('caixa_sem_justificativa');

    // Com justificativa o dia conta como justificado.
    $servico->fecharCaixa(['data' => '2026-11-18', 'saldo_inicial' => 575.5, 'saldo_contado' => 570, 'justificativa' => 'Troco devolvido ao cliente']);
    expect(MentoriaRegistro::where('tipo', 'caixa_dia')->orderByDesc('id')->first()->ok)->toBeTrue();
});

it('régua de cobrança: contatos dos dias 1, 7 e 15 pendentes, registro e indicador FIN-11', function () {
    $servico = app(FinanceiroService::class);
    $os = $this->makeOs();
    $this->at('2026-11-18 10:00:00');

    $boleto = ServiceOrderBoleto::create(['quote_id' => $os->id, 'valor' => 900, 'vencimento' => '2026-11-10', 'status' => 'Pendente']);

    // Vencido há 8 dias: previstos os contatos dos dias 1 e 7.
    $pendentes = collect($servico->reguaPendente(today()));

    expect($pendentes->pluck('dia_regua')->all())->toBe([1, 7]);

    $servico->registrarContatoDeCobranca($boleto->id, 1);

    expect(collect($servico->reguaPendente(today()))->pluck('dia_regua')->all())->toBe([7])
        ->and(fn () => $servico->registrarContatoDeCobranca($boleto->id, 3))->toThrow(ValidationException::class);

    // FIN-11: 1 dos 2 contatos previstos no mês.
    expect(app(IndicatorService::class)->evaluate('FIN-11', '2026-11')['resultado']->realizado)->toBe(50.0);
});

it('relatório diário do financeiro: três partes, até 3 alertas, horário combinado e FIN-08', function () {
    $this->enablePilot([], []);
    WorkshopSettings::set('relatorio_financeiro_horario', '17:30');
    $servico = app(FinanceiroService::class);
    $dia = Carbon::parse('2026-11-17');

    lancar('Aluguel', 2000, '5.01', '2026-11-20');
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_NEGOCIACAO]);
    $os->forceFill(['grand_total' => 4500])->save();
    ServiceOrderBoleto::create(['quote_id' => $os->id, 'valor' => 700, 'vencimento' => '2026-11-19', 'status' => 'Pendente']);

    $relatorio = $servico->relatorioDoDia($dia);

    expect($relatorio['caixa']['a_pagar_7_dias'])->toBe(2000.0)
        ->and($relatorio['caixa']['a_receber_7_dias'])->toBe(700.0)
        ->and($relatorio['operacao']['situacoes']['aguardando_aprovacao'])->toBe(['quantidade' => 1, 'valor' => 4500.0])
        ->and($relatorio['operacao']['saidas_sem_nf'])->toBe(0)
        ->and($relatorio['operacao'])->toHaveKeys(['os_paradas_3_dias', 'orcamentos_sem_resposta_48h', 'os_fechadas_no_dia', 'excecoes_de_credito']);

    expect(fn () => $servico->enviarRelatorio($dia, ['a', 'b', 'c', 'd']))->toThrow(ValidationException::class);

    $noHorario = $servico->enviarRelatorio($dia, ['Cobrar a transportadora X', '', 'Aprovar compra de turbina'], null, Carbon::parse('2026-11-17 17:00:00'));

    expect($noHorario)->toBeTrue()
        ->and(json_decode(DB::table('relatorios_financeiro')->first()->alertas_json, true))->toBe(['Cobrar a transportadora X', 'Aprovar compra de turbina'])
        ->and(fn () => $servico->enviarRelatorio($dia, [], null, Carbon::parse('2026-11-17 17:10:00')))->toThrow(ValidationException::class);

    // Outro dia, enviado depois do horário: fora do horário.
    expect($servico->enviarRelatorio(Carbon::parse('2026-11-18'), [], null, Carbon::parse('2026-11-18 18:45:00')))->toBeFalse();

    $this->at('2026-11-19 10:00:00');
    $registros = MentoriaRegistro::where('tipo', 'relatorio_financeiro')->orderBy('id')->pluck('ok')->all();

    expect($registros)->toBe([true, false]);
});

it('relatório do gerente: as 5 linhas são obrigatórias e alimentam GER-12', function () {
    $servico = app(FinanceiroService::class);

    expect(fn () => $servico->salvarRelatorioGerente(Carbon::parse('2026-11-17'), ['destaques' => 'ok', 'problemas' => 'nada']))->toThrow(ValidationException::class);

    $servico->salvarRelatorioGerente(Carbon::parse('2026-11-17'), [
        'destaques' => '4 caminhões entregues', 'problemas' => 'Falta de ferramenta no box 2', 'decisoes_tomadas' => 'Comprar a ferramenta',
        'decisoes_pendentes' => 'Contratar auxiliar', 'equipe' => 'Todos presentes',
    ]);

    expect(DB::table('relatorios_gerente')->count())->toBe(1)
        ->and(MentoriaRegistro::where('tipo', 'relatorio_gerente')->count())->toBe(1);
});

it('DRE: receita por tipo, margem de contribuição, resultado operacional e final, com estimativas marcadas', function () {
    cenarioDre();
    lancar('Pró-labore', 300, '6.01');

    $dre = app(DreService::class)->compute('2026-11');

    expect($dre['faturamento'])->toBe(1500.0)
        ->and($dre['receita_mo'])->toBe(1000.0)
        ->and($dre['receita_pecas'])->toBe(500.0)
        // 10% de impostos estimado; 5% de comissões estimado.
        ->and($dre['impostos'])->toBe(150.0)
        ->and($dre['receita_liquida'])->toBe(1350.0)
        ->and($dre['custo_pecas'])->toBe(300.0)
        ->and($dre['terceiros'])->toBe(100.0)
        ->and($dre['comissoes'])->toBe(75.0)
        ->and($dre['margem_contribuicao'])->toBe(875.0)
        ->and($dre['margem_contribuicao_servico'])->toBe(750.0)
        ->and($dre['margem_contribuicao_pecas'])->toBe(125.0)
        ->and($dre['pessoal'])->toBe(200.0)
        ->and($dre['despesas_fixas'])->toBe(150.0)
        ->and($dre['resultado_operacional'])->toBe(525.0)
        ->and($dre['pro_labore'])->toBe(300.0)
        ->and($dre['resultado_final'])->toBe(225.0)
        ->and($dre['estimados'])->toBe(['impostos', 'comissoes']);

    // Lançamento real de impostos substitui a estimativa.
    lancar('DAS do mês', 120, '2.01');
    $real = app(DreService::class)->compute('2026-11');

    expect($real['impostos'])->toBe(120.0)->and($real['estimados'])->toBe(['comissoes']);
});

it('DRE: pessoal estimado pelos salários dos colaboradores quando nada foi lançado', function () {
    osFaturada('2026-11-05', [['tipo' => 'Serviço', 'name' => 'Revisão', 'price' => 2000, 'hours' => 1]], 2000);
    Colaborador::create(['nome' => 'Técnico', 'cargos' => 'tecnico', 'classificacao' => 'oficina', 'salario_base' => 3000, 'ativo' => true]);
    Colaborador::create(['nome' => 'Recepção', 'cargos' => 'consultor', 'classificacao' => 'administracao', 'salario_base' => 2000, 'ativo' => true]);
    WorkshopSettings::set('encargos_percentual', 50);

    $dre = app(DreService::class)->compute('2026-11');

    expect($dre['pessoal_oficina'])->toBe(4500.0)
        ->and($dre['pessoal_adm'])->toBe(3000.0)
        ->and($dre['estimados'])->toContain('pessoal');
});

it('DRE: linhas com % do faturamento, meta do orçamento anual, mês anterior e média de 3 meses', function () {
    cenarioDre();
    DB::table('orcamento_anual')->insert([
        ['ano' => 2026, 'mes' => 11, 'linha' => 'faturamento', 'valor' => 1200, 'created_at' => now(), 'updated_at' => now()],
        ['ano' => 2026, 'mes' => 11, 'linha' => 'despesas_fixas', 'valor' => 100, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $linhas = collect(app(DreService::class)->linhas('2026-11'))->keyBy('chave');

    expect($linhas['faturamento']['valor'])->toBe(1500.0)
        ->and($linhas['faturamento']['percentual'])->toBe(100.0)
        ->and($linhas['faturamento']['meta'])->toBe(1200.0)
        ->and($linhas['faturamento']['situacao'])->toBe('verde')
        // Despesa fixa (custo) de 150 contra meta de 100: até 1,5× = atenção.
        ->and($linhas['despesas_fixas']['situacao'])->toBe('amarelo')
        ->and($linhas['margem_contribuicao']['percentual'])->toBe(58.3)
        ->and($linhas['faturamento']['mes_anterior'])->toBe(0.0)
        ->and($linhas['resultado_operacional']['situacao'])->toBe('sem_meta');
});

it('ponto de equilíbrio: custo fixo ÷ % da margem de contribuição e o dia em que foi atingido', function () {
    cenarioDre();

    $equilibrio = app(DreService::class)->pontoEquilibrio('2026-11');

    // (200 + 150) ÷ 58,3% = R$ 600; a 1ª OS (dia 5, R$ 1.000) já cobriu.
    expect($equilibrio['custo_fixo'])->toBe(350.0)
        ->and($equilibrio['margem_contribuicao_pct'])->toBe(58.3)
        ->and($equilibrio['ponto_equilibrio'])->toBe(600.0)
        ->and($equilibrio['dia_atingido'])->toBe(5)
        ->and($equilibrio['faltam'])->toBe(0.0);

    $this->at('2026-12-02 10:00:00');
    expect(app(IndicatorService::class)->evaluate('DRE-05', '2026-11')['resultado']->realizado)->toBe(5.0);
});

it('preço mínimo da hora: memória de cálculo e perda mensal', function () {
    cenarioDre();
    WorkshopSettings::set('lucro_desejado', 1000);
    WorkshopSettings::set('produtividade_alvo', 80);
    WorkshopSettings::set('horas_mes_tecnico', 176);
    WorkshopSettings::set('valor_hora_mao_obra', 8);
    $this->makeMechanic('Zé');

    $hora = app(DreService::class)->precoMinimoHora('2026-11');

    // Custo a cobrir = 200 + 150 × (1000 ÷ 1500) = 300; horas vendáveis = 1 × 176 × 80% = 140,8.
    expect($hora['custo_a_cobrir'])->toBe(300.0)
        ->and($hora['horas_vendaveis'])->toBe(140.8)
        ->and($hora['preco_minimo'])->toBe(10.86)
        ->and($hora['hora_cobrada'])->toBe(8.0)
        ->and($hora['hora_cobrada_sobre_minimo'])->toBe(73.7)
        ->and($hora['perda_mensal'])->toBeGreaterThan(0);

    $this->at('2026-12-02 10:00:00');
    expect(app(IndicatorService::class)->evaluate('DRE-06', '2026-11')['resultado']->realizado)->toBe(73.7);
});

it('preço mínimo da peça: custo ÷ (1 − impostos − despesas − margem), pela faixa de custo', function () {
    WorkshopSettings::set('pct_impostos', 10);
    WorkshopSettings::set('pct_desp_var_pecas', 5);
    WorkshopSettings::set('margem_desejada_pecas', 25);
    $dre = app(DreService::class);

    expect($dre->precoMinimoPeca(100))->toBe(166.67);

    DB::table('margem_pecas_faixas')->insert([
        ['custo_ate' => 200, 'margem_minima' => 30, 'margem_alvo' => 45, 'created_at' => now(), 'updated_at' => now()],
        ['custo_ate' => null, 'margem_minima' => 15, 'margem_alvo' => 25, 'created_at' => now(), 'updated_at' => now()],
    ]);

    expect($dre->precoMinimoPeca(100))->toBe(181.82)
        ->and($dre->precoMinimoPeca(5000))->toBe(round(5000 / (1 - 0.10 - 0.05 - 0.15), 2));
});

it('peça precificada abaixo do mínimo vai para a fila de alçadas; aprovada pelo gerente, passa', function () {
    $this->enablePilot();
    WorkshopSettings::set('pct_impostos', 10);
    WorkshopSettings::set('margem_desejada_pecas', 30);
    $pecas = $this->makeUser('pecas', ['service_orders', 'service_orders.view', 'service_orders.edit', 'ver_valores_financeiros']);
    $gerente = $this->makeUser('gerente');
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_ORCAMENTO]);

    $peca = new ServiceOrderItem;
    $peca->forceFill(['quote_id' => $os->id, 'product_id' => 0, 'tipo' => 'Peça', 'name' => 'Bico injetor', 'price' => 0, 'quantity' => 1, 'total' => 0])->save();

    // Custo 100: mínimo = 100 ÷ (1 − 0,10 − 0,30) = 166,67.
    $payload = ['item_id' => $peca->id, 'preco_unitario' => 120, 'custo_unitario' => 100, 'origem_preco' => 'fornecedor'];
    $rota = route('admin.service_orders.workflow.action', [$os->id, 'precificar_peca']);

    $this->actingAs($pecas, 'user')->post($rota, $payload)->assertSessionHas('error');

    expect($peca->refresh()->precificada_em)->toBeNull()
        ->and(AlcadaPedido::first()->tema)->toBe('preco_abaixo_minimo');

    app(AlcadaService::class)->decide(AlcadaPedido::first(), $gerente, true, 'Cliente de frota');

    $this->post($rota, $payload)->assertSessionHas('success');

    expect($peca->refresh()->precificada_em)->not->toBeNull();

    // Preço igual ou acima do mínimo passa direto.
    $outra = new ServiceOrderItem;
    $outra->forceFill(['quote_id' => $os->id, 'product_id' => 0, 'tipo' => 'Peça', 'name' => 'Filtro', 'price' => 0, 'quantity' => 1, 'total' => 0])->save();

    $this->post($rota, ['item_id' => $outra->id, 'preco_unitario' => 170, 'custo_unitario' => 100, 'origem_preco' => 'estoque'])->assertSessionHas('success');
    expect(AlcadaPedido::count())->toBe(1);

    // DRE-07: a peça abaixo do mínimo tinha aprovação, então não conta como ocorrência.
    $this->at('2026-12-02 10:00:00');
    DB::table('quote_items')->where('id', $peca->id)->update(['precificada_em' => '2026-11-18 10:00:00']);
    DB::table('quote_items')->where('id', $outra->id)->update(['precificada_em' => '2026-11-18 10:00:00']);

    expect(app(IndicatorService::class)->evaluate('DRE-07', '2026-11')['resultado']->realizado)->toBe(0.0);
});

it('DRE congela no fechamento e só reabre com justificativa e a aprovação do dono', function () {
    $this->enablePilot();
    $financeiro = $this->makeUser('financeiro');
    $gerente = $this->makeUser('gerente');
    $dono = $this->makeUser('proprietario');
    cenarioDre();
    $dre = app(DreService::class);

    $dre->congelar('2026-11');

    // Lançamento novo depois do fechamento não muda a DRE congelada.
    lancar('Conta atrasada', 500, '5.07', '2026-11-25');

    expect($dre->congelada('2026-11'))->toBeTrue()
        ->and($dre->compute('2026-11')['despesas_fixas'])->toEqual(150)
        ->and($dre->compute('2026-11')['congelada'])->toBeTrue()
        ->and($dre->compute('2026-11', false)['despesas_fixas'])->toBe(650.0);

    expect(fn () => $dre->reabrir('2026-11', '', $financeiro))->toThrow(ValidationException::class);

    // Sem a aprovação do dono, o pedido é aberto e a DRE continua congelada.
    expect(fn () => $dre->reabrir('2026-11', 'Faltou lançar uma conta', $financeiro))->toThrow(ValidationException::class)
        ->and($dre->congelada('2026-11'))->toBeTrue();

    $pedido = AlcadaPedido::where('tema', 'reabrir_dre')->firstOrFail();
    expect($pedido->nivel_necessario)->toBe('proprietario');

    app(AlcadaService::class)->forward($pedido, $gerente);
    app(AlcadaService::class)->decide($pedido->refresh(), $dono, true, 'Pode reabrir');

    $dre->reabrir('2026-11', 'Faltou lançar uma conta', $financeiro);

    expect($dre->congelada('2026-11'))->toBeFalse()
        ->and($dre->compute('2026-11')['despesas_fixas'])->toBe(650.0)
        ->and(DB::table('dre_fechamentos')->first()->reabertura_justificativa)->toBe('Faltou lançar uma conta');
});

it('margem por OS e ranking de clientes: receita − impostos − peças − terceiros − horas × custo da hora', function () {
    WorkshopSettings::set('pct_impostos', 10);
    WorkshopSettings::set('horas_mes_tecnico', 100);
    $this->makeMechanic('Zé');
    lancar('Salários da oficina', 1000, '4.01');

    $boa = osFaturada('2026-11-05', [['tipo' => 'Serviço', 'name' => 'Revisão', 'price' => 2000, 'hours' => 1], ['tipo' => 'Peça', 'name' => 'Filtro', 'price' => 400, 'custo_unitario' => 150]], 2400);
    $boa->forceFill(['horas_reais_saida' => 4])->save();

    $ruim = osFaturada('2026-11-08', [['tipo' => 'Serviço', 'name' => 'Retífica', 'price' => 600, 'hours' => 1]], 600);
    $ruim->forceFill(['horas_reais_saida' => 20])->save();

    $dre = app(DreService::class);
    $margens = $dre->margemPorOs('2026-11')->keyBy('os');

    // Custo da hora = 1.000 ÷ (1 técnico × 100 h) = R$ 10.
    expect($dre->custoDaHora('2026-11'))->toBe(10.0)
        // 2.400 − 240 − 150 − 0 − 4 h × 10 = 1.970.
        ->and($margens[$boa->id]['margem'])->toBe(1970.0)
        // 600 − 60 − 0 − 20 h × 10 = 340.
        ->and($margens[$ruim->id]['margem'])->toBe(340.0)
        ->and($dre->margemPorOs('2026-11')->first()['os'])->toBe($ruim->id);

    $negativa = osFaturada('2026-11-09', [['tipo' => 'Serviço', 'name' => 'Cortesia', 'price' => 100, 'hours' => 1]], 100);
    $negativa->forceFill(['horas_reais_saida' => 30])->save();

    expect($dre->margemPorOs('2026-11')->firstWhere('os', $negativa->id)['margem'])->toBeLessThan(0);

    $this->at('2026-12-02 10:00:00');
    // DRE-09: 1 OS com margem negativa em 3.
    expect(app(IndicatorService::class)->evaluate('DRE-09', '2026-11')['resultado']->realizado)->toBe(33.3);

    expect($dre->rankingClientes('2026-11'))->not->toBeEmpty();
});

it('caixa projetado: saldo + a receber − a pagar − folha, por semana, com alerta de saldo negativo', function () {
    $this->at('2026-11-20 10:00:00');
    DB::table('caixa_fechamentos')->insert(['data' => '2026-11-19', 'saldo_inicial' => 0, 'saldo_contado' => 1000, 'saldo_sistema' => 1000, 'fechado_em' => now(), 'created_at' => now(), 'updated_at' => now()]);
    Colaborador::create(['nome' => 'Técnico', 'cargos' => 'tecnico', 'salario_base' => 2000, 'ativo' => true]);

    $os = $this->makeOs();
    ServiceOrderBoleto::create(['quote_id' => $os->id, 'valor' => 500, 'vencimento' => '2026-11-30', 'status' => 'Pendente']);
    lancar('Fornecedor', 300, '3.01', '2026-12-10');

    $caixa = app(DreService::class)->caixaProjetado(today());

    // Em 30 dias: 1.000 + 500 − 300 − folha de 5/12 (2.000) = −800.
    expect($caixa['saldo_inicial'])->toBe(1000.0)
        ->and($caixa['em_30_dias'])->toBe(-800.0)
        ->and($caixa['semanas'])->toHaveCount(13)
        ->and($caixa['fica_negativo'])->toBeTrue()
        ->and($caixa['menor_saldo'])->toBeLessThan(0);
});

it('orçamento anual: salvar metas e GER-03 (resultado contra a meta)', function () {
    $gerente = $this->makeUser('gerente', ['financeiro_dre', 'financeiro_rotina']);
    cenarioDre();
    $this->actingAs($gerente, 'user');

    $this->post(route('admin.financeiro.orcamento.salvar'), ['ano' => 2026, 'meta' => ['resultado_final' => [11 => '500,00', 12 => ''], 'faturamento' => [11 => '2.000']]])
        ->assertRedirect(route('admin.financeiro.orcamento', ['ano' => 2026]));

    expect(DB::table('orcamento_anual')->where('linha', 'resultado_final')->where('mes', 11)->value('valor'))->toEqual(500)
        ->and(DB::table('orcamento_anual')->where('linha', 'faturamento')->value('valor'))->toEqual(2000);

    $this->at('2026-12-02 10:00:00');

    // Resultado final de novembro = 525 (sem pró-labore) contra meta de 500 => 105%.
    expect(app(IndicatorService::class)->evaluate('GER-03', '2026-11')['resultado']->realizado)->toBe(105.0);
});

it('as telas do financeiro abrem para quem tem permissão e barram os demais', function () {
    $this->enablePilot();
    cenarioDre();
    $dono = $this->makeUser('proprietario', ['financeiro_rotina', 'financeiro_dre', 'painel_dono', 'ver_valores_financeiros']);
    $this->actingAs($dono, 'user');

    $this->get(route('admin.financeiro.lancamentos'))->assertOk()->assertSee('Contas a pagar')->assertSee('Salários da oficina');
    $this->get(route('admin.financeiro.contas'))->assertOk()->assertSee('Plano de contas')->assertSee('Serviços de mão de obra');
    $this->get(route('admin.financeiro.caixa', ['dia' => '2026-11-17']))->assertOk()->assertSee('Fechamento do caixa')->assertSee('Régua de cobrança');
    $this->get(route('admin.financeiro.relatorios', ['dia' => '2026-11-17']))->assertOk()->assertSee('Caixa e banco')->assertSee('Operação (o segundo olhar)');
    $this->get(route('admin.financeiro.dre', ['periodo' => '2026-11']))->assertOk()->assertSee('DRE gerencial de 2026-11')->assertSee('Margem de contribuição')->assertSee('Ponto de equilíbrio')->assertSee('Preço mínimo da hora');
    $this->get(route('admin.financeiro.analises', ['periodo' => '2026-11']))->assertOk()->assertSee('Simulador')->assertSee('Margem por OS')->assertSee('Fluxo de caixa projetado');
    $this->get(route('admin.financeiro.orcamento', ['ano' => 2026]))->assertOk()->assertSee('Marco Zero');

    $this->post(route('admin.financeiro.marco_zero'), ['data' => '2026-10-01', 'faturamento' => 80000, 'resultado' => 5000, 'hora_cobrada' => 120, 'produtividade' => 60, 'retrabalho' => 6])->assertRedirect();
    $this->get(route('admin.financeiro.orcamento'))->assertOk()->assertSee('Marco Zero (01/10/2026)');

    $this->post(route('admin.financeiro.lancamentos.store'), ['descricao' => 'Frete', 'valor' => 90, 'vencimento' => '2026-11-25'])->assertRedirect();
    expect(Lancamento::where('descricao', 'Frete')->exists())->toBeTrue();

    $this->post(route('admin.financeiro.dre.congelar'), ['periodo' => '2026-11'])->assertRedirect();
    $this->get(route('admin.financeiro.dre', ['periodo' => '2026-11']))->assertSee('Congelada no fechamento do mês');

    $consultor = $this->makeUser('consultor', ['service_orders', 'service_orders.view']);
    $this->actingAs($consultor, 'user');

    foreach (['lancamentos', 'caixa', 'relatorios', 'dre', 'analises', 'orcamento', 'contas'] as $rota) {
        $this->get(route('admin.financeiro.'.$rota))->assertForbidden();
    }

    $this->post(route('admin.financeiro.caixa.fechar'), [])->assertForbidden();
});

it('Painel do Dono: DRE entra no bloco 1 e a aba Financeiro do celular traz relatório, a receber/pagar e DRE do mês', function () {
    $this->enablePilot();
    $this->at('2026-11-20 10:00:00');
    cenarioDre();
    $dono = $this->makeUser('proprietario', ['painel_dono', 'financeiro_rotina', 'financeiro_dre', 'ver_valores_financeiros']);
    app(FinanceiroService::class)->enviarRelatorio(Carbon::parse('2026-11-19'), ['Cobrar frota Norte'], $dono, Carbon::parse('2026-11-19 17:00:00'));
    $this->actingAs($dono, 'user');

    $this->get(route('admin.mentoria.painel', ['periodo' => '2026-11']))
        ->assertOk()
        ->assertSee('Indicadores da DRE e da precificação')
        ->assertSee('Margem de contribuição de serviço')
        ->assertSee('Resultado do mês');

    $financeiro = $this->getJson(route('admin.mentoria.painel.dados', ['aba' => 'financeiro', 'periodo' => '2026-11']))->assertOk();

    expect($financeiro->json('relatorio.alertas'))->toBe(['Cobrar frota Norte'])
        ->and($financeiro->json('relatorio.no_horario'))->toBeTrue()
        ->and($financeiro->json('caixa'))->toHaveKeys(['saldo_conciliado', 'a_receber_7_dias', 'a_pagar_7_dias'])
        ->and(collect($financeiro->json('dre'))->pluck('rotulo')->all())->toContain('Faturamento (receita bruta)', 'Resultado final')
        ->and($financeiro->json('equilibrio.dia_atingido'))->toBe(5);
});
