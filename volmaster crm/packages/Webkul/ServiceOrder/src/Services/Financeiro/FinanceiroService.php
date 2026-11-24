<?php

namespace Webkul\ServiceOrder\Services\Financeiro;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Webkul\ServiceOrder\Models\AlcadaPedido;
use Webkul\ServiceOrder\Models\Lancamento;
use Webkul\ServiceOrder\Models\MentoriaRegistro;
use Webkul\ServiceOrder\Models\PlanoConta;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderBoleto;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Models\ServiceOrderItem;
use Webkul\ServiceOrder\Services\Alcada\AlcadaService;
use Webkul\ServiceOrder\Services\AlertService;
use Webkul\ServiceOrder\Services\Indicators\PainelDonoService;
use Webkul\ServiceOrder\Services\WorkshopSettings;
use Webkul\User\Models\User;

/**
 * Rotina do financeiro: contas a pagar classificadas no plano de contas,
 * conciliação, fechamento do caixa, régua de cobrança e o relatório diário ao
 * dono (capítulo 9.1). Cada gesto grava o registro que alimenta os
 * indicadores FIN-xx.
 */
class FinanceiroService
{
    public const REGUA_DIAS = [1, 7, 15];

    public function __construct(
        protected AlertService $alerts,
        protected AlcadaService $alcadas,
        protected PainelDonoService $painel,
    ) {}

    public function criar(array $dados, ?User $user = null): Lancamento
    {
        $vencimento = Carbon::parse($dados['vencimento']);

        return Lancamento::create([
            'descricao' => $dados['descricao'],
            'valor' => (float) $dados['valor'],
            'vencimento' => $vencimento,
            'competencia' => ! empty($dados['competencia']) ? Carbon::parse($dados['competencia']) : $vencimento->copy()->startOfMonth(),
            'conta_id' => $dados['conta_id'] ?? null,
            'service_order_id' => $dados['service_order_id'] ?? null,
            'parceiro' => $dados['parceiro'] ?? null,
            'programada' => (bool) ($dados['programada'] ?? true),
            'pagamento_pecas' => (bool) ($dados['pagamento_pecas'] ?? false),
            'created_by' => $user?->id ?? auth()->guard('user')->id(),
        ]);
    }

    public function classificar(Lancamento $lancamento, int $contaId): Lancamento
    {
        if (! PlanoConta::where('ativo', true)->whereKey($contaId)->exists()) {
            throw ValidationException::withMessages(['conta_id' => ['Escolha uma conta do plano de contas.']]);
        }

        $lancamento->update(['conta_id' => $contaId]);

        return $lancamento->refresh();
    }

    /**
     * Pagamento. Despesa avulsa (não programada) acima do que o financeiro
     * decide sozinho vai para a fila de alçadas e fica bloqueada.
     *
     * @throws ValidationException
     */
    public function pagar(Lancamento $lancamento, array $dados, ?User $user = null): Lancamento
    {
        if ($lancamento->pago_em) {
            throw ValidationException::withMessages(['lancamento' => ['Este lançamento já foi pago.']]);
        }

        if (! $lancamento->programada) {
            $this->alcadas->authorize('despesas', null, $lancamento->valor, 'Pagamento avulso: '.$lancamento->descricao, $user);
        }

        $pagoEm = ! empty($dados['pago_em']) ? Carbon::parse($dados['pago_em']) : today();
        $juros = (bool) ($dados['juros_multa'] ?? false);
        $nfConferida = (bool) ($dados['nf_entrada_conferida'] ?? false);

        $lancamento->update([
            'pago_em' => $pagoEm,
            'forma' => $dados['forma'] ?? null,
            'juros_multa' => $juros,
            'nf_entrada_conferida' => $nfConferida,
        ]);

        // Alimenta FIN-06 (pago sem juros ou multa) e FIN-07 (NF de entrada conferida antes de pagar).
        MentoriaRegistro::record('conta_paga', ['ok' => ! $juros, 'valor' => $lancamento->valor, 'observacao' => $lancamento->descricao], $pagoEm->copy()->setTimeFrom(now()), $user?->id);

        if ($lancamento->pagamento_pecas) {
            MentoriaRegistro::record('pagamento_pecas', ['ok' => $nfConferida, 'observacao' => $lancamento->descricao], $pagoEm->copy()->setTimeFrom(now()), $user?->id);
        }

        return $lancamento->refresh();
    }

