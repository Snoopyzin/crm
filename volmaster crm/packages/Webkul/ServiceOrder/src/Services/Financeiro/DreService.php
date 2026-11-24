<?php

namespace Webkul\ServiceOrder\Services\Financeiro;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Webkul\Mechanic\Models\Mechanic;
use Webkul\ServiceOrder\Models\Colaborador;
use Webkul\ServiceOrder\Models\Lancamento;
use Webkul\ServiceOrder\Models\PlanoConta;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderBoleto;
use Webkul\ServiceOrder\Models\ServiceOrderItem;
use Webkul\ServiceOrder\Services\Alcada\AlcadaService;
use Webkul\ServiceOrder\Services\OperationService;
use Webkul\ServiceOrder\Services\WorkshopSettings;
use Webkul\User\Models\User;

/**
 * DRE gerencial e precificação (capítulo 10). Montada sozinha:
 * notas emitidas (OS faturadas), peças lançadas na OS e contas a pagar
 * classificadas. O único trabalho manual do financeiro é escolher a conta de
 * cada pagamento.
 *
 * Valores que a oficina não lançou (impostos, comissões, pessoal) são
 * estimados pelos parâmetros da Ficha e aparecem marcados como estimados.
 */
class DreService
{
    public function __construct(protected OperationService $operations) {}

    /**
     * Parâmetros financeiros da Ficha de Enquadramento.
     *
     * @return array<string, float>
     */
    public function parametros(): array
    {
        $f = fn (string $chave, float $padrao = 0) => (float) (WorkshopSettings::get($chave) ?? $padrao);

        return [
            'impostos' => $f('pct_impostos') / 100,
            'comissoes' => $f('pct_comissoes') / 100,
            'desp_var_pecas' => $f('pct_desp_var_pecas') / 100,
            'lucro_desejado' => $f('lucro_desejado'),
            'margem_pecas' => $f('margem_desejada_pecas') / 100,
            'produtividade' => $f('produtividade_alvo', 80) / 100,
            'horas_mes' => $f('horas_mes_tecnico', 176),
            'encargos' => $f('encargos_percentual') / 100,
            'hora_cobrada' => $f('valor_hora_mao_obra'),
        ];
    }

