<?php

namespace Webkul\ServiceOrder\Services\Indicators;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\ServiceOrder\Models\AlcadaPedido;
use Webkul\ServiceOrder\Models\BarrierInstance;
use Webkul\ServiceOrder\Models\BarrierRule;
use Webkul\ServiceOrder\Models\BonusApuracao;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Models\WorkshopAlert;
use Webkul\ServiceOrder\Services\Bonus\BonusService;
use Webkul\ServiceOrder\Services\Financeiro\DreService;
use Webkul\ServiceOrder\Services\Financeiro\FinanceiroService;

/**
 * Dados do Painel do Dono (capítulo 8): resultado do mês, fluxo do caminhão,
 * peças e estoque, caixa e financeiro, gestão do gerente e RH, e equipe/dono.
 * A mesma estrutura alimenta a tela do computador e a do celular.
 *
 * Valores em reais aparecem aqui -- o painel é só do dono e do gerente.
 */
class PainelDonoService
{
    /** Indicador-chave de cada etapa no Bloco 2 (capítulo 8). */
    public const FLUXO = [
        ['etapa' => '01 Atendimento', 'stages' => [ServiceOrder::STAGE_CADASTRO, ServiceOrder::STAGE_ORDEM_SERVICO], 'codigos' => ['CON-01', 'CON-03', 'CON-13']],
        ['etapa' => '02 Diagnóstico', 'stages' => [ServiceOrder::STAGE_DIAGNOSTICO], 'codigos' => ['TEC-01', 'TEC-02', 'CHE-02']],
        ['etapa' => '03 Orçamento', 'stages' => [ServiceOrder::STAGE_ORCAMENTO], 'codigos' => ['PEC-01', 'CHE-04', 'CON-04']],
        ['etapa' => '04 Negociação', 'stages' => [ServiceOrder::STAGE_NEGOCIACAO], 'codigos' => ['CON-09', 'CON-10']],
        ['etapa' => '05 Execução', 'stages' => [ServiceOrder::STAGE_EM_EXECUCAO], 'codigos' => ['TEC-07', 'PEC-05', 'CHE-07']],
        ['etapa' => '06 Test Drive', 'stages' => [ServiceOrder::STAGE_VEICULO_TESTE], 'codigos' => ['CHE-08']],
        ['etapa' => '07 Faturamento', 'stages' => [ServiceOrder::STAGE_FATURAMENTO], 'codigos' => ['CON-07', 'FIN-02', 'PEC-06']],
        ['etapa' => '08 Pós-venda', 'stages' => [ServiceOrder::STAGE_POS_VENDA], 'codigos' => ['CON-08', 'CHE-10', 'CON-16']],
    ];

    public const BLOCO_PECAS = ['PEC-09', 'PEC-11', 'PEC-12'];

    public const BLOCO_FINANCEIRO = ['FIN-10', 'FIN-04', 'FIN-05', 'FIN-02', 'FIN-06', 'FIN-01', 'FIN-11', 'FIN-09', 'FIN-08'];

    public const BLOCO_GESTAO = ['GER-06', 'GER-07', 'GER-08', 'GER-09', 'GER-10', 'GER-14', 'GER-15', 'GER-16', 'GER-17', 'GER-19', 'GER-20'];

    public const BLOCO_DONO = ['DON-01', 'DON-02', 'DON-03', 'DON-04', 'DON-05', 'DON-06'];

    public const BLOCO_DRE = ['DRE-01', 'DRE-02', 'DRE-03', 'DRE-04', 'DRE-05', 'DRE-06', 'DRE-07', 'DRE-08', 'DRE-09', 'DRE-10'];

    public function __construct(protected IndicatorService $indicators, protected BonusService $bonus) {}

    /**
     * @return array<string, mixed>
     */
    public function build(string $periodo): array
    {
        [$from, $to] = $this->indicators->period($periodo);
        $anterior = $from->copy()->subMonth()->format('Y-m');

        return [
            'periodo' => $periodo,
            'periodo_rotulo' => ucfirst($from->locale('pt_BR')->translatedFormat('F \d\e Y')),
            'periodo_anterior' => $anterior,
            'cartoes' => $this->cartoes($periodo, $from, $to),
            'fluxo' => $this->fluxo($periodo),
            'pecas' => [
                'linhas' => $this->linhas(self::BLOCO_PECAS, $periodo),
                'valor_estoque' => $this->valorDoEstoque(),
            ],
            'financeiro' => ['linhas' => $this->linhas(self::BLOCO_FINANCEIRO, $periodo)],
            'gestao' => ['linhas' => $this->linhas(self::BLOCO_GESTAO, $periodo)],
            'dono' => ['linhas' => $this->linhas(self::BLOCO_DONO, $periodo)],
            'equipe' => $this->equipe($periodo),
            // Os indicadores da DRE entram no Bloco 1 do painel.
            'dre' => $this->linhas(self::BLOCO_DRE, $periodo),
        ];
    }

