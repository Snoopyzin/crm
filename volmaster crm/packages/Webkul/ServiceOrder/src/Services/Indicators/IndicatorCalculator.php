<?php

namespace Webkul\ServiceOrder\Services\Indicators;

use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\Fiscal\Models\FiscalDocument;
use Webkul\Mechanic\Models\Mechanic;
use Webkul\ServiceOrder\Models\AlcadaPedido;
use Webkul\ServiceOrder\Models\BarrierInstance;
use Webkul\ServiceOrder\Models\Colaborador;
use Webkul\ServiceOrder\Models\Lancamento;
use Webkul\ServiceOrder\Models\MentoriaRegistro;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderBoleto;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Models\ServiceOrderItem;
use Webkul\ServiceOrder\Services\BusinessHours;
use Webkul\ServiceOrder\Services\Financeiro\DreService;
use Webkul\ServiceOrder\Services\OperationService;
use Webkul\ServiceOrder\Services\WorkshopSettings;

/**
 * Cálculo de cada indicador do catálogo a partir dos registros da OS
 * (carimbos de tempo, eventos, barreiras) e dos registros manuais.
 *
 * Regra do capítulo 1: indicador nunca é digitado à mão, exceto as auditorias
 * com nota. Tudo aqui sai de service_order_events, barrier_instances, das
 * colunas da OS e de mentoria_registros.
 *
 * Os indicadores de prazo ("em até 10 min", "em 2 h"...) leem as instâncias
 * de barreira: cumprida no prazo conta, fora do prazo (ou vencida e ainda
 * aberta) não. Assim o número do indicador é o mesmo que gerou os alertas.
 */
class IndicatorCalculator
{
    protected Carbon $from;

    protected Carbon $to;

    /** @var array{user_id?: int, mechanic_id?: int} */
    protected array $scope = [];

    /** @var array<string, Closure>|null */
    protected ?array $registry = null;

    public function __construct(protected OperationService $operations, protected DreService $dre) {}

    public function supports(string $code): bool
    {
        return array_key_exists($code, $this->registry());
    }

    /**
     * @param  array{user_id?: int, mechanic_id?: int}  $scope  Restringe ao consultor (user_id) ou ao técnico (mechanic_id)
     */
    public function calculate(string $code, Carbon $from, Carbon $to, array $scope = []): IndicatorResult
    {
        $this->from = $from->copy();
        $this->to = $to->copy();
        $this->scope = $scope;

        $calculator = $this->registry()[$code] ?? null;

        return $calculator ? $calculator() : IndicatorResult::none('Este indicador ainda não tem cálculo automático.');
    }