    /**
     * DRE do mês. Mês fechado (congelado) devolve a foto do fechamento.
     *
     * @return array<string, mixed>
     */
    public function compute(string $periodo, bool $respeitarCongelamento = true): array
    {
        if ($respeitarCongelamento) {
            $fechado = DB::table('dre_fechamentos')->where('periodo', $periodo)->whereNull('reaberto_em')->first();

            if ($fechado) {
                return array_merge(json_decode($fechado->snapshot_json, true), ['congelada' => true]);
            }
        }

        PlanoConta::ensureDefaultsOnce();

        [$from, $to] = $this->limites($periodo);
        $p = $this->parametros();

        $ordens = ServiceOrder::query()->with('items')->whereBetween('quotes.faturado_em', [$from, $to])->get();
        $itens = $ordens->flatMap->items;

        $receitaMO = round((float) $itens->where('tipo', ServiceOrderItem::TIPO_SERVICO)->sum(fn ($i) => $i->line_total), 2);
        $receitaPecas = round((float) $itens->where('tipo', ServiceOrderItem::TIPO_PECA)->sum(fn ($i) => $i->line_total), 2);
        $receitaOutras = round(max(0, (float) $ordens->sum('grand_total') - $receitaMO - $receitaPecas), 2);
        $bruta = round($receitaMO + $receitaPecas + $receitaOutras, 2);

        $pecas = $itens->where('tipo', ServiceOrderItem::TIPO_PECA);
        $custoPecas = round((float) $pecas->sum(fn ($i) => (float) $i->custo_unitario * (float) $i->quantity), 2);
        $pecasSemCusto = $pecas->filter(fn ($i) => $i->custo_unitario === null)->count();

        $porPapel = $this->lancamentosPorPapel($periodo);
        $grupo = fn (string $g) => $this->lancamentosPorGrupo($periodo, $g);

        [$impostos, $impostosEstimado] = $this->realOuEstimado($porPapel['impostos'] ?? 0, $p['impostos'] * $bruta);
        $devolucoes = $porPapel['devolucoes'] ?? 0;
        $terceiros = $porPapel['terceiros'] ?? 0;
        [$comissoes, $comissoesEstimado] = $this->realOuEstimado($porPapel['comissoes'] ?? 0, $p['comissoes'] * $bruta);
        [$despVarPecas] = $this->realOuEstimado($porPapel['desp_var_pecas'] ?? 0, $p['desp_var_pecas'] * $receitaPecas);

        $liquida = round($bruta - $impostos - $devolucoes, 2);
        $margem = round($liquida - $custoPecas - $terceiros - $comissoes - $despVarPecas, 2);

        $shareMO = $bruta > 0 ? $receitaMO / $bruta : 0;
        $sharePecas = $bruta > 0 ? $receitaPecas / $bruta : 0;

        $margemServico = round($receitaMO - $impostos * $shareMO - $terceiros - $comissoes * $shareMO, 2);
        $margemPecas = round($receitaPecas - $impostos * $sharePecas - $custoPecas - $comissoes * $sharePecas - $despVarPecas, 2);

        [$pessoalOficina, $pessoalAdm, $pessoalEstimado] = $this->pessoal($porPapel, $p['encargos']);
        $pessoal = round($pessoalOficina + $pessoalAdm, 2);
        $despFixas = round($grupo('despesas_fixas'), 2);

        $operacional = round($margem - $pessoal - $despFixas, 2);
        $proLabore = $porPapel['pro_labore'] ?? 0;
        $juros = $porPapel['juros'] ?? 0;
        $parcelas = $porPapel['parcelas'] ?? 0;
        $final = round($operacional - $proLabore - $juros - $parcelas, 2);

        return [
            'periodo' => $periodo,
            'congelada' => false,
            'faturamento' => $bruta,
            'os_faturadas' => $ordens->count(),
            'receita_mo' => $receitaMO,
            'receita_pecas' => $receitaPecas,
            'receita_outras' => $receitaOutras,
            'impostos' => round($impostos + $devolucoes, 2),
            'receita_liquida' => $liquida,
            'custo_pecas' => $custoPecas,
            'pecas_sem_custo' => $pecasSemCusto,
            'terceiros' => round($terceiros, 2),
            'comissoes' => round($comissoes, 2),
            'desp_var_pecas' => round($despVarPecas, 2),
            'margem_contribuicao' => $margem,
            'margem_contribuicao_servico' => $margemServico,
            'margem_contribuicao_pecas' => $margemPecas,
            'pessoal_oficina' => round($pessoalOficina, 2),
            'pessoal_adm' => round($pessoalAdm, 2),
            'pessoal' => $pessoal,
            'despesas_fixas' => $despFixas,
            'resultado_operacional' => $operacional,
            'pro_labore' => round($proLabore, 2),
            'juros' => round($juros, 2),
            'parcelas' => round($parcelas, 2),
            'resultado_final' => $final,
            'estimados' => array_keys(array_filter(['impostos' => $impostosEstimado, 'comissoes' => $comissoesEstimado, 'pessoal' => $pessoalEstimado])),
        ];
    }