    /**
     * Financeiro do dono no celular: relatório diário, a receber e a pagar em
     * 7 dias, DRE do mês e ponto de equilíbrio.
     *
     * @return array<string, mixed>
     */
    public function financeiro(string $periodo): array
    {
        $financeiro = app(FinanceiroService::class);
        $dre = app(DreService::class);
        $hoje = today();

        $linhas = collect($dre->linhas($periodo))->keyBy('chave');
        $enviado = DB::table('relatorios_financeiro')->whereNotNull('enviado_em')->orderByDesc('data')->first();
        $fmt = fn ($v) => IndicatorFormatter::value($v === null ? null : (float) $v, 'brl');

        $caixa = $financeiro->caixaEBanco($hoje);

        return [
            'caixa' => [
                'saldo_conciliado' => $fmt($caixa['saldo_conciliado']),
                'a_receber_7_dias' => $fmt($caixa['a_receber_7_dias']),
                'a_pagar_7_dias' => $fmt($caixa['a_pagar_7_dias']),
                'vencido_receber' => $fmt($caixa['total_vencido_receber']),
                'vencido_pagar' => $fmt($caixa['total_vencido_pagar']),
            ],
            'relatorio' => $enviado ? [
                'data' => Carbon::parse($enviado->data)->format('d/m/Y'),
                'no_horario' => (bool) $enviado->no_horario,
                'alertas' => json_decode($enviado->alertas_json, true) ?: [],
            ] : null,
            'dre' => collect(['faturamento', 'margem_contribuicao', 'resultado_operacional', 'resultado_final'])
                ->map(fn ($chave) => ['rotulo' => $linhas[$chave]['rotulo'], 'valor' => $fmt($linhas[$chave]['valor']), 'percentual' => $linhas[$chave]['percentual'], 'situacao' => $linhas[$chave]['situacao']])
                ->values()->all(),
            'equilibrio' => collect($dre->pontoEquilibrio($periodo))->only(['ponto_equilibrio', 'faltam', 'dia_atingido', 'percentual'])
                ->map(fn ($v, $k) => in_array($k, ['ponto_equilibrio', 'faltam'], true) ? $fmt($v) : $v)->all(),
        ];
    }

    /**
     * Bloco 6: tabela de colaboradores (cargo, nota do mês, faixa, trava, valor
     * a pagar e o botão de aprovar bônus) e a fila de alçadas do dono.
     *
     * @return array<string, mixed>
     */
    protected function equipe(string $periodo): array
    {
        $apuracoes = BonusApuracao::with('colaborador')->where('periodo', $periodo)->get()
            ->sortBy(fn ($a) => $a->colaborador?->nome.$a->cargo)
            ->map(fn (BonusApuracao $a) => [
                'id' => $a->id,
                'colaborador' => $a->colaborador?->nome,
                'cargo' => $a->cargo,
                'nota' => $a->nota,
                'faixa' => $a->faixa_percentual,
                'trava' => $a->zerado_por_trava,
                'valor' => $a->valor,
                'status' => $a->status,
                'pode_aprovar' => $a->status === BonusApuracao::REVISADO,
            ])->values()->all();

        return [
            'apuracoes' => $apuracoes,
            'janela_aprovacao' => $this->bonus->janelaDeAprovacao($periodo),
            'alcadas_aguardando_dono' => AlcadaPedido::where('status', AlcadaPedido::ENCAMINHADO)->count(),
            'alcadas_atrasadas' => AlcadaPedido::where('status', AlcadaPedido::ENCAMINHADO)->where('prazo_dono_em', '<', now())->count(),
        ];
    }

    /**
     * Linha do painel: realizado, meta, situação, tendência de 3 meses e responsável.
     *
     * @param  string[]  $codigos
     * @return list<array<string, mixed>>
     */
    public function linhas(array $codigos, string $periodo, bool $comTendencia = true): array
    {
        return collect($codigos)->map(fn ($codigo) => $this->linha($codigo, $periodo, $comTendencia))->filter()->values()->all();
    }