    /**
     * @return array<string, Closure>
     */
    protected function registry(): array
    {
        if ($this->registry !== null) {
            return $this->registry;
        }

        return $this->registry = [
            // ---- Consultor técnico ----
            'CON-01' => fn () => $this->barrier('recepcao'),
            'CON-02' => fn () => $this->fichas(),
            'CON-03' => fn () => $this->comLiberacao(fn ($os, $liberada) => $os->cor_credito_em && $os->cor_credito_em->lte($liberada)),
            'CON-04' => fn () => $this->barrier('orcamento_2h'),
            'CON-05' => fn () => $this->aprovacaoAntesDaLiberacao(),
            'CON-06' => fn () => $this->barrier('atualizacao_cliente', porOs: true),
            'CON-07' => fn () => $this->barrier('pronto_nao_faturado'),
            'CON-08' => fn () => $this->barrier('posvenda_7_dias'),
            'CON-09' => fn () => $this->taxaAprovacao(),
            'CON-10' => fn () => $this->comLiberacao(fn ($os) => $os->items->contains('adicional', true)),
            'CON-11' => fn () => $this->adimplencia(),
            'CON-12' => fn () => $this->recusasComRetorno(),
            'CON-13' => fn () => $this->taxa($this->osComEvento('chegada_veiculo'), fn ($os) => $os->termo_assinado_em && $os->autorizacao_rodagem),
            'CON-14' => fn () => $this->taxa($this->osComEvento('saida_veiculo'), fn ($os) => (bool) $os->proxima_revisao_data),
            'CON-15' => fn () => $this->notaMediaClientes(),
            'CON-16' => fn () => $this->retornoPreventiva(),
            'CON-17' => fn () => $this->taxa($this->osBase()->where('tipo_os', 'chamado_externo')->whereBetween('created_at', [$this->from, $this->to])->get(), fn ($os) => (bool) $os->chamado_autorizado_em),

            // ---- Chefe de oficina ----
            'CHE-01' => fn () => $this->barrier('tecnico_designado'),
            'CHE-02' => fn () => $this->taxa($this->osComEvento('orcamento_enviado'), fn ($os, $envio) => $os->diagnostico_visto_status === 'aprovado' && $os->diagnostico_visto_em && $os->diagnostico_visto_em->lte($envio)),
            'CHE-03' => fn () => $this->orcamentoSemCorrecao(),
            'CHE-04' => fn () => $this->barrier('os_completa_consultor'),
            'CHE-05' => fn () => $this->aprovacaoAntesDaLiberacao(),
            'CHE-06' => fn () => $this->aderenciaAoTempo(),
            'CHE-07' => fn () => $this->taxa($this->osComEvento('saida_veiculo')->filter(fn ($os) => $os->prazo_prometido_em), fn ($os) => $os->saida_em && $os->saida_em->lte($os->prazo_prometido_em)),
            'CHE-08' => fn () => $this->taxa($this->osComEvento('saida_veiculo')->filter(fn ($os) => $os->vehicle_id), fn ($os) => $os->teste_iniciado_em && $os->teste_finalizado_em && $os->conferencia_final_em),
            'CHE-09' => fn () => $this->taxa($this->osComEvento('saida_veiculo'), fn ($os) => $os->checklist_saida_em && $os->horas_reais_saida !== null),
            'CHE-10' => fn () => $this->retrabalho(),
            'CHE-11' => fn () => $this->comLiberacao(fn ($os) => $os->items->contains('adicional', true)),
            'CHE-12' => fn () => $this->produtividade(),
            'CHE-13' => fn () => $this->barrier('diagnostico_retorno'),
            'CHE-14' => fn () => $this->taxa($this->retornos(), fn ($os) => (bool) $os->retorno_causa),
            'CHE-15' => fn () => $this->taxa($this->osBase()->where('tipo_os', 'chamado_externo')->whereBetween('created_at', [$this->from, $this->to])->get(), fn ($os) => $os->pre_diagnostico_em && $os->pre_diagnostico_em->lte($os->created_at->copy()->addMinutes(30))),
            'CHE-16' => fn () => $this->simNaoNoTrimestre('tempario_revisado'),

            // ---- Técnico ----
            'TEC-01' => fn () => $this->barrier('diagnostico_tempo'),
            'TEC-02' => fn () => $this->assertividade(),
            'TEC-03' => fn () => $this->laudosSemDevolucao(),
            'TEC-04' => fn () => $this->taxa($this->osComEvento('diagnostico_fim'), fn ($os) => $this->laudoCompleto($os)),
            'TEC-05' => fn () => $this->barrier('relacao_pecas'),
            'TEC-06' => fn () => $this->relacaoCorreta(),
            'TEC-07' => fn () => $this->eficiencia(),
            'TEC-08' => fn () => $this->aderenciaAoTempo(),
            'TEC-09' => fn () => $this->taxa($this->osComEvento('teste_fim'), fn ($os) => $os->teste_resultado !== 'reprovado'),
            'TEC-10' => fn () => $this->retornoDeServico(),
            'TEC-11' => fn () => $this->pecasVelhasComTermo(),
            'TEC-12' => fn () => $this->apontamentoCompleto(),
            'TEC-13' => fn () => $this->mediaRegistros('auditoria_box'),
            'TEC-14' => fn () => IndicatorResult::value($this->registros('celular_execucao')->count()),
            'TEC-15' => fn () => $this->taxa($this->osComEvento('checklist_saida'), fn ($os) => $os->conferencia_final_em && $os->checklist_saida_em && $os->conferencia_final_em->lte($os->checklist_saida_em)),

            // ---- Peças ----
            'PEC-01' => fn () => $this->barrier('precificacao_peca'),
            'PEC-02' => fn () => $this->pecaCerta(),
            'PEC-03' => fn () => $this->precoMantido(),
            'PEC-04' => fn () => $this->barrier('compra_peca'),
            'PEC-05' => fn () => $this->entregaNoPrazo(),
            'PEC-06' => fn () => $this->conferenciaDePecas(),
            'PEC-07' => fn () => $this->prazoEntreDatas('nf_entrada', 'recebida_em', 'lancada_em', 24 * 60),
            'PEC-08' => fn () => $this->prazoEntreDatas('garantia_fornecedor', 'recebida_em', 'aberta_em', 5 * 24 * 60),
            'PEC-09' => fn () => $this->acuracidade(),
            'PEC-10' => fn () => $this->inventarioSemanal(),
            'PEC-11' => fn () => $this->pecasParadas(),
            'PEC-12' => fn () => $this->margemDePecas(),
            'PEC-13' => fn () => $this->mediaRegistros('auditoria_estoque'),

            // ---- Financeiro ----
            'FIN-01' => fn () => $this->barrier('cor_credito'),
            'FIN-02' => fn () => $this->nfAntesDaSaida(),
            'FIN-03' => fn () => $this->boletoNoDia(),
            'FIN-04' => fn () => $this->taxaDeRegistros('conciliacao_dia'),
            'FIN-05' => fn () => $this->caixaSemDiferenca(),
            'FIN-06' => fn () => $this->taxaDeRegistros('conta_paga'),
            'FIN-07' => fn () => $this->taxaDeRegistros('pagamento_pecas'),
            'FIN-08' => fn () => $this->registrosPorDiaUtil('relatorio_financeiro'),
            'FIN-09' => fn () => $this->notasComErro(),
            'FIN-10' => fn () => $this->inadimplencia(),
            'FIN-11' => fn () => $this->reguaCumprida(),
            'FIN-12' => fn () => $this->taxaDeRegistros('contabilidade_envio'),
            'FIN-13' => fn () => $this->simNaoNoMes('relatorio_mensal_financeiro'),

            // ---- Gerente ----
            'GER-02' => fn () => $this->faturamentoContraMeta(),
            'GER-03' => fn () => $this->resultadoContraMeta(),
            'GER-04' => fn () => $this->permanenciaMedia(),
            'GER-05' => fn () => $this->retrabalho(),
            'GER-06' => fn () => $this->reunioes(['reuniao_diaria', 'reuniao_semanal', 'reuniao_mensal']),
            'GER-08' => fn () => $this->planosNoPrazo(),
            'GER-09' => fn () => $this->reclamacoesEm48h(),
            'GER-10' => fn () => $this->auditoriasFeitas(),
            'GER-11' => fn () => $this->painelNoPrazo(),
            'GER-12' => fn () => $this->registrosPorDiaUtil('relatorio_gerente'),
            'GER-13' => fn () => $this->admissoesComRegistro(),
            'GER-14' => fn () => $this->folhaNoPrazo(),
            'GER-15' => fn () => $this->feriasVencidas(),
            'GER-16' => fn () => $this->examesEEpiEmDia(),
            'GER-17' => fn () => IndicatorResult::value($this->registros('hora_extra_sem_autorizacao')->count()),
            'GER-18' => fn () => $this->taxaDeRegistros('rescisao'),
            'GER-19' => fn () => $this->absenteismo(),
            'GER-20' => fn () => $this->rotatividade(),

            // ---- Zeladoria ----
            'ZEL-01' => fn () => $this->registrosPorDiaUtil('zel_limpeza'),
            'ZEL-02' => fn () => $this->banheiros(),
            'ZEL-03' => fn () => $this->mediaRegistros('zel_auditoria'),
            'ZEL-04' => fn () => $this->registrosPorDiaUtil('zel_cafe'),
            'ZEL-05' => fn () => IndicatorResult::value($this->registros('zel_falta_item')->count()),
            'ZEL-06' => fn () => IndicatorResult::value($this->registros('zel_reclamacao')->count()),

            // ---- Dono ----
            'DON-01' => fn () => $this->horasDoDono(),
            'DON-02' => fn () => IndicatorResult::value($this->registros('dono_prospeccao')->count()),
            'DON-03' => fn () => $this->novosClientes(),
            'DON-04' => fn () => $this->reunioes(['dono_reuniao_gerente'], semanais: true),
            'DON-05' => fn () => $this->acionadoForaDaAlcada(),
            'DON-06' => fn () => $this->alcadasEm24h(),

            // ---- DRE e precificação ----
            'DRE-01' => fn () => $this->razaoDaDre('margem_contribuicao_servico', 'receita_mo'),
            'DRE-02' => fn () => $this->razaoDaDre('margem_contribuicao_pecas', 'receita_pecas'),
            'DRE-03' => fn () => $this->razaoDaDre('resultado_operacional', 'faturamento'),
            'DRE-04' => fn () => $this->razaoDaDre('pessoal', 'faturamento'),
            'DRE-05' => fn () => $this->diaDoEquilibrio(),
            'DRE-06' => fn () => $this->horaSobreMinimo(),
            'DRE-07' => fn () => $this->pecasAbaixoDoMinimo(),
            'DRE-08' => fn () => IndicatorResult::value(Lancamento::semConta()->whereBetween('competencia', [$this->from->toDateString(), $this->to->toDateString()])->count()),
            'DRE-09' => fn () => $this->osComMargemNegativa(),
            'DRE-10' => fn () => $this->caixaSobreFixas(),
        ];
    }

    // =====================================================================
    // Primitivas
    // =====================================================================

    /**
     * OS do período e do escopo (sem as canceladas).
     */
    protected function osBase(): Builder
    {
        $query = ServiceOrder::query()
            ->where(fn ($q) => $q->whereNull('quotes.workshop_status')->orWhere('quotes.workshop_status', '!=', ServiceOrder::STAGE_CANCELADA));

        if (! empty($this->scope['user_id'])) {
            $query->where('quotes.user_id', $this->scope['user_id']);
        }

        if (! empty($this->scope['mechanic_id'])) {
            $mechanicId = $this->scope['mechanic_id'];

            $query->where(fn ($q) => $q->where('quotes.diagnostico_mechanic_id', $mechanicId)
                ->orWhere(fn ($inner) => $inner->whereNull('quotes.diagnostico_mechanic_id')->where('quotes.mechanic_id', $mechanicId)));
        }

        return $query;
    }