    /**
     * Linhas da tela da DRE: valor, % do faturamento, meta (orçamento anual),
     * mês anterior e média de 3 meses.
     *
     * @return list<array<string, mixed>>
     */
    public function linhas(string $periodo): array
    {
        $atual = $this->compute($periodo);
        $anterior = $this->compute($this->deslocar($periodo, -1));
        $media = $this->media3($periodo);
        $orcamento = $this->orcamento((int) substr($periodo, 0, 4), (int) substr($periodo, 5, 2));

        $modelo = [
            ['faturamento', 'Faturamento (receita bruta)', 0, 'receita', 'faturamento'],
            ['receita_mo', 'Receita de mão de obra', 1, 'receita', 'receita_mo'],
            ['receita_pecas', 'Receita de peças', 1, 'receita', 'receita_pecas'],
            ['impostos', '(−) Impostos e devoluções', 0, 'custo', null],
            ['receita_liquida', 'Receita líquida', 0, 'receita', null],
            ['custo_pecas', '(−) Custo das peças vendidas', 1, 'custo', null],
            ['terceiros', '(−) Serviços de terceiros', 1, 'custo', null],
            ['comissoes', '(−) Comissões', 1, 'custo', null],
            ['desp_var_pecas', '(−) Despesas variáveis de peças', 1, 'custo', null],
            ['margem_contribuicao', 'Margem de contribuição', 0, 'receita', 'margem_contribuicao'],
            ['pessoal', '(−) Pessoal', 1, 'custo', 'pessoal'],
            ['despesas_fixas', '(−) Despesas fixas', 1, 'custo', 'despesas_fixas'],
            ['resultado_operacional', 'Resultado operacional', 0, 'receita', 'resultado_operacional'],
            ['pro_labore', '(−) Pró-labore', 1, 'custo', null],
            ['juros', '(−) Juros e tarifas', 1, 'custo', null],
            ['parcelas', '(−) Parcelas de financiamentos', 1, 'custo', null],
            ['resultado_final', 'Resultado final', 0, 'receita', 'resultado_final'],
        ];

        return collect($modelo)->map(function ($m) use ($atual, $anterior, $media, $orcamento) {
            [$chave, $rotulo, $nivel, $natureza, $linhaMeta] = $m;
            $valor = (float) $atual[$chave];
            $meta = $linhaMeta ? ($orcamento[$linhaMeta] ?? null) : null;

            // Receitas e resultados: bom é ficar acima da meta; custos: abaixo.
            $situacao = $meta === null ? 'sem_meta' : ($natureza === 'receita'
                ? ($valor >= $meta ? 'verde' : ($valor >= $meta * 0.8 ? 'amarelo' : 'vermelho'))
                : ($valor <= $meta ? 'verde' : ($valor <= $meta * 1.5 ? 'amarelo' : 'vermelho')));

            return [
                'chave' => $chave,
                'rotulo' => $rotulo,
                'nivel' => $nivel,
                'valor' => $valor,
                'percentual' => $atual['faturamento'] > 0 ? round($valor / $atual['faturamento'] * 100, 1) : null,
                'meta' => $meta,
                'situacao' => $situacao,
                'mes_anterior' => (float) $anterior[$chave],
                'media_3_meses' => $media[$chave] ?? null,
                'estimado' => in_array($chave, $atual['estimados'], true),
            ];
        })->all();
    }

    /**
     * Média dos 3 meses anteriores ao período.
     *
     * @return array<string, float>
     */
    public function media3(string $periodo): array
    {
        $meses = collect([-3, -2, -1])->map(fn ($d) => $this->compute($this->deslocar($periodo, $d)));
        $chaves = ['faturamento', 'receita_mo', 'receita_pecas', 'impostos', 'receita_liquida', 'custo_pecas', 'terceiros', 'comissoes', 'desp_var_pecas', 'margem_contribuicao', 'pessoal', 'despesas_fixas', 'resultado_operacional', 'pro_labore', 'juros', 'parcelas', 'resultado_final'];

        return collect($chaves)->mapWithKeys(fn ($c) => [$c => round((float) $meses->avg($c), 2)])->all();
    }

    /**
     * Ponto de equilíbrio: (pessoal + despesas fixas) ÷ % da margem de
     * contribuição, com a média de 3 meses. Mostra o quanto falta e o dia em
     * que o faturamento do mês cobriu o ponto de equilíbrio.
     *
     * @return array<string, mixed>
     */
    public function pontoEquilibrio(string $periodo): array
    {
        $historico = collect([-3, -2, -1])->map(fn ($d) => $this->compute($this->deslocar($periodo, $d)))->filter(fn ($m) => $m['faturamento'] > 0);
        $base = $historico->isEmpty() ? collect([$this->compute($periodo)]) : $historico;

        $custoFixo = (float) $base->avg(fn ($m) => $m['pessoal'] + $m['despesas_fixas']);
        $margemPct = (float) $base->avg(fn ($m) => $m['faturamento'] > 0 ? $m['margem_contribuicao'] / $m['faturamento'] : 0);

        $equilibrio = $margemPct > 0 ? round($custoFixo / $margemPct, 2) : null;

        [$from, $to] = $this->limites($periodo);
        $porDia = ServiceOrder::query()->whereBetween('quotes.faturado_em', [$from, $to])->get(['quotes.grand_total', 'quotes.faturado_em'])
            ->groupBy(fn ($os) => (int) $os->faturado_em->format('j'))
            ->map(fn ($grupo) => (float) $grupo->sum('grand_total'))
            ->sortKeys();

        $faturado = (float) $porDia->sum();
        $diaAtingido = null;
        $acumulado = 0.0;

        if ($equilibrio) {
            foreach ($porDia as $dia => $valor) {
                $acumulado += $valor;

                if ($acumulado >= $equilibrio) {
                    $diaAtingido = $dia;
                    break;
                }
            }
        }

        return [
            'custo_fixo' => round($custoFixo, 2),
            'margem_contribuicao_pct' => round($margemPct * 100, 1),
            'ponto_equilibrio' => $equilibrio,
            'faturado' => round($faturado, 2),
            'faltam' => $equilibrio ? round(max(0, $equilibrio - $faturado), 2) : null,
            'dia_atingido' => $diaAtingido,
            'percentual' => $equilibrio ? round(min(100, $faturado / $equilibrio * 100), 1) : null,
        ];
    }