    public function linha(string $codigo, string $periodo, bool $comTendencia = true): ?array
    {
        $definicao = $this->indicators->definition($codigo);

        if (! $definicao) {
            return null;
        }

        $avaliacao = $this->indicators->evaluate($definicao, $periodo);
        $resultado = $avaliacao['resultado'];

        return [
            'codigo' => $codigo,
            'nome' => $definicao->nome,
            'cargo' => IndicatorCatalog::CARGOS[$definicao->cargo] ?? $definicao->cargo,
            'valida' => $definicao->valida,
            'realizado' => $resultado->realizado,
            'realizado_fmt' => IndicatorFormatter::value($resultado->realizado, $definicao->unidade).($avaliacao['origem'] === 'planilha' ? ' (origem: planilha)' : ''),
            'origem' => $avaliacao['origem'],
            'meta' => $avaliacao['meta'],
            'meta_fmt' => IndicatorFormatter::meta($avaliacao['meta'], $definicao->tipo_meta, $definicao->unidade),
            'situacao' => $avaliacao['situacao'],
            'situacao_rotulo' => IndicatorStatus::ROTULOS[$avaliacao['situacao']],
            'cores' => IndicatorFormatter::cores($avaliacao['situacao']),
            'tendencia' => $comTendencia ? $this->tendencia($codigo, $periodo, $definicao->unidade) : [],
            'os_ids' => $resultado->osIds,
            'detalhe' => $resultado->detalhe,
        ];
    }

    /**
     * Últimos 3 meses (antes do atual), mais antigo primeiro.
     *
     * @return list<array{periodo: string, valor: ?float, fmt: string}>
     */
    protected function tendencia(string $codigo, string $periodo, string $unidade): array
    {
        return collect($this->indicators->trendDetalhado($codigo, $periodo, 3))
            ->map(fn ($item, $ym) => [
                'periodo' => $ym,
                'valor' => $item['valor'],
                'origem' => $item['origem'],
                // Mês vindo da planilha de medição leva a marca "origem: planilha".
                'fmt' => IndicatorFormatter::value($item['valor'], $unidade).($item['origem'] === 'planilha' ? ' (origem: planilha)' : ''),
            ])
            ->values()
            ->all();
    }

    /**
     * Bloco 1: cartões do topo.
     *
     * @return list<array<string, mixed>>
     */
    protected function cartoes(string $periodo, Carbon $from, Carbon $to): array
    {
        $faturadas = ServiceOrder::query()->whereBetween('quotes.faturado_em', [$from, $to])->get();
        $faturamento = (float) $faturadas->sum('grand_total');
        $entregues = ServiceOrderEvent::where('tipo_evento', 'saida_veiculo')->whereBetween('ocorrido_em', [$from, $to])->distinct('service_order_id')->count('service_order_id');

        $cartao = fn (string $rotulo, string $valor, string $situacao = IndicatorStatus::SEM_META, ?string $codigo = null) => [
            'rotulo' => $rotulo, 'valor' => $valor, 'situacao' => $situacao, 'cores' => IndicatorFormatter::cores($situacao), 'codigo' => $codigo,
        ];

        $deIndicador = function (string $rotulo, string $codigo) use ($cartao, $periodo) {
            $linha = $this->linha($codigo, $periodo, false);

            return $cartao($rotulo, $linha['realizado_fmt'], $linha['situacao'], $codigo);
        };

        $faturamentoMeta = $this->indicators->evaluate('GER-02', $periodo);
        $resultado = $this->indicators->evaluate('GER-03', $periodo);
        $dre = app(DreService::class)->compute($periodo);

        return [
            $cartao('Faturamento', IndicatorFormatter::value($faturamento, 'brl'), $faturamentoMeta['situacao'], 'GER-02'),
            $cartao('Resultado do mês', $dre['faturamento'] > 0 || $dre['pessoal'] > 0 ? IndicatorFormatter::value($dre['resultado_final'], 'brl') : '—', $resultado['situacao'], 'GER-03'),
            $cartao('Ticket médio', IndicatorFormatter::value($faturadas->count() ? $faturamento / $faturadas->count() : null, 'brl')),
            $cartao('Caminhões entregues', (string) $entregues),
            $deIndicador('Permanência média', 'GER-04'),
            $deIndicador('Produtividade da oficina', 'CHE-12'),
            $deIndicador('Nota de recomendação', 'CON-15'),
            $deIndicador('Indicadores no alvo', 'GER-01'),
        ];
    }

    /**
     * Bloco 2: o fluxo do caminhão em 8 etapas, com a faixa de quantos
     * caminhões há em cada etapa agora e quantos estão parados há mais de 3 dias.
     *
     * @return array<string, mixed>
     */
    protected function fluxo(string $periodo): array
    {
        $abertas = ServiceOrder::query()
            ->whereNotIn('quotes.workshop_status', [ServiceOrder::STAGE_CONCLUIDA, ServiceOrder::STAGE_CANCELADA])
            ->get(['quotes.id', 'quotes.workshop_status']);

        $parados = $this->idsParados($abertas->pluck('id')->all());

        $etapas = collect(self::FLUXO)->map(function ($etapa) use ($abertas, $parados, $periodo) {
            $naEtapa = $abertas->whereIn('workshop_status', $etapa['stages']);

            return [
                'etapa' => $etapa['etapa'],
                'agora' => $naEtapa->count(),
                'parados' => $naEtapa->whereIn('id', $parados)->count(),
                'indicadores' => $this->linhas($etapa['codigos'], $periodo),
            ];
        })->all();

        return [
            'etapas' => $etapas,
            'no_patio' => $abertas->whereNotIn('workshop_status', [ServiceOrder::STAGE_POS_VENDA])->count(),
            'parados' => count($parados),
        ];
    }