    /**
     * OS com um evento do tipo no período; guarda a hora do primeiro como
     * atributo "ancora" (2º parâmetro dos testes por OS).
     *
     * @return Collection<int, ServiceOrder>
     */
    protected function osComEvento(string|array $types): Collection
    {
        $rows = ServiceOrderEvent::query()
            ->whereIn('tipo_evento', (array) $types)
            ->whereBetween('ocorrido_em', [$this->from, $this->to])
            ->whereIn('service_order_id', $this->osBase()->select('quotes.id'))
            ->selectRaw('service_order_id, MIN(ocorrido_em) as primeiro')
            ->groupBy('service_order_id')
            ->get()
            ->pluck('primeiro', 'service_order_id');

        if ($rows->isEmpty()) {
            return collect();
        }

        return ServiceOrder::query()->with('items')->whereIn('id', $rows->keys())->get()
            ->each(fn (ServiceOrder $os) => $os->setAttribute('ancora', Carbon::parse($rows[$os->id])))
            ->values();
    }

    /**
     * Fração das OS que passam no teste. O teste recebe a OS e a hora do evento âncora.
     *
     * @param  Collection<int, ServiceOrder>  $os
     */
    protected function taxa(Collection $os, Closure $test): IndicatorResult
    {
        if ($os->isEmpty()) {
            return IndicatorResult::none();
        }

        $passed = $os->filter(fn ($item) => (bool) $test($item, $item->ancora ?? null));

        return IndicatorResult::ratio($passed->count(), $os->count(), $os->pluck('id')->all());
    }

    /**
     * Indicador de prazo: lê as instâncias da barreira. Cumprida no prazo
     * conta; fora do prazo, ou vencida e ainda aberta, não conta.
     */
    protected function barrier(string $code, bool $porOs = false): IndicatorResult
    {
        $instances = BarrierInstance::query()
            ->where('barrier_code', $code)
            ->whereBetween('vence_em', [$this->from, $this->to])
            ->whereIn('service_order_id', $this->osBase()->select('quotes.id'))
            ->where(fn ($q) => $q->where('status', BarrierInstance::STATUS_CUMPRIDA)->orWhere('vence_em', '<', now()))
            ->get();

        if ($instances->isEmpty()) {
            return IndicatorResult::none('Nenhuma barreira "'.$code.'" venceu no período.');
        }

        if ($porOs) {
            $groups = $instances->groupBy('service_order_id');
            $ok = $groups->filter(fn ($group) => $group->every(fn ($i) => $i->cumprida_no_prazo === true));

            return IndicatorResult::ratio($ok->count(), $groups->count(), $groups->keys()->all());
        }

        return IndicatorResult::ratio(
            $instances->filter(fn ($i) => $i->cumprida_no_prazo === true)->count(),
            $instances->count(),
            $instances->pluck('service_order_id')->unique()->values()->all(),
        );
    }