    /**
     * Preço mínimo da hora (memória de cálculo passo a passo) e o comparativo
     * com a hora cobrada, com a perda mensal estimada.
     *
     * @return array<string, mixed>
     */
    public function precoMinimoHora(string $periodo): array
    {
        $p = $this->parametros();
        $meses = collect([-3, -2, -1])->map(fn ($d) => $this->compute($this->deslocar($periodo, $d)))->filter(fn ($m) => $m['faturamento'] > 0);

        if ($meses->isEmpty()) {
            $meses = collect([$this->compute($periodo)]);
        }

        $pessoalOficina = (float) $meses->avg('pessoal_oficina');
        $estruturaComum = (float) $meses->avg(fn ($m) => $m['pessoal_adm'] + $m['despesas_fixas']);
        $shareMO = (float) $meses->avg(fn ($m) => $m['faturamento'] > 0 ? $m['receita_mo'] / $m['faturamento'] : 0);

        $custoACobrir = $pessoalOficina + $estruturaComum * $shareMO;
        $tecnicos = max(1, Mechanic::where('status', 'Ativo')->count());
        $horasVendaveis = $tecnicos * $p['horas_mes'] * $p['produtividade'];
        $divisor = 1 - $p['impostos'] - $p['comissoes'];

        $minimo = $horasVendaveis > 0 && $divisor > 0
            ? round(($custoACobrir + $p['lucro_desejado']) / $horasVendaveis / $divisor, 2)
            : null;

        $cobrada = $p['hora_cobrada'] ?: null;
        $razao = $minimo && $cobrada ? round($cobrada / $minimo * 100, 1) : null;
        $perda = $minimo && $cobrada && $cobrada < $minimo ? round(($minimo - $cobrada) * $horasVendaveis, 2) : 0.0;

        return [
            'pessoal_oficina' => round($pessoalOficina, 2),
            'estrutura_comum' => round($estruturaComum, 2),
            'participacao_mao_de_obra' => round($shareMO * 100, 1),
            'custo_a_cobrir' => round($custoACobrir, 2),
            'lucro_desejado' => $p['lucro_desejado'],
            'tecnicos' => $tecnicos,
            'horas_mes' => $p['horas_mes'],
            'produtividade' => $p['produtividade'] * 100,
            'horas_vendaveis' => round($horasVendaveis, 1),
            'impostos_pct' => $p['impostos'] * 100,
            'comissoes_pct' => $p['comissoes'] * 100,
            'preco_minimo' => $minimo,
            'hora_cobrada' => $cobrada,
            'hora_cobrada_sobre_minimo' => $razao,
            'perda_mensal' => $perda,
        ];
    }

    /**
     * Preço mínimo da peça: custo ÷ (1 − % impostos − % despesas variáveis −
     * % margem). A margem vem da tabela por faixa de custo (margem mínima);
     * sem faixa, da margem desejada da Ficha.
     */
    public function precoMinimoPeca(float $custo): ?float
    {
        $p = $this->parametros();

        $faixa = DB::table('margem_pecas_faixas')
            ->where(fn ($q) => $q->whereNull('custo_ate')->orWhere('custo_ate', '>=', $custo))
            ->orderByRaw('custo_ate is null, custo_ate')
            ->first();

        $margem = $faixa ? (float) $faixa->margem_minima / 100 : $p['margem_pecas'];

        if ($margem <= 0 && $p['impostos'] <= 0 && $p['desp_var_pecas'] <= 0) {
            return null;
        }

        $divisor = 1 - $p['impostos'] - $p['desp_var_pecas'] - $margem;

        return $divisor > 0 ? round($custo / $divisor, 2) : null;
    }