    /**
     * OS abertas sem mudança de etapa há mais de 3 dias.
     *
     * @param  int[]  $ids
     * @return int[]
     */
    public function idsParados(array $ids, int $dias = 3): array
    {
        if (! $ids) {
            return [];
        }

        $ultimas = ServiceOrderEvent::query()
            ->where('tipo_evento', 'mudanca_etapa')
            ->whereIn('service_order_id', $ids)
            ->selectRaw('service_order_id, MAX(ocorrido_em) as ultima')
            ->groupBy('service_order_id')
            ->pluck('ultima', 'service_order_id');

        $limite = now()->subDays($dias);

        return collect($ids)->filter(function ($id) use ($ultimas, $limite) {
            $ultima = $ultimas[$id] ?? null;

            // Sem evento de etapa, vale a data de criação da OS.
            return $ultima ? Carbon::parse($ultima)->lt($limite) : false;
        })->values()->all();
    }

    protected function valorDoEstoque(): float
    {
        return (float) DB::table('part_inventories as pi')
            ->join('parts as p', 'p.id', '=', 'pi.part_id')
            ->selectRaw('COALESCE(SUM(pi.in_stock * p.valor_unitario), 0) as total')
            ->value('total');
    }

    /**
     * Tela "Hoje" do celular: pátio por etapa, barreiras vencidas, faturado
     * no dia e alertas críticos / de nível 3.
     *
     * @return array<string, mixed>
     */
    public function hoje(): array
    {
        $abertas = ServiceOrder::query()
            ->with(['vehicle:id,placa', 'person:id,name'])
            ->whereNotIn('quotes.workshop_status', [ServiceOrder::STAGE_CONCLUIDA, ServiceOrder::STAGE_CANCELADA])
            ->get(['quotes.id', 'quotes.workshop_status', 'quotes.vehicle_id', 'quotes.person_id']);

        $porEtapa = collect(ServiceOrder::stagesFlow())->map(fn ($etapa) => [
            'etapa' => $etapa,
            'quantidade' => $abertas->where('workshop_status', $etapa)->count(),
        ])->all();

        $nomes = BarrierRule::pluck('nome', 'codigo');

        $vencidas = BarrierInstance::query()
            ->where('status', BarrierInstance::STATUS_VENCIDA)
            ->orderBy('vence_em')
            ->limit(50)
            ->get()
            ->map(function ($instancia) use ($abertas, $nomes) {
                $os = $abertas->firstWhere('id', $instancia->service_order_id);

                return [
                    'os' => $instancia->service_order_id,
                    'placa' => $os?->vehicle?->placa,
                    'cliente' => $os?->person?->name,
                    'barreira' => $nomes[$instancia->barrier_code] ?? $instancia->barrier_code,
                    'nivel' => $instancia->nivel_atual,
                    'venceu_em' => $instancia->vence_em?->format('d/m H:i'),
                ];
            })->all();

        $faturadoHoje = (float) ServiceOrder::query()->whereDate('quotes.faturado_em', now()->toDateString())->sum('grand_total');

        return [
            'no_patio' => $abertas->whereNotIn('workshop_status', [ServiceOrder::STAGE_POS_VENDA])->count(),
            'por_etapa' => $porEtapa,
            'barreiras_vencidas' => $vencidas,
            'faturado_hoje' => $faturadoHoje,
            'faturado_hoje_fmt' => IndicatorFormatter::value($faturadoHoje, 'brl'),
            'parados' => count($this->idsParados($abertas->pluck('id')->all())),
        ];
    }

    /**
     * Alertas críticos e de nível 3 (o que o dono recebe), com o que já foi resolvido.
     *
     * @return list<array<string, mixed>>
     */
    public function alertas(int $limite = 60): array
    {
        return WorkshopAlert::query()
            ->where(fn ($q) => $q->where('critico', true)->orWhere('nivel', 3))
            ->orderByDesc('disparado_em')
            ->limit($limite)
            ->get()
            ->map(fn ($alerta) => [
                'id' => $alerta->id,
                'titulo' => $alerta->titulo,
                'mensagem' => $alerta->mensagem,
                'critico' => (bool) $alerta->critico,
                'nivel' => $alerta->nivel,
                'status' => $alerta->status,
                'os' => $alerta->service_order_id,
                'disparado_em' => $alerta->disparado_em?->format('d/m H:i'),
                'resolvido_em' => $alerta->resolvido_em?->format('d/m H:i'),
            ])->all();
    }
}