    /**
     * Registros manuais vigentes do tipo, no período e no escopo.
     */
    protected function registros(string|array $types): Builder
    {
        $query = MentoriaRegistro::query()->vigentes()->doTipo($types)->noPeriodo($this->from, $this->to);

        // Registro sem colaborador vale para quem ocupa o cargo.
        if (! empty($this->scope['user_id'])) {
            $query->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $this->scope['user_id']));
        }

        if (! empty($this->scope['mechanic_id'])) {
            $query->where(fn ($q) => $q->whereNull('mechanic_id')->orWhere('mechanic_id', $this->scope['mechanic_id']));
        }

        return $query;
    }

    protected function taxaDeRegistros(string|array $types): IndicatorResult
    {
        $registros = $this->registros($types)->get();

        return $registros->isEmpty()
            ? IndicatorResult::none()
            : IndicatorResult::ratio($registros->where('ok', true)->count(), $registros->count());
    }

    protected function mediaRegistros(string $type): IndicatorResult
    {
        $valores = $this->registros($type)->whereNotNull('valor')->pluck('valor');

        return $valores->isEmpty() ? IndicatorResult::none() : IndicatorResult::value($valores->avg(), numerator: $valores->sum(), denominator: $valores->count());
    }

    /**
     * Registros "feito/não feito" por dia de funcionamento (relatório diário,
     * checklist de limpeza...): dias com registro ok ÷ dias úteis até hoje.
     */
    protected function registrosPorDiaUtil(string $type): IndicatorResult
    {
        $dias = $this->diasUteis();

        if ($dias === 0) {
            return IndicatorResult::none();
        }

        $comRegistro = $this->registros($type)->where('ok', true)->get()->map(fn ($r) => $r->ocorrido_em->toDateString())->unique()->count();

        return IndicatorResult::ratio(min($comRegistro, $dias), $dias);
    }

    protected function simNaoNoMes(string $type): IndicatorResult
    {
        if (! $this->periodoEncerrado() && ! $this->registros($type)->exists()) {
            return IndicatorResult::none('Aguardando o fim do prazo.');
        }

        return IndicatorResult::ratio($this->registros($type)->where('ok', true)->exists() ? 1 : 0, 1);
    }

    protected function simNaoNoTrimestre(string $type): IndicatorResult
    {
        $inicio = $this->to->copy()->firstOfQuarter();
        $fim = $this->to->copy()->lastOfQuarter()->endOfDay();

        $feito = MentoriaRegistro::vigentes()->doTipo($type)->noPeriodo($inicio, $fim)->where('ok', true)->exists();

        return IndicatorResult::ratio($feito ? 1 : 0, 1);
    }

    /**
     * Dias de funcionamento do período até hoje.
     */
    protected function diasUteis(): int
    {
        $ate = $this->to->lt(now()) ? $this->to : now();

        return $ate->lt($this->from) ? 0 : (new BusinessHours)->businessDaysBetween($this->from, $ate);
    }

    protected function semanas(): int
    {
        $ate = $this->to->lt(now()) ? $this->to : now();

        return max(1, (int) ceil(($this->from->diffInDays($ate) + 1) / 7));
    }

    protected function periodoEncerrado(): bool
    {
        return $this->to->lt(now());
    }

    /**
     * Operações concluídas no período, com tempo trabalhado e padrão.
     *
     * @return Collection<int, ServiceOrderItem>
     */
    protected function operacoesConcluidas(): Collection
    {
        $itemIds = ServiceOrderEvent::query()
            ->where('tipo_evento', 'operacao_fim')
            ->whereBetween('ocorrido_em', [$this->from, $this->to])
            ->whereIn('service_order_id', $this->osBase()->select('quotes.id'))
            ->get()
            ->map(fn ($event) => $event->dados_json['item_id'] ?? null)
            ->filter()
            ->unique();

        $items = ServiceOrderItem::query()->whereIn('id', $itemIds)->get();

        if (! empty($this->scope['mechanic_id'])) {
            $items = $items->where('funcionario_id', $this->scope['mechanic_id']);
        }

        return $items->each(function (ServiceOrderItem $item) {
            $item->setAttribute('segundos_reais', $this->operations->workedSeconds($item));
            $item->setAttribute('segundos_padrao', $this->operations->padraoSeconds($item));
        })->filter(fn ($item) => $item->segundos_padrao > 0)->values();
    }

    // =====================================================================
    // Consultor
    // =====================================================================

    protected function fichas(): IndicatorResult
    {
        $os = $this->osComEvento('ficha_enviada_chefe');

        return $this->taxa($os, fn ($item) => (int) $item->ficha_devolucoes === 0 && $item->termo_assinado_em);
    }

    /**
     * OS liberadas para execução no período (denominador de várias taxas).
     */
    protected function comLiberacao(Closure $test): IndicatorResult
    {
        return $this->taxa($this->osComEvento('execucao_liberada'), $test);
    }

    protected function aprovacaoAntesDaLiberacao(): IndicatorResult
    {
        return $this->comLiberacao(fn ($os, $liberada) => $os->aprovacao_em && $os->aprovacao_tipo !== 'recusa' && $os->aprovacao_em->lte($liberada));
    }

    protected function taxaAprovacao(): IndicatorResult
    {
        $os = $this->osComEvento('orcamento_enviado');
        $orcado = $os->sum(fn ($item) => (float) $item->grand_total);

        if ($orcado <= 0) {
            return IndicatorResult::none();
        }

        $aprovado = $os->filter(fn ($item) => in_array($item->aprovacao_tipo, ['total', 'parcial'], true))->sum(fn ($item) => (float) $item->grand_total);

        return IndicatorResult::ratio($aprovado, $orcado, $os->pluck('id')->all());
    }

    /**
     * Valor recebido em dia ÷ valor faturado a prazo (boletos que venceram no período).
     */
    protected function adimplencia(): IndicatorResult
    {
        $boletos = ServiceOrderBoleto::query()
            ->whereNotNull('vencimento')
            ->whereBetween('vencimento', [$this->from->toDateString(), $this->to->toDateString()])
            ->where('vencimento', '<=', now()->toDateString())
            ->where('status', '!=', 'Cancelado')
            ->whereIn('quote_id', $this->osBase()->select('quotes.id'))
            ->get();

        $total = $boletos->sum('valor');

        if ($total <= 0) {
            return IndicatorResult::none();
        }

        $emDia = $boletos->filter(fn ($b) => $b->status === 'Pago' && $b->updated_at && $b->updated_at->toDateString() <= Carbon::parse($b->vencimento)->toDateString())->sum('valor');

        return IndicatorResult::ratio($emDia, $total, $boletos->pluck('quote_id')->unique()->values()->all());
    }

    protected function recusasComRetorno(): IndicatorResult
    {
        $os = $this->osComEvento('cliente_recusou');

        if ($os->isEmpty()) {
            return IndicatorResult::none();
        }

        $events = new EventIndex($os->pluck('id')->all(), ['recontato_recusa']);

        return $this->taxa($os, function ($item, $recusa) use ($events) {
            $recontato = $events->first($item->id, 'recontato_recusa');

            return $recontato && $recontato->ocorrido_em->lte($recusa->copy()->addDays(7)->endOfDay());
        });
    }

    protected function notaMediaClientes(): IndicatorResult
    {
        $notas = $this->osComEvento('nota_cliente')->pluck('nota_recomendacao')->filter(fn ($n) => $n !== null);

        return $notas->isEmpty() ? IndicatorResult::none() : IndicatorResult::value($notas->avg(), numerator: $notas->sum(), denominator: $notas->count());
    }

    /**
     * Revisões que venceram no período e o cliente voltou (nova OS do mesmo
     * veículo até 15 dias da data prevista).
     */
    protected function retornoPreventiva(): IndicatorResult
    {
        $vencidas = $this->osBase()
            ->whereNotNull('proxima_revisao_data')
            ->whereBetween('proxima_revisao_data', [$this->from->toDateString(), $this->to->toDateString()])
            ->whereNotNull('vehicle_id')
            ->get();

        return $this->taxa($vencidas, function ($os) {
            $data = Carbon::parse($os->proxima_revisao_data);

            return ServiceOrder::withoutGlobalScopes()
                ->where('vehicle_id', $os->vehicle_id)
                ->where('id', '!=', $os->id)
                ->whereBetween('created_at', [$data->copy()->subDays(15)->startOfDay(), $data->copy()->addDays(15)->endOfDay()])
                ->exists();
        });
    }

    // =====================================================================
    // Chefe e técnico
    // =====================================================================

    protected function orcamentoSemCorrecao(): IndicatorResult
    {
        $os = $this->osComEvento('orcamento_enviado');
        $events = new EventIndex($os->pluck('id')->all(), ['orcamento_corrigido']);

        return $this->taxa($os, fn ($item) => ! $events->has($item->id, 'orcamento_corrigido'));
    }

    protected function aderenciaAoTempo(): IndicatorResult
    {
        $ops = $this->operacoesConcluidas();
        $margem = (float) WorkshopSettings::get('tecnico_margem_percentual', 120) / 100;

        if ($ops->isEmpty()) {
            return IndicatorResult::none();
        }

        return IndicatorResult::ratio(
            $ops->filter(fn ($i) => $i->segundos_reais <= $i->segundos_padrao * $margem)->count(),
            $ops->count(),
            $ops->pluck('quote_id')->unique()->values()->all(),
        );
    }

    protected function eficiencia(): IndicatorResult
    {
        $ops = $this->operacoesConcluidas();
        $reais = $ops->sum('segundos_reais');

        if ($ops->isEmpty() || $reais <= 0) {
            return IndicatorResult::none();
        }

        return IndicatorResult::ratio($ops->sum('segundos_padrao'), $reais, $ops->pluck('quote_id')->unique()->values()->all());
    }

    /**
     * Retornos (OS de retorno/garantia) abertos no período.
     *
     * @return Collection<int, ServiceOrder>
     */
    protected function retornos(): Collection
    {
        return ServiceOrder::query()
            ->whereIn('tipo_os', ['retorno', 'garantia'])
            ->whereBetween('created_at', [$this->from, $this->to])
            ->get();
    }

    /**
     * Retrabalho: retornos por falha de execução ÷ OS entregues no período.
     */
    protected function retrabalho(): IndicatorResult
    {
        $entregues = $this->osComEvento('saida_veiculo')->count();

        if ($entregues === 0) {
            return IndicatorResult::none();
        }

        $retornos = $this->retornos()->where('retorno_causa', 'execucao');

        return IndicatorResult::ratio($retornos->count(), $entregues, $retornos->pluck('id')->all());
    }

    protected function produtividade(): IndicatorResult
    {
        $os = $this->osBase()->with('items')->whereBetween('quotes.faturado_em', [$this->from, $this->to])->get();
        $horasPadrao = $os->sum(fn ($o) => $o->items->where('tipo', ServiceOrderItem::TIPO_SERVICO)->sum(fn ($i) => (float) $i->hours));

        $tecnicos = Mechanic::where('status', 'Ativo')->count();
        $ate = $this->to->lt(now()) ? $this->to : now();
        $disponiveis = (new BusinessHours)->diffMinutes($this->from, $ate) / 60 * $tecnicos;

        if ($disponiveis <= 0) {
            return IndicatorResult::none();
        }

        return IndicatorResult::ratio($horasPadrao, $disponiveis, $os->pluck('id')->all());
    }

    protected function laudoCompleto(ServiceOrder $os): bool
    {
        $tipos = $os->laudoItems()->pluck('tipo')->unique();

        return $tipos->contains('codigo') && $tipos->contains('medicao') && $tipos->contains('foto');
    }

    protected function laudosSemDevolucao(): IndicatorResult
    {
        $os = $this->osComEvento('diagnostico_fim');
        $events = new EventIndex($os->pluck('id')->all(), ['diagnostico_auditado']);

        return $this->taxa($os, function ($item) use ($events) {
            $auditorias = $events->all($item->id, 'diagnostico_auditado');

            return $auditorias->isNotEmpty() && $auditorias->every(fn ($e) => ($e->dados_json['status'] ?? null) === 'aprovado');
        });
    }

    /**
     * Diagnósticos sem peça trocada a mais por erro e sem retorno pelo mesmo
     * defeito em 30 dias.
     */
    protected function assertividade(): IndicatorResult
    {
        $os = $this->osComEvento('diagnostico_fim');

        return $this->taxa($os, function ($item, $fim) {
            $retornoPorDiagnostico = ServiceOrder::query()
                ->where('os_origem_id', $item->id)
                ->where('retorno_causa', 'diagnostico')
                ->where('created_at', '<=', $fim->copy()->addDays(30))
                ->exists();

            $pecaErrada = MentoriaRegistro::vigentes()->doTipo('peca_errada')->where('service_order_id', $item->id)->exists();

            return ! $retornoPorDiagnostico && ! $pecaErrada;
        });
    }

    protected function relacaoCorreta(): IndicatorResult
    {
        $os = $this->osComEvento('relacao_pecas_entregue');
        $events = new EventIndex($os->pluck('id')->all(), ['orcamento_corrigido']);

        return $this->taxa($os, fn ($item) => ! $events->all($item->id, 'orcamento_corrigido')->contains(fn ($e) => ($e->dados_json['quem'] ?? null) === 'pecas'));
    }

    protected function retornoDeServico(): IndicatorResult
    {
        $os = $this->osComEvento('operacao_fim');

        if ($os->isEmpty()) {
            return IndicatorResult::none();
        }

        $retornos = ServiceOrder::query()
            ->whereIn('os_origem_id', $os->pluck('id'))
            ->where('retorno_causa', 'execucao')
            ->get()
            ->filter(fn ($retorno) => $retorno->created_at->lte($os->firstWhere('id', $retorno->os_origem_id)->ancora->copy()->addDays(30)));

        return IndicatorResult::ratio($retornos->count(), $os->count(), $retornos->pluck('id')->all());
    }

    protected function pecasVelhasComTermo(): IndicatorResult
    {
        $os = $this->osComEvento('operacao_fim')->filter(fn ($item) => $item->items->where('tipo', ServiceOrderItem::TIPO_PECA)->isNotEmpty());
        $events = new EventIndex($os->pluck('id')->all(), ['termo_pecas_velhas']);

        return $this->taxa($os, fn ($item) => $events->has($item->id, 'termo_pecas_velhas'));
    }

    protected function apontamentoCompleto(): IndicatorResult
    {
        $os = $this->osComEvento('execucao_liberada');

        if ($os->isEmpty()) {
            return IndicatorResult::none();
        }

        $events = new EventIndex($os->pluck('id')->all(), ['operacao_inicio', 'operacao_fim']);
        $operacoes = $os->flatMap(fn ($item) => $item->items->where('tipo', ServiceOrderItem::TIPO_SERVICO)->map(fn ($op) => [$item->id, $op]));

        if (! empty($this->scope['mechanic_id'])) {
            $operacoes = $operacoes->filter(fn ($par) => $par[1]->funcionario_id == $this->scope['mechanic_id']);
        }

        if ($operacoes->isEmpty()) {
            return IndicatorResult::none();
        }

        $completas = $operacoes->filter(function ($par) use ($events) {
            [$osId, $op] = $par;
            $comItem = fn ($tipo) => $events->all($osId, $tipo)->contains(fn ($e) => ($e->dados_json['item_id'] ?? null) == $op->id);

            return $comItem('operacao_inicio') && $comItem('operacao_fim');
        });

        return IndicatorResult::ratio($completas->count(), $operacoes->count(), $os->pluck('id')->all());
    }

    // =====================================================================
    // Peças
    // =====================================================================

    protected function pecaCerta(): IndicatorResult
    {
        $itens = ServiceOrderEvent::where('tipo_evento', 'peca_precificada')->whereBetween('ocorrido_em', [$this->from, $this->to])->count();

        if ($itens === 0) {
            return IndicatorResult::none();
        }

        $erros = min($itens, $this->registros('peca_errada')->count());

        return IndicatorResult::ratio($itens - $erros, $itens);
    }

    protected function precoMantido(): IndicatorResult
    {
        $os = $this->osComEvento(['cliente_aprovou', 'cliente_aprovou_parcial']);
        $events = new EventIndex($os->pluck('id')->all(), ['orcamento_corrigido']);

        return $this->taxa($os, fn ($item, $aprovou) => ! $events->all($item->id, 'orcamento_corrigido')->contains(fn ($e) => $e->ocorrido_em->gt($aprovou)));
    }

    protected function entregaNoPrazo(): IndicatorResult
    {
        $entregas = ServiceOrderEvent::where('tipo_evento', 'peca_entregue_tecnico')
            ->whereBetween('ocorrido_em', [$this->from, $this->to])
            ->get()
            ->filter(fn ($e) => ! empty($e->dados_json['prazo_entrega_em']));

        if ($entregas->isEmpty()) {
            return IndicatorResult::none();
        }

        return IndicatorResult::ratio(
            $entregas->filter(fn ($e) => $e->ocorrido_em->lte(Carbon::parse($e->dados_json['prazo_entrega_em'])))->count(),
            $entregas->count(),
            $entregas->pluck('service_order_id')->unique()->values()->all(),
        );
    }

    protected function conferenciaDePecas(): IndicatorResult
    {
        $os = $this->osBase()->with('items')->whereBetween('quotes.faturado_em', [$this->from, $this->to])->get()
            ->filter(fn ($item) => $item->items->where('tipo', ServiceOrderItem::TIPO_PECA)->isNotEmpty())->values();

        $events = new EventIndex($os->pluck('id')->all(), ['pecas_conferidas']);

        return $this->taxa($os, function ($item) use ($events) {
            $conferencia = $events->last($item->id, 'pecas_conferidas');

            return $conferencia && empty($conferencia->dados_json['divergencia']);
        });
    }

    /**
     * Registros com duas datas em dados_json: o intervalo entre elas cabe no limite?
     */
    protected function prazoEntreDatas(string $type, string $inicio, string $fim, int $limiteMinutos): IndicatorResult
    {
        $registros = $this->registros($type)->get()->filter(fn ($r) => ! empty($r->dados_json[$inicio]) && ! empty($r->dados_json[$fim]));

        if ($registros->isEmpty()) {
            return IndicatorResult::none();
        }

        $noPrazo = $registros->filter(function ($r) use ($inicio, $fim, $limiteMinutos) {
            $a = Carbon::parse($r->dados_json[$inicio]);
            $b = Carbon::parse($r->dados_json[$fim]);

            // Datas sem hora valem até o fim do dia.
            if (strlen((string) $r->dados_json[$fim]) <= 10) {
                $b = $b->endOfDay();
            }

            return $a->diffInMinutes($b, false) <= $limiteMinutos;
        });

        return IndicatorResult::ratio($noPrazo->count(), $registros->count());
    }

    protected function acuracidade(): IndicatorResult
    {
        $registros = $this->registros('inventario_rotativo')->get();
        $contados = $registros->sum(fn ($r) => (int) ($r->dados_json['contados'] ?? 0));

        return $contados > 0
            ? IndicatorResult::ratio($registros->sum(fn ($r) => (int) ($r->dados_json['corretos'] ?? 0)), $contados)
            : IndicatorResult::none();
    }

    protected function inventarioSemanal(): IndicatorResult
    {
        $semanas = $this->registros('inventario_rotativo')->get()->map(fn ($r) => $r->ocorrido_em->format('o-W'))->unique()->count();

        return IndicatorResult::ratio(min($semanas, $this->semanas()), $this->semanas());
    }

    /**
     * Valor do estoque sem nenhuma movimentação há mais de 180 dias ÷ valor do estoque.
     */
    protected function pecasParadas(): IndicatorResult
    {
        $limite = now()->subDays(180);

        $linhas = DB::table('part_inventories as pi')
            ->join('parts as p', 'p.id', '=', 'pi.part_id')
            ->leftJoin('part_stock_movements as m', 'm.part_inventory_id', '=', 'pi.id')
            ->groupBy('pi.id', 'pi.in_stock', 'pi.created_at', 'p.valor_unitario')
            ->selectRaw('pi.in_stock, p.valor_unitario, COALESCE(MAX(m.created_at), pi.created_at) as ultimo')
            ->get();

        $total = $linhas->sum(fn ($l) => $l->in_stock * (float) $l->valor_unitario);

        if ($total <= 0) {
            return IndicatorResult::none();
        }

        $parado = $linhas->filter(fn ($l) => Carbon::parse($l->ultimo)->lt($limite))->sum(fn ($l) => $l->in_stock * (float) $l->valor_unitario);

        return IndicatorResult::ratio($parado, $total);
    }

    protected function margemDePecas(): IndicatorResult
    {
        $itens = ServiceOrderItem::query()
            ->where('tipo', ServiceOrderItem::TIPO_PECA)
            ->whereNotNull('custo_unitario')
            ->whereIn('quote_id', $this->osBase()->whereBetween('quotes.faturado_em', [$this->from, $this->to])->select('quotes.id'))
            ->get();

        $venda = $itens->sum(fn ($i) => $i->line_total);
        $custo = $itens->sum(fn ($i) => (float) $i->custo_unitario * (float) $i->quantity);

        return $venda > 0 ? IndicatorResult::ratio($venda - $custo, $venda, $itens->pluck('quote_id')->unique()->values()->all()) : IndicatorResult::none();
    }

    // =====================================================================
    // Financeiro
    // =====================================================================

    protected function nfAntesDaSaida(): IndicatorResult
    {
        $os = $this->osComEvento('saida_veiculo')->filter(fn ($item) => ! $item->isFrotaFaturamentoMensal())->values();
        $events = new EventIndex($os->pluck('id')->all(), ['saida_veiculo']);

        return $this->taxa($os, fn ($item) => empty($events->first($item->id, 'saida_veiculo')?->dados_json['sem_nf']));
    }

    protected function boletoNoDia(): IndicatorResult
    {
        $os = $this->osComEvento('boleto_ou_cobranca_gerada');
        $events = new EventIndex($os->pluck('id')->all(), ['boleto_ou_cobranca_gerada']);

        return $this->taxa($os, fn ($item) => $item->faturado_em && $events->first($item->id, 'boleto_ou_cobranca_gerada')->ocorrido_em->isSameDay($item->faturado_em));
    }

    protected function caixaSemDiferenca(): IndicatorResult
    {
        $registros = $this->registros('caixa_dia')->get();

        if ($registros->isEmpty()) {
            return IndicatorResult::none();
        }

        // Sem diferença, ou diferença justificada.
        return IndicatorResult::ratio($registros->filter(fn ($r) => abs((float) $r->valor) < 0.005 || $r->ok)->count(), $registros->count());
    }

    protected function notasComErro(): IndicatorResult
    {
        $emitidas = FiscalDocument::query()->whereBetween('created_at', [$this->from, $this->to])->count();

        if ($emitidas === 0) {
            return IndicatorResult::none();
        }

        $comErro = FiscalDocument::query()->whereBetween('created_at', [$this->from, $this->to])
            ->whereIn('status', [FiscalDocument::STATUS_CANCELADO, FiscalDocument::STATUS_ERRO])->count();

        return IndicatorResult::ratio($comErro, $emitidas);
    }

    protected function inadimplencia(): IndicatorResult
    {
        $boletos = ServiceOrderBoleto::query()
            ->where('status', '!=', 'Cancelado')
            ->whereBetween('created_at', [$this->from, $this->to])
            ->get();

        $total = $boletos->sum('valor');

        if ($total <= 0) {
            return IndicatorResult::none();
        }

        $vencido = $boletos->filter(fn ($b) => $b->status === 'Pendente' && $b->vencimento && Carbon::parse($b->vencimento)->lt(now()->startOfDay()))->sum('valor');

        return IndicatorResult::ratio($vencido, $total, $boletos->pluck('quote_id')->unique()->values()->all());
    }

    // =====================================================================
    // Gerente, zeladoria e dono
    // =====================================================================

    protected function faturamentoContraMeta(): IndicatorResult
    {
        $meta = (float) WorkshopSettings::get('meta_faturamento_mensal', 0);

        if ($meta <= 0) {
            return IndicatorResult::none('Informe a meta de faturamento do mês na Ficha de Enquadramento.');
        }

        $os = $this->osBase()->whereBetween('quotes.faturado_em', [$this->from, $this->to])->get();

        return IndicatorResult::ratio($os->sum(fn ($o) => (float) $o->grand_total), $meta, $os->pluck('id')->all());
    }

    protected function permanenciaMedia(): IndicatorResult
    {
        $os = $this->osComEvento('saida_veiculo');

        if ($os->isEmpty()) {
            return IndicatorResult::none();
        }

        $dias = $os->map(fn ($o) => max(0, $o->created_at->diffInHours($o->saida_em ?? $o->ancora, false)) / 24);

        return IndicatorResult::value($dias->avg(), $os->pluck('id')->all(), $dias->sum(), $dias->count());
    }

    /**
     * Reuniões realizadas ÷ previstas (dias úteis, semanas, 1 por mês).
     *
     * @param  string[]  $types
     */
    protected function reunioes(array $types, bool $semanais = false): IndicatorResult
    {
        $previstas = 0;

        foreach ($types as $type) {
            $previstas += match ($type) {
                'reuniao_diaria' => $this->diasUteis(),
                'reuniao_semanal', 'dono_reuniao_gerente' => $this->semanas(),
                'reuniao_mensal' => 1,
                default => 0,
            };
        }

        // Reunião do dono com o gerente: semanais + a mensal.
        if ($semanais) {
            $previstas += 1;
        }

        if ($previstas === 0) {
            return IndicatorResult::none();
        }

        $feitas = $this->registros($types)->where('ok', true)->count();

        return IndicatorResult::ratio(min($feitas, $previstas), $previstas);
    }

    protected function planosNoPrazo(): IndicatorResult
    {
        $planos = MentoriaRegistro::vigentes()->doTipo('plano_acao')->get()->filter(function ($plano) {
            $prazo = $plano->dados_json['prazo'] ?? null;

            return $prazo && Carbon::parse($prazo)->between($this->from, $this->to) && Carbon::parse($prazo)->endOfDay()->lt(now());
        });

        if ($planos->isEmpty()) {
            return IndicatorResult::none();
        }

        $concluidos = MentoriaRegistro::vigentes()->doTipo('plano_acao_concluido')->get();

        $noPrazo = $planos->filter(fn ($plano) => $concluidos->contains(fn ($c) => (int) ($c->dados_json['plano_id'] ?? 0) === $plano->id
            && $c->ocorrido_em->lte(Carbon::parse($plano->dados_json['prazo'])->endOfDay())));

        return IndicatorResult::ratio($noPrazo->count(), $planos->count());
    }

    protected function reclamacoesEm48h(): IndicatorResult
    {
        $reclamacoes = DB::table('service_order_reclamacoes')->whereBetween('aberta_em', [$this->from, $this->to])->get()
            ->filter(fn ($r) => $r->fechada_em || Carbon::parse($r->aberta_em)->addHours(48)->lt(now()));

        if ($reclamacoes->isEmpty()) {
            return IndicatorResult::none();
        }

        $noPrazo = $reclamacoes->filter(fn ($r) => $r->fechada_em && Carbon::parse($r->aberta_em)->diffInHours(Carbon::parse($r->fechada_em)) <= 48);

        return IndicatorResult::ratio($noPrazo->count(), $reclamacoes->count(), $reclamacoes->pluck('quote_id')->unique()->values()->all());
    }

    protected function auditoriasFeitas(): IndicatorResult
    {
        $combinadas = (int) WorkshopSettings::get('auditorias_amostragem_mes', 4);

        if ($combinadas <= 0) {
            return IndicatorResult::none();
        }

        return IndicatorResult::ratio(min($combinadas, $this->registros('auditoria_amostragem')->where('ok', true)->count()), $combinadas);
    }

    /**
     * Painel do Dono consolidado até o 5º dia útil do mês seguinte (sim/não).
     */
    protected function painelNoPrazo(): IndicatorResult
    {
        $periodo = $this->from->format('Y-m');
        $limite = BusinessHours::nthWeekday($this->from->copy()->addMonth(), 5);

        $registro = MentoriaRegistro::vigentes()->doTipo('painel_consolidado')->get()
            ->first(fn ($r) => ($r->dados_json['periodo'] ?? null) === $periodo);

        if (! $registro) {
            return $limite->lt(now()) ? IndicatorResult::ratio(0, 1) : IndicatorResult::none('Aguardando o fechamento do mês.');
        }

        return IndicatorResult::ratio($registro->ocorrido_em->lte($limite) ? 1 : 0, 1);
    }

    protected function folhaNoPrazo(): IndicatorResult
    {
        $variaveis = $this->registros('folha_variaveis')->where('ok', true)->exists();
        $folha = $this->registros('folha_fechada')->where('ok', true)->exists();

        if (! $variaveis && ! $folha && ! $this->periodoEncerrado()) {
            return IndicatorResult::none('Aguardando o fechamento da folha.');
        }

        return IndicatorResult::ratio($variaveis && $folha ? 1 : 0, 1);
    }

    /**
     * Quadro de pessoal: o cadastro de colaboradores; sem ele, os usuários ativos.
     */
    protected function quadro(): int
    {
        return Colaborador::ativos()->exists() ? Colaborador::ativos()->count() : DB::table('users')->where('status', 1)->count();
    }

    /**
     * GER-13: admissões do período registradas antes do início (cadastro de
     * colaboradores; sem cadastro, os registros de admissão).
     */
    protected function admissoesComRegistro(): IndicatorResult
    {
        if (! Colaborador::exists()) {
            return $this->taxaDeRegistros('admissao');
        }

        $admitidos = Colaborador::whereBetween('admissao', [$this->from->toDateString(), $this->to->toDateString()])->get();

        return $admitidos->isEmpty()
            ? IndicatorResult::none()
            : IndicatorResult::ratio($admitidos->where('registro_antes_inicio', true)->count(), $admitidos->count());
    }

    /**
     * GER-15: colaboradores ativos com as férias vencidas.
     */
    protected function feriasVencidas(): IndicatorResult
    {
        if (! Colaborador::ativos()->exists()) {
            return IndicatorResult::value($this->registros('ferias_vencida')->count());
        }

        $referencia = $this->to->lt(now()) ? $this->to->toDateString() : now()->toDateString();

        return IndicatorResult::value(Colaborador::ativos()->whereNotNull('ferias_vencimento')->where('ferias_vencimento', '<', $referencia)->count());
    }

    /**
     * GER-16: colaboradores com exame periódico e ficha de EPI em dia.
     */
    protected function examesEEpiEmDia(): IndicatorResult
    {
        if (! Colaborador::ativos()->exists()) {
            return $this->taxaDeRegistros('rh_em_dia');
        }

        $ativos = Colaborador::ativos()->get();

        return IndicatorResult::ratio($ativos->filter->exameEEpiEmDia()->count(), $ativos->count());
    }

    protected function absenteismo(): IndicatorResult
    {
        $colaboradores = $this->quadro();
        $ate = $this->to->lt(now()) ? $this->to : now();
        $previstas = (new BusinessHours)->diffMinutes($this->from, $ate) / 60 * $colaboradores;

        if ($previstas <= 0) {
            return IndicatorResult::none();
        }

        return IndicatorResult::ratio((float) $this->registros('falta_horas')->sum('valor'), $previstas);
    }

    /**
     * GER-20: desligamentos do período ÷ quadro médio (quadro de hoje mais
     * metade dos que saíram no período).
     */
    protected function rotatividade(): IndicatorResult
    {
        $desligados = Colaborador::whereBetween('desligamento_em', [$this->from->toDateString(), $this->to->toDateString()])->count();

        if (! Colaborador::exists()) {
            $desligados = $this->registros('desligamento')->count();
        }

        $quadroMedio = $this->quadro() + $desligados / 2;

        return $quadroMedio > 0 ? IndicatorResult::ratio($desligados, $quadroMedio) : IndicatorResult::none();
    }

    protected function banheiros(): IndicatorResult
    {
        $previstos = $this->diasUteis() * max(1, (int) WorkshopSettings::get('zel_banheiros_por_dia', 3));

        if ($previstos === 0) {
            return IndicatorResult::none();
        }

        return IndicatorResult::ratio(min($previstos, $this->registros('zel_banheiro')->where('ok', true)->count()), $previstos);
    }

    protected function horasDoDono(): IndicatorResult
    {
        $horas = (float) $this->registros('dono_prospeccao')->sum('valor');

        if ($horas <= 0 && ! $this->periodoEncerrado()) {
            return IndicatorResult::none();
        }

        return IndicatorResult::value($horas / $this->semanas(), numerator: $horas, denominator: $this->semanas());
    }

    // =====================================================================
    // Financeiro, DRE e precificação
    // =====================================================================

    protected function periodoDoIndicador(): string
    {
        return $this->from->format('Y-m');
    }

    /**
     * Uma linha da DRE sobre outra, em % (ex.: margem de serviço ÷ receita de serviço).
     */
    protected function razaoDaDre(string $numerador, string $denominador): IndicatorResult
    {
        $dre = $this->dre->compute($this->periodoDoIndicador());

        return $dre[$denominador] > 0 ? IndicatorResult::ratio($dre[$numerador], $dre[$denominador]) : IndicatorResult::none('Sem faturamento no mês.');
    }

    /**
     * DRE-05: dia do mês em que o faturamento cobriu o ponto de equilíbrio.
     */
    protected function diaDoEquilibrio(): IndicatorResult
    {
        $equilibrio = $this->dre->pontoEquilibrio($this->periodoDoIndicador());

        if (! $equilibrio['ponto_equilibrio']) {
            return IndicatorResult::none('Sem dados para o ponto de equilíbrio.');
        }

        if ($equilibrio['dia_atingido']) {
            return IndicatorResult::value($equilibrio['dia_atingido']);
        }

        // Mês encerrado sem atingir: conta como o dia seguinte ao último.
        return $this->periodoEncerrado()
            ? new IndicatorResult((float) ($this->from->daysInMonth + 1), null, null, [], 'Ponto de equilíbrio não atingido no mês.')
            : IndicatorResult::none('Ponto de equilíbrio ainda não atingido.');
    }

    /**
     * DRE-06: hora cobrada ÷ preço mínimo da hora.
     */
    protected function horaSobreMinimo(): IndicatorResult
    {
        $hora = $this->dre->precoMinimoHora($this->periodoDoIndicador());

        return $hora['hora_cobrada_sobre_minimo'] === null
            ? IndicatorResult::none('Informe o valor da hora e os parâmetros financeiros na Ficha.')
            : IndicatorResult::ratio($hora['hora_cobrada'], $hora['preco_minimo']);
    }

    /**
     * DRE-07: peças precificadas abaixo do preço mínimo sem aprovação na fila de alçadas.
     */
    protected function pecasAbaixoDoMinimo(): IndicatorResult
    {
        $itens = ServiceOrderItem::query()
            ->where('tipo', ServiceOrderItem::TIPO_PECA)
            ->whereBetween('precificada_em', [$this->from, $this->to])
            ->where('custo_unitario', '>', 0)
            ->get();

        $abaixo = $itens->filter(function ($item) {
            $minimo = $this->dre->precoMinimoPeca((float) $item->custo_unitario);

            if ($minimo === null || (float) $item->price >= $minimo) {
                return false;
            }

            return ! AlcadaPedido::where('tema', 'preco_abaixo_minimo')
                ->where('service_order_id', $item->quote_id)
                ->whereIn('status', [AlcadaPedido::APROVADO, AlcadaPedido::CONSUMIDO])
                ->exists();
        });

        return IndicatorResult::value($abaixo->count(), $abaixo->pluck('quote_id')->unique()->values()->all());
    }

    /**
     * DRE-09: OS com margem negativa ÷ OS faturadas.
     */
    protected function osComMargemNegativa(): IndicatorResult
    {
        $margens = $this->dre->margemPorOs($this->periodoDoIndicador());

        return $margens->isEmpty()
            ? IndicatorResult::none()
            : IndicatorResult::ratio($margens->where('margem', '<', 0)->count(), $margens->count(), $margens->where('margem', '<', 0)->pluck('os')->all());
    }

    /**
     * DRE-10: caixa projetado em 30 dias ÷ despesas fixas.
     */
    protected function caixaSobreFixas(): IndicatorResult
    {
        $fixas = $this->dre->compute($this->periodoDoIndicador())['despesas_fixas'];

        return $fixas > 0 ? IndicatorResult::ratio($this->dre->caixaProjetado()['em_30_dias'], $fixas) : IndicatorResult::none('Sem despesas fixas lançadas.');
    }

    /**
     * GER-03: resultado final do mês ÷ meta do orçamento anual.
     */
    protected function resultadoContraMeta(): IndicatorResult
    {
        $meta = $this->dre->orcamento((int) $this->from->format('Y'), (int) $this->from->format('n'))['resultado_final'] ?? null;

        if (! $meta || $meta <= 0) {
            return IndicatorResult::none('Informe a meta de resultado no orçamento anual.');
        }

        return IndicatorResult::ratio($this->dre->compute($this->periodoDoIndicador())['resultado_final'], $meta);
    }

    /**
     * FIN-11: contatos da régua de cobrança (dias 1, 7 e 15 após o vencimento)
     * feitos ÷ contatos previstos no período.
     */
    protected function reguaCumprida(): IndicatorResult
    {
        $feitos = MentoriaRegistro::vigentes()->doTipo('cobranca_contato')->get()
            ->map(fn ($r) => ($r->dados_json['boleto_id'] ?? 0).':'.(int) $r->valor)->flip();

        $previstos = 0;
        $cumpridos = 0;

        $boletos = ServiceOrderBoleto::query()->where('status', '!=', 'Cancelado')->whereNotNull('vencimento')
            ->whereBetween('vencimento', [$this->from->copy()->subDays(15)->toDateString(), $this->to->toDateString()])->get();

        foreach ($boletos as $boleto) {
            foreach ([1, 7, 15] as $dia) {
                $prazo = Carbon::parse($boleto->vencimento)->addDays($dia);

                if (! $prazo->between($this->from, $this->to) || $prazo->gt(now())) {
                    continue;
                }

                // Pago antes do dia do contato: não há o que cobrar.
                if ($boleto->status === 'Pago' && $boleto->updated_at && $boleto->updated_at->lt($prazo->copy()->startOfDay())) {
                    continue;
                }

                $previstos++;

                if ($feitos->has($boleto->id.':'.$dia)) {
                    $cumpridos++;
                }
            }
        }

        return $previstos > 0 ? IndicatorResult::ratio($cumpridos, $previstos) : IndicatorResult::none();
    }

    /**
     * DON-05: ações do dono dentro do fluxo da OS + pedidos de alçada que ele
     * decidiu sem terem passado pelo gerente (os que têm OS já geram evento).
     */
    protected function acionadoForaDaAlcada(): IndicatorResult
    {
        $acoes = ServiceOrderEvent::where('tipo_evento', 'acao_dono_fora_fluxo')->whereBetween('ocorrido_em', [$this->from, $this->to])->count();

        $semOs = AlcadaPedido::where('sem_gerente', true)->whereNull('service_order_id')->whereBetween('decidido_em', [$this->from, $this->to])->count();

        return IndicatorResult::value($acoes + $semOs + $this->registros('dono_acionado')->count());
    }

    /**
     * DON-06: decisões do dono em até 24 h ÷ pedidos que chegaram a ele.
     * Pedido ainda sem resposta depois das 24 h conta como fora do prazo.
     */
    protected function alcadasEm24h(): IndicatorResult
    {
        $pedidos = AlcadaPedido::query()
            ->whereNotNull('encaminhado_em')
            ->whereBetween('encaminhado_em', [$this->from, $this->to])
            ->get()
            ->filter(fn ($p) => $p->decidido_em || $p->encaminhado_em->copy()->addHours(24)->lt(now()));

        if ($pedidos->isEmpty()) {
            return IndicatorResult::none();
        }

        $noPrazo = $pedidos->filter(fn ($p) => $p->decidido_em && $p->decidido_em->lte($p->encaminhado_em->copy()->addHours(24)));

        return IndicatorResult::ratio($noPrazo->count(), $pedidos->count());
    }

    /**
     * Clientes cuja 1ª OS foi aberta no período.
     */
    protected function novosClientes(): IndicatorResult
    {
        $novos = ServiceOrder::withoutGlobalScopes()
            ->whereBetween('created_at', [$this->from, $this->to])
            ->whereNotNull('person_id')
            ->get()
            ->pluck('person_id')
            ->unique()
            ->filter(fn ($personId) => ! ServiceOrder::withoutGlobalScopes()->where('person_id', $personId)->where('created_at', '<', $this->from)->exists());

        return IndicatorResult::value($novos->count());
    }
}