    /**
     * Margem por OS do mês: receita − impostos − custo das peças − terceiros −
     * horas reais × custo da hora.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function margemPorOs(string $periodo): Collection
    {
        [$from, $to] = $this->limites($periodo);
        $p = $this->parametros();
        $custoHora = $this->custoDaHora($periodo);

        $terceiros = Lancamento::query()->join('plano_contas as c', 'c.id', '=', 'financeiro_lancamentos.conta_id')
            ->where('c.papel', 'terceiros')->whereNotNull('financeiro_lancamentos.service_order_id')
            ->selectRaw('financeiro_lancamentos.service_order_id as os, SUM(financeiro_lancamentos.valor) as total')
            ->groupBy('financeiro_lancamentos.service_order_id')->pluck('total', 'os');

        return ServiceOrder::query()->with(['items', 'person:id,name', 'vehicle:id,placa'])
            ->whereBetween('quotes.faturado_em', [$from, $to])->get()
            ->map(function (ServiceOrder $os) use ($p, $custoHora, $terceiros) {
                $receita = (float) $os->grand_total;
                $impostos = $receita * $p['impostos'];
                $pecas = $os->items->where('tipo', ServiceOrderItem::TIPO_PECA)->sum(fn ($i) => (float) $i->custo_unitario * (float) $i->quantity);
                $horasReais = $os->horas_reais_saida !== null
                    ? (float) $os->horas_reais_saida
                    : $os->items->where('tipo', ServiceOrderItem::TIPO_SERVICO)->sum(fn ($i) => $this->operations->workedSeconds($i) / 3600);
                $maoDeObra = $horasReais * $custoHora;
                $terc = (float) ($terceiros[$os->id] ?? 0);
                $margem = round($receita - $impostos - $pecas - $terc - $maoDeObra, 2);

                return [
                    'os' => $os->id,
                    'cliente' => $os->person?->name,
                    'person_id' => $os->person_id,
                    'placa' => $os->vehicle?->placa,
                    'receita' => round($receita, 2),
                    'impostos' => round($impostos, 2),
                    'custo_pecas' => round($pecas, 2),
                    'terceiros' => round($terc, 2),
                    'horas_reais' => round($horasReais, 2),
                    'custo_mao_de_obra' => round($maoDeObra, 2),
                    'margem' => $margem,
                    'margem_pct' => $receita > 0 ? round($margem / $receita * 100, 1) : null,
                    'faturado_em' => $os->faturado_em,
                    'pago_em' => $os->pagamento_confirmado_em,
                ];
            })->sortBy('margem')->values();
    }

    /**
     * Ranking de clientes: margem, prazo médio de pagamento e inadimplência.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rankingClientes(string $periodo): Collection
    {
        $boletos = ServiceOrderBoleto::query()->where('status', '!=', 'Cancelado')->get()->groupBy('quote_id');

        return $this->margemPorOs($periodo)->groupBy('person_id')->map(function (Collection $os) use ($boletos) {
            $prazos = $os->filter(fn ($o) => $o['pago_em'] && $o['faturado_em'])->map(fn ($o) => $o['faturado_em']->diffInDays($o['pago_em']));
            $boletosDoCliente = $os->flatMap(fn ($o) => $boletos[$o['os']] ?? collect());
            $total = (float) $boletosDoCliente->sum('valor');
            $vencido = (float) $boletosDoCliente->filter(fn ($b) => $b->status === 'Pendente' && $b->vencimento && Carbon::parse($b->vencimento)->lt(today()))->sum('valor');

            return [
                'cliente' => $os->first()['cliente'],
                'os' => $os->count(),
                'receita' => round($os->sum('receita'), 2),
                'margem' => round($os->sum('margem'), 2),
                'margem_pct' => $os->sum('receita') > 0 ? round($os->sum('margem') / $os->sum('receita') * 100, 1) : null,
                'prazo_medio_dias' => $prazos->isEmpty() ? null : round($prazos->avg(), 1),
                'inadimplencia_pct' => $total > 0 ? round($vencido / $total * 100, 1) : 0.0,
            ];
        })->sortByDesc('margem')->values();
    }

    /**
     * Custo da hora do técnico: pessoal da oficina ÷ horas produtivas do mês.
     */
    public function custoDaHora(string $periodo): float
    {
        $p = $this->parametros();
        $dre = $this->compute($periodo, false);
        $tecnicos = max(1, Mechanic::where('status', 'Ativo')->count());
        $horas = $tecnicos * $p['horas_mes'];

        return $horas > 0 ? round($dre['pessoal_oficina'] / $horas, 2) : 0.0;
    }