    /**
     * Conciliação dos pagamentos do dia: pagamento sem conta não entra.
     *
     * @return array{conciliados: int, sem_conta: int}
     */
    public function conciliarDia(Carbon $dia, ?User $user = null): array
    {
        $pagos = Lancamento::whereDate('pago_em', $dia->toDateString())->whereNull('conciliado_em')->get();

        $semConta = $pagos->whereNull('conta_id');
        $comConta = $pagos->whereNotNull('conta_id');

        foreach ($comConta as $lancamento) {
            $lancamento->update(['conciliado_em' => now()]);
        }

        // Dia conciliado até o dia útil seguinte (FIN-04).
        if ($semConta->isEmpty()) {
            MentoriaRegistro::record('conciliacao_dia', [
                'ok' => now()->lte($this->diaUtilSeguinte($dia)->endOfDay()),
                'observacao' => $dia->format('d/m/Y'),
            ], $dia->copy()->setTime(23, 59), $user?->id);
        }

        return ['conciliados' => $comConta->count(), 'sem_conta' => $semConta->count()];
    }

    /**
     * Fechamento do caixa do dia. Diferença gera aviso crítico ao gerente e
     * ao dono; sem justificativa também vira trava do financeiro.
     *
     * @return array<string, mixed>
     */
    public function fecharCaixa(array $dados, ?User $user = null): array
    {
        $dia = Carbon::parse($dados['data'])->startOfDay();

        if (DB::table('caixa_fechamentos')->whereDate('data', $dia->toDateString())->exists()) {
            throw ValidationException::withMessages(['data' => ['O caixa deste dia já foi fechado.']]);
        }

        $entradas = collect(['dinheiro', 'pix', 'cartoes', 'boletos'])->mapWithKeys(fn ($forma) => [$forma => round((float) ($dados['entradas'][$forma] ?? 0), 2)]);
        $totalEntradas = round($entradas->sum(), 2);
        $saidas = round((float) Lancamento::whereDate('pago_em', $dia->toDateString())->sum('valor'), 2);
        $inicial = round((float) ($dados['saldo_inicial'] ?? $this->saldoDoDiaAnterior($dia)), 2);
        $sistema = round($inicial + $totalEntradas - $saidas, 2);
        $contado = round((float) $dados['saldo_contado'], 2);
        $diferenca = round($contado - $sistema, 2);
        $justificativa = trim((string) ($dados['justificativa'] ?? '')) ?: null;

        DB::table('caixa_fechamentos')->insert([
            'data' => $dia->toDateString(),
            'saldo_inicial' => $inicial,
            'entradas_json' => json_encode($entradas),
            'total_entradas' => $totalEntradas,
            'total_saidas' => $saidas,
            'saldo_sistema' => $sistema,
            'saldo_contado' => $contado,
            'diferenca' => $diferenca,
            'justificativa' => $justificativa,
            'fechado_por' => $user?->id ?? auth()->guard('user')->id(),
            'fechado_em' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // FIN-05: dia sem diferença, ou com diferença justificada.
        MentoriaRegistro::record('caixa_dia', [
            'valor' => $diferenca,
            'ok' => abs($diferenca) < 0.005 || $justificativa !== null,
            'observacao' => $justificativa,
        ], $dia->copy()->setTime(18, 0), $user?->id);

        if (abs($diferenca) >= 0.005) {
            // Crítico: gerente e dono, na hora. O aviso não leva o valor da diferença.
            $this->alerts->raise(
                'critico_diferenca_caixa',
                'Diferença de caixa',
                'ALERTA - Diferença no fechamento do caixa de '.$dia->format('d/m/Y').($justificativa ? ' (justificada).' : ' sem justificativa.'),
                ['gerente', 'proprietario'],
                critico: true,
            );
        }

        return ['saldo_sistema' => $sistema, 'saldo_contado' => $contado, 'diferenca' => $diferenca];
    }

    public function saldoDoDiaAnterior(Carbon $dia): float
    {
        $anterior = DB::table('caixa_fechamentos')->where('data', '<', $dia->toDateString())->orderByDesc('data')->first();

        return (float) ($anterior->saldo_contado ?? 0);
    }

    /**
     * Saldo conciliado (último fechamento), a receber e a pagar em 7 dias e total vencido.
     *
     * @return array<string, float>
     */
    public function caixaEBanco(Carbon $dia): array
    {
        $limite = $dia->copy()->addDays(7)->toDateString();

        $receberBoletos = ServiceOrderBoleto::where('status', 'Pendente');

        $ultimo = DB::table('caixa_fechamentos')->where('data', '<=', $dia->toDateString())->orderByDesc('data')->first();

        return [
            'saldo_conciliado' => (float) ($ultimo->saldo_contado ?? 0),
            'a_receber_7_dias' => (float) (clone $receberBoletos)->whereBetween('vencimento', [$dia->toDateString(), $limite])->sum('valor'),
            'a_pagar_7_dias' => (float) Lancamento::emAberto()->whereBetween('vencimento', [$dia->toDateString(), $limite])->sum('valor'),
            'total_vencido_receber' => (float) (clone $receberBoletos)->where('vencimento', '<', $dia->toDateString())->sum('valor'),
            'total_vencido_pagar' => (float) Lancamento::emAberto()->where('vencimento', '<', $dia->toDateString())->sum('valor'),
        ];
    }

    // ---- Régua de cobrança -------------------------------------------------------

    /**
     * Contatos de cobrança que já deveriam ter sido feitos (dias 1, 7 e 15
     * depois do vencimento) e ainda não foram.
     *
     * @return list<array<string, mixed>>
     */
    public function reguaPendente(?Carbon $hoje = null): array
    {
        $hoje ??= today();
        $feitos = MentoriaRegistro::vigentes()->doTipo('cobranca_contato')->get()
            ->map(fn ($r) => ($r->dados_json['boleto_id'] ?? 0).':'.(int) $r->valor)->flip();

        $linhas = [];

        foreach (ServiceOrderBoleto::where('status', 'Pendente')->whereNotNull('vencimento')->where('vencimento', '<', $hoje->toDateString())->get() as $boleto) {
            $atraso = Carbon::parse($boleto->vencimento)->diffInDays($hoje);

            foreach (self::REGUA_DIAS as $dia) {
                if ($atraso >= $dia && ! $feitos->has($boleto->id.':'.$dia)) {
                    $linhas[] = ['boleto_id' => $boleto->id, 'os' => $boleto->quote_id, 'dia_regua' => $dia, 'atraso_dias' => $atraso, 'vencimento' => Carbon::parse($boleto->vencimento)];
                }
            }
        }

        return $linhas;
    }

    public function registrarContatoDeCobranca(int $boletoId, int $dia, ?User $user = null): MentoriaRegistro
    {
        if (! in_array($dia, self::REGUA_DIAS, true)) {
            throw ValidationException::withMessages(['dia' => ['A régua de cobrança tem os dias 1, 7 e 15.']]);
        }

        ServiceOrderBoleto::findOrFail($boletoId);

        return MentoriaRegistro::record('cobranca_contato', ['ok' => true, 'valor' => $dia, 'dados_json' => ['boleto_id' => $boletoId, 'dia' => $dia]], now(), $user?->id);
    }

    // ---- Relatório diário ----------------------------------------------------------

    /**
     * Relatório diário do financeiro ao dono (9.1), gerado sozinho: caixa e
     * banco, operação (o segundo olhar) e o espaço dos alertas escritos.
     *
     * @return array<string, mixed>
     */
    public function relatorioDoDia(Carbon $dia): array
    {
        $caixa = DB::table('caixa_fechamentos')->whereDate('data', $dia->toDateString())->first();

        return [
            'data' => $dia->toDateString(),
            'caixa' => $this->caixaEBanco($dia) + [
                'saldo_inicial' => $caixa ? (float) $caixa->saldo_inicial : $this->saldoDoDiaAnterior($dia),
                'entradas' => $caixa ? json_decode($caixa->entradas_json, true) : null,
                'saidas' => $caixa ? (float) $caixa->total_saidas : (float) Lancamento::whereDate('pago_em', $dia->toDateString())->sum('valor'),
                'diferenca' => $caixa ? (float) $caixa->diferenca : null,
                'explicacao' => $caixa->justificativa ?? null,
                'fechado' => (bool) $caixa,
            ],
            'operacao' => $this->operacao($dia),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function operacao(Carbon $dia): array
    {
        $abertas = ServiceOrder::query()->with('items')
            ->whereNotIn('quotes.workshop_status', [ServiceOrder::STAGE_CONCLUIDA, ServiceOrder::STAGE_CANCELADA, ServiceOrder::STAGE_POS_VENDA])
            ->get();

        $comPecaPendente = fn (ServiceOrder $os) => $os->items->where('tipo', ServiceOrderItem::TIPO_PECA)
            ->contains(fn ($i) => $i->origem_preco === 'fornecedor' && ! $i->comprada_em || $i->situacao === ServiceOrderItem::SITUACAO_PENDENTE_DE_COMPRA);

        $classificar = fn (ServiceOrder $os) => match (true) {
            in_array($os->workshop_status, [ServiceOrder::STAGE_CADASTRO, ServiceOrder::STAGE_ORDEM_SERVICO, ServiceOrder::STAGE_DIAGNOSTICO, ServiceOrder::STAGE_ORCAMENTO], true) => 'aguardando_diagnostico_ou_orcamento',
            $os->workshop_status === ServiceOrder::STAGE_NEGOCIACAO => 'aguardando_aprovacao',
            $os->workshop_status === ServiceOrder::STAGE_FATURAMENTO => 'prontas_nao_faturadas',
            $comPecaPendente($os) => 'aguardando_peca',
            default => 'em_execucao',
        };

        $situacoes = $abertas->groupBy($classificar)->map(fn ($grupo) => ['quantidade' => $grupo->count(), 'valor' => round((float) $grupo->sum('grand_total'), 2)])->all();

        foreach (['aguardando_diagnostico_ou_orcamento', 'aguardando_aprovacao', 'aguardando_peca', 'em_execucao', 'prontas_nao_faturadas'] as $chave) {
            $situacoes[$chave] ??= ['quantidade' => 0, 'valor' => 0.0];
        }

        $paradas = $this->painel->idsParados($abertas->pluck('id')->all(), 3);
        $semResposta = ServiceOrder::query()->whereNotNull('quotes.orcamento_enviado_em')->whereNull('quotes.aprovacao_em')
            ->where('quotes.orcamento_enviado_em', '<', $dia->copy()->subHours(48))->count();

        $saidasSemNf = ServiceOrderEvent::where('tipo_evento', 'saida_veiculo')->whereDate('ocorrido_em', $dia->toDateString())->get()
            ->filter(fn ($e) => ! empty($e->dados_json['sem_nf']))->count();

        $excecoesCredito = ServiceOrderEvent::where('tipo_evento', 'cor_credito_registrada')->whereDate('ocorrido_em', $dia->toDateString())->get()
            ->filter(fn ($e) => in_array($e->dados_json['cor'] ?? null, ['amarelo', 'vermelho'], true))->count()
            + AlcadaPedido::where('tema', 'condicao_pagamento')->whereDate('created_at', $dia->toDateString())->count();

        return [
            'situacoes' => $situacoes,
            'os_paradas_3_dias' => count($paradas),
            'orcamentos_sem_resposta_48h' => $semResposta,
            'os_fechadas_no_dia' => ServiceOrder::whereDate('quotes.faturado_em', $dia->toDateString())->count(),
            'saidas_sem_nf' => $saidasSemNf,
            'excecoes_de_credito' => $excecoesCredito,
        ];
    }

    /**
     * O financeiro revisa, escreve até 3 alertas e envia. Alimenta FIN-08
     * (relatório completo e no horário combinado).
     */
    public function enviarRelatorio(Carbon $dia, array $alertas, ?User $user = null, ?Carbon $agora = null): bool
    {
        $agora ??= now();
        $alertas = array_values(array_filter(array_map('trim', $alertas), fn ($a) => $a !== ''));

        if (count($alertas) > 3) {
            throw ValidationException::withMessages(['alertas' => ['O relatório leva no máximo 3 alertas e decisões para o dono.']]);
        }

        if (DB::table('relatorios_financeiro')->whereDate('data', $dia->toDateString())->whereNotNull('enviado_em')->exists()) {
            throw ValidationException::withMessages(['data' => ['O relatório deste dia já foi enviado.']]);
        }

        // Horário combinado: até a hora da Ficha no próprio dia.
        $horario = (string) (WorkshopSettings::get('relatorio_financeiro_horario') ?: '17:30');
        $limite = $dia->copy()->setTimeFromTimeString($horario);
        $noHorario = $agora->lte($limite);

        DB::table('relatorios_financeiro')->updateOrInsert(
            ['data' => $dia->toDateString()],
            [
                'alertas_json' => json_encode($alertas),
                'snapshot_json' => json_encode($this->relatorioDoDia($dia)),
                'enviado_em' => $agora,
                'enviado_por' => $user?->id ?? auth()->guard('user')->id(),
                'no_horario' => $noHorario,
                'updated_at' => $agora,
                'created_at' => $agora,
            ]
        );

        MentoriaRegistro::record('relatorio_financeiro', ['ok' => $noHorario, 'observacao' => $dia->format('d/m/Y')], $dia->copy()->setTime(12, 0), $user?->id);

        return $noHorario;
    }

    /**
     * Relatório de 5 linhas do gerente (alimenta GER-12).
     */
    public function salvarRelatorioGerente(Carbon $dia, array $campos, ?User $user = null): void
    {
        $chaves = ['destaques', 'problemas', 'decisoes_tomadas', 'decisoes_pendentes', 'equipe'];

        foreach ($chaves as $chave) {
            if (trim((string) ($campos[$chave] ?? '')) === '') {
                throw ValidationException::withMessages([$chave => ['Preencha as 5 linhas do relatório do gerente (escreva "nada" se não houver).']]);
            }
        }

        DB::table('relatorios_gerente')->updateOrInsert(
            ['data' => $dia->toDateString()],
            array_intersect_key($campos, array_flip($chaves)) + ['user_id' => $user?->id ?? auth()->guard('user')->id(), 'updated_at' => now(), 'created_at' => now()]
        );

        MentoriaRegistro::record('relatorio_gerente', ['ok' => true, 'observacao' => $dia->format('d/m/Y')], $dia->copy()->setTime(12, 0), $user?->id);
    }

    protected function diaUtilSeguinte(Carbon $dia): Carbon
    {
        $proximo = $dia->copy()->addDay();

        while ($proximo->isWeekend()) {
            $proximo->addDay();
        }

        return $proximo;
    }
}