    /**
     * Caixa projetado semana a semana até 90 dias: saldo conciliado + a
     * receber − a pagar − folha − parcelas.
     *
     * @return array<string, mixed>
     */
    public function caixaProjetado(?Carbon $hoje = null): array
    {
        $hoje ??= today();
        $p = $this->parametros();

        $ultimo = DB::table('caixa_fechamentos')->orderByDesc('data')->first();
        $saldo = (float) ($ultimo->saldo_contado ?? 0);

        $receber = ServiceOrderBoleto::query()->where('status', 'Pendente')->whereNotNull('vencimento')->get()
            ->map(fn ($b) => ['data' => max(Carbon::parse($b->vencimento), $hoje->copy()), 'valor' => (float) $b->valor]);

        $pagar = Lancamento::emAberto()->get()
            ->map(fn ($l) => ['data' => max($l->vencimento->copy(), $hoje->copy()), 'valor' => -$l->valor]);

        $folhaMensal = (float) Colaborador::ativos()->sum('salario_base') * (1 + $p['encargos']);
        $folha = collect();

        if ($folhaMensal > 0) {
            for ($mes = 0; $mes <= 3; $mes++) {
                $dia = $hoje->copy()->addMonthsNoOverflow($mes)->startOfMonth()->addDays(4);

                if ($dia->gte($hoje)) {
                    $folha->push(['data' => $dia, 'valor' => -$folhaMensal]);
                }
            }
        }

        $movimentos = $receber->concat($pagar)->concat($folha);
        $semanas = [];
        $acumulado = $saldo;
        $menor = $saldo;

        for ($s = 1; $s <= 13; $s++) {
            $ate = $hoje->copy()->addDays(7 * $s);
            $de = $hoje->copy()->addDays(7 * ($s - 1));

            $acumulado += (float) $movimentos->filter(fn ($m) => $m['data']->gte($de) && $m['data']->lt($ate))->sum('valor');
            $menor = min($menor, $acumulado);
            $semanas[] = ['semana' => $s, 'ate' => $ate->format('d/m'), 'saldo' => round($acumulado, 2)];
        }

        $em = fn (int $dias) => round($saldo + (float) $movimentos->filter(fn ($m) => $m['data']->lte($hoje->copy()->addDays($dias)))->sum('valor'), 2);

        return [
            'saldo_inicial' => round($saldo, 2),
            'em_30_dias' => $em(30),
            'em_60_dias' => $em(60),
            'em_90_dias' => $em(90),
            'semanas' => $semanas,
            'fica_negativo' => $menor < 0,
            'menor_saldo' => round($menor, 2),
        ];
    }

    // ---- Fechamento mensal da DRE ------------------------------------------------------

    /**
     * Congela a DRE do mês (a foto não muda mais, mesmo que se lance algo depois).
     */
    public function congelar(string $periodo): void
    {
        $snapshot = $this->compute($periodo, false);

        DB::table('dre_fechamentos')->updateOrInsert(
            ['periodo' => $periodo],
            ['snapshot_json' => json_encode($snapshot), 'congelado_em' => now(), 'reaberto_em' => null, 'reabertura_justificativa' => null, 'reaberto_por' => null, 'updated_at' => now(), 'created_at' => now()]
        );
    }

    public function congelada(string $periodo): bool
    {
        return DB::table('dre_fechamentos')->where('periodo', $periodo)->whereNull('reaberto_em')->exists();
    }

    /**
     * Reabre uma DRE congelada: exige a justificativa do financeiro e a
     * aprovação do dono (pedido na fila de alçadas).
     *
     * @throws ValidationException
     */
    public function reabrir(string $periodo, string $justificativa, User $financeiro): void
    {
        if (! $this->congelada($periodo)) {
            throw ValidationException::withMessages(['dre' => ['Esta DRE não está congelada.']]);
        }

        if (trim($justificativa) === '') {
            throw ValidationException::withMessages(['justificativa' => ['A reabertura da DRE exige justificativa.']]);
        }

        // Exige a aprovação do dono: sem ela, abre o pedido e bloqueia.
        app(AlcadaService::class)->authorize('reabrir_dre', null, null, "Reabrir a DRE de {$periodo}: {$justificativa}", $financeiro);

        DB::table('dre_fechamentos')->where('periodo', $periodo)->update([
            'reaberto_em' => now(), 'reabertura_justificativa' => $justificativa, 'reaberto_por' => $financeiro->id, 'updated_at' => now(),
        ]);
    }

    // ---- Orçamento anual ---------------------------------------------------------

    /**
     * Metas do mês por linha da DRE.
     *
     * @return array<string, float>
     */
    public function orcamento(int $ano, int $mes): array
    {
        return DB::table('orcamento_anual')->where('ano', $ano)->where('mes', $mes)->pluck('valor', 'linha')->map(fn ($v) => (float) $v)->all();
    }

    // ---- Privados ------------------------------------------------------------------

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function limites(string $periodo): array
    {
        $from = Carbon::createFromFormat('Y-m', $periodo)->startOfMonth();

        return [$from, $from->copy()->endOfMonth()];
    }

    public function deslocar(string $periodo, int $meses): string
    {
        return Carbon::createFromFormat('Y-m', $periodo)->startOfMonth()->addMonthsNoOverflow($meses)->format('Y-m');
    }

    /**
     * @return array{0: float, 1: bool} valor e se foi estimado
     */
    protected function realOuEstimado(float $real, float $estimado): array
    {
        return $real > 0 ? [$real, false] : [$estimado, $estimado > 0];
    }

    /**
     * Soma dos lançamentos do mês (por competência) por papel da conta.
     *
     * @return array<string, float>
     */
    protected function lancamentosPorPapel(string $periodo): array
    {
        [$from, $to] = $this->limites($periodo);

        return Lancamento::query()
            ->join('plano_contas as c', 'c.id', '=', 'financeiro_lancamentos.conta_id')
            ->whereBetween('financeiro_lancamentos.competencia', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('c.papel')
            ->selectRaw('c.papel, SUM(financeiro_lancamentos.valor) as total')
            ->groupBy('c.papel')
            ->pluck('total', 'papel')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    protected function lancamentosPorGrupo(string $periodo, string $grupo): float
    {
        [$from, $to] = $this->limites($periodo);

        return (float) Lancamento::query()
            ->join('plano_contas as c', 'c.id', '=', 'financeiro_lancamentos.conta_id')
            ->whereBetween('financeiro_lancamentos.competencia', [$from->toDateString(), $to->toDateString()])
            ->where('c.grupo', $grupo)
            ->sum('financeiro_lancamentos.valor');
    }

    /**
     * Pessoal: contas lançadas; sem lançamento, estimado pelos salários dos colaboradores.
     *
     * @return array{0: float, 1: float, 2: bool}
     */
    protected function pessoal(array $porPapel, float $encargos): array
    {
        $oficina = (float) ($porPapel['pessoal_oficina'] ?? 0);
        $adm = (float) ($porPapel['pessoal_adm'] ?? 0);

        if ($oficina > 0 || $adm > 0) {
            return [$oficina, $adm, false];
        }

        $colaboradores = Colaborador::ativos()->whereNotNull('salario_base')->get();

        if ($colaboradores->isEmpty()) {
            return [0.0, 0.0, false];
        }

        $fator = 1 + $encargos;

        return [
            (float) $colaboradores->where('classificacao', 'oficina')->sum('salario_base') * $fator,
            (float) $colaboradores->where('classificacao', 'administracao')->sum('salario_base') * $fator,
            true,
        ];
    }
}
