<?php

namespace Webkul\ServiceOrder\Services\Rotina;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Webkul\ServiceOrder\Models\AlcadaPedido;
use Webkul\ServiceOrder\Models\BarrierInstance;
use Webkul\ServiceOrder\Models\Colaborador;
use Webkul\ServiceOrder\Models\MentoriaRegistro;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Models\ServiceOrderItem;
use Webkul\ServiceOrder\Services\Financeiro\FinanceiroService;
use Webkul\ServiceOrder\Services\Indicators\IndicatorService;
use Webkul\ServiceOrder\Services\Indicators\IndicatorStatus;
use Webkul\ServiceOrder\Services\Indicators\PainelDonoService;
use Webkul\ServiceOrder\Services\ModoTeste;
use Webkul\ServiceOrder\Services\ServiceOrderItemService;
use Webkul\ServiceOrder\Services\ValoresFinanceiros;
use Webkul\ServiceOrder\Services\WorkshopSettings;
use Webkul\User\Models\User;

/**
 * Telas de rotina de cada cargo (capítulo 4.2): quadro de pátio e os painéis
 * do chefe, de peças, do financeiro, do consultor, do gerente e da zeladoria.
 * Cada fila mostra o que o cargo precisa fazer agora, com o cronômetro do
 * prazo (verde no prazo, amarelo a 80% do prazo, vermelho atrasado).
 */
class RotinaService
{
    public function __construct(
        protected PainelDonoService $painel,
        protected IndicatorService $indicators,
        protected FinanceiroService $financeiro,
    ) {}

    /**
     * Cor do cronômetro pelo prazo em minutos.
     */
    public static function cor(float $decorrido, ?float $limite): string
    {
        if (! $limite) {
            return 'verde';
        }

        return match (true) {
            $decorrido > $limite => 'vermelho',
            $decorrido >= $limite * 0.8 => 'amarelo',
            default => 'verde',
        };
    }

    /**
     * Quadro de pátio: todos os caminhões na oficina por etapa, com o tempo
     * na etapa e a cor do prazo (pior barreira aberta da OS).
     *
     * @return list<array<string, mixed>>
     */
    public function quadroDePatio(): array
    {
        $abertas = ServiceOrder::query()->with(['vehicle:id,placa,marca,modelo', 'person:id,name'])
            ->whereNotIn('quotes.workshop_status', [ServiceOrder::STAGE_CONCLUIDA, ServiceOrder::STAGE_CANCELADA])
            ->get();

        $ids = $abertas->pluck('id')->all();
        $desde = ServiceOrderEvent::query()->where('tipo_evento', 'mudanca_etapa')->whereIn('service_order_id', $ids)
            ->selectRaw('service_order_id, MAX(ocorrido_em) as ultima')->groupBy('service_order_id')->pluck('ultima', 'service_order_id');

        $barreiras = BarrierInstance::abertas()->whereIn('service_order_id', $ids)->get()->groupBy('service_order_id');
        $agora = now();

        return collect(ServiceOrder::stagesFlow())->map(function ($etapa) use ($abertas, $desde, $barreiras, $agora) {
            $caminhoes = $abertas->where('workshop_status', $etapa)->map(function (ServiceOrder $os) use ($desde, $barreiras, $agora) {
                $naEtapa = isset($desde[$os->id]) ? Carbon::parse($desde[$os->id]) : $os->created_at;
                $cor = 'verde';

                foreach ($barreiras[$os->id] ?? [] as $barreira) {
                    $total = max(1, $barreira->aberta_em->diffInSeconds($barreira->vence_em, false));
                    $passado = $barreira->aberta_em->diffInSeconds($agora, false);

                    $corDela = $barreira->nivel_atual > 0 || $passado > $total ? 'vermelho' : ($passado >= $total * 0.8 ? 'amarelo' : 'verde');
                    $cor = $corDela === 'vermelho' || ($corDela === 'amarelo' && $cor === 'verde') ? $corDela : $cor;
                }

                return [
                    'os' => $os->id,
                    'placa' => $os->vehicle?->placa ?? '-',
                    'veiculo' => trim(($os->vehicle?->marca ?? '').' '.($os->vehicle?->modelo ?? '')),
                    'cliente' => $os->person?->name,
                    'tempo_na_etapa' => $this->duracao($naEtapa->diffInMinutes($agora)),
                    'dias_parado' => (int) $naEtapa->diffInDays($agora),
                    'cor' => $cor,
                ];
            })->sortByDesc('dias_parado')->values()->all();

            return ['etapa' => $etapa, 'caminhoes' => $caminhoes];
        })->all();
    }

    /**
     * Seções do painel do usuário, uma para cada papel que ele ocupa.
     *
     * @return list<array<string, mixed>>
     */
    public function painelDoUsuario(User $user): array
    {
        $perfis = array_filter(array_map('trim', explode(',', (string) $user->role?->perfil_oficina)));
        $secoes = [];

        foreach ($perfis as $perfil) {
            $secoes = array_merge($secoes, match ($perfil) {
                'chefe' => $this->doChefe(),
                'pecas' => $this->dePecas(),
                'financeiro' => $this->doFinanceiro(),
                'consultor' => $this->doConsultor($user),
                'gerente', 'proprietario' => $this->doGerente(),
                'zeladoria' => $this->daZeladoria(),
                default => [],
            });
        }

        return $secoes;
    }

    /**
     * Chefe: diagnósticos a auditar, operações acima do tempo, test drives pendentes e auditoria semanal.
     *
     * @return list<array<string, mixed>>
     */
    public function doChefe(): array
    {
        $agora = now();

        $auditar = ServiceOrder::query()->with('vehicle:id,placa')
            ->whereNotNull('quotes.diagnostico_concluido_em')
            ->where(fn ($q) => $q->whereNull('quotes.diagnostico_visto_status')->orWhere('quotes.diagnostico_visto_status', '!=', 'aprovado'))
            ->whereNotIn('quotes.workshop_status', [ServiceOrder::STAGE_CONCLUIDA, ServiceOrder::STAGE_CANCELADA])
            ->get()
            ->map(fn ($os) => $this->item($os->id, $os->vehicle?->placa, 'aguardando o seu visto', $os->diagnostico_concluido_em->diffInMinutes($agora), 60));

        $acima = BarrierInstance::abertas()->where('barrier_code', 'operacao_tempo')->where('nivel_atual', '>', 0)->get()
            ->map(function ($b) {
                $os = ServiceOrder::with('vehicle:id,placa')->find($b->service_order_id);

                return $this->item($b->service_order_id, $os?->vehicle?->placa, 'operação acima de '.($b->nivel_atual >= 2 ? '150' : '120').'% do padrão', 0, null, 'vermelho');
            });

        $testes = ServiceOrder::query()->with('vehicle:id,placa')->where('quotes.workshop_status', ServiceOrder::STAGE_VEICULO_TESTE)
            ->where(fn ($q) => $q->whereNull('quotes.teste_finalizado_em')->orWhereNull('quotes.conferencia_final_em'))->get()
            ->map(fn ($os) => $this->item($os->id, $os->vehicle?->placa, $os->teste_finalizado_em ? 'falta a conferência final' : 'test drive pendente'));

        $semana = MentoriaRegistro::vigentes()->doTipo('auditoria_box')->where('ocorrido_em', '>=', $agora->copy()->startOfWeek())->exists();

        return [
            $this->secao('Diagnósticos a auditar', $auditar, 'Nenhum diagnóstico aguardando o seu visto.'),
            $this->secao('Operações acima do tempo', $acima, 'Nenhuma operação acima do tempo padrão.'),
            $this->secao('Test drives pendentes', $testes, 'Nenhum test drive pendente.'),
            $this->secao('Auditoria semanal de box e postura', collect($semana ? [] : [$this->item(0, null, 'ainda não registrada nesta semana (nota de 0 a 10 por técnico)', 0, null, 'amarelo')]), 'Auditoria da semana registrada.'),
        ];
    }

    /**
     * Peças: pedidos a precificar (cronômetro 20 min estoque / 40 min fornecedor), compras do dia e entregas pendentes.
     *
     * @return list<array<string, mixed>>
     */
    public function dePecas(): array
    {
        $agora = now();

        $precificar = ServiceOrderItem::query()->where('tipo', ServiceOrderItem::TIPO_PECA)->whereNull('precificada_em')
            ->whereHas('serviceOrder', fn ($q) => $q->whereNotNull('relacao_pecas_em')->whereNotIn('workshop_status', [ServiceOrder::STAGE_CONCLUIDA, ServiceOrder::STAGE_CANCELADA]))
            ->with('serviceOrder.vehicle:id,placa')->get()
            ->map(fn ($i) => $this->item($i->quote_id, $i->serviceOrder?->vehicle?->placa, $i->name, $i->serviceOrder->relacao_pecas_em->diffInMinutes($agora), $i->origem_preco === 'fornecedor' ? 40 : 20));

        $compras = ServiceOrderItem::query()->where('tipo', ServiceOrderItem::TIPO_PECA)->where('origem_preco', 'fornecedor')->whereNull('comprada_em')
            ->whereHas('serviceOrder', fn ($q) => $q->whereNotNull('aprovacao_em')->where('aprovacao_tipo', '!=', 'recusa'))
            ->with('serviceOrder.vehicle:id,placa')->get()
            ->map(fn ($i) => $this->item($i->quote_id, $i->serviceOrder?->vehicle?->placa, $i->name.' (comprar hoje)', $i->serviceOrder->aprovacao_em->diffInMinutes($agora), 600));

        $entregas = ServiceOrderItem::query()->where('tipo', ServiceOrderItem::TIPO_PECA)->whereNull('entregue_tecnico_em')
            ->where(fn ($q) => $q->where('origem_preco', 'estoque')->orWhereNotNull('comprada_em'))
            ->whereHas('serviceOrder', fn ($q) => $q->where('workshop_status', ServiceOrder::STAGE_EM_EXECUCAO))
            ->with('serviceOrder.vehicle:id,placa')->get()
            ->map(fn ($i) => $this->item($i->quote_id, $i->serviceOrder?->vehicle?->placa, $i->name.($i->prazo_entrega_em ? ' (prazo '.$i->prazo_entrega_em->format('d/m').')' : ''), 0, null, $i->prazo_entrega_em && $i->prazo_entrega_em->isPast() ? 'vermelho' : 'verde'));

        $semanaInventario = MentoriaRegistro::vigentes()->doTipo('inventario_rotativo')->where('ocorrido_em', '>=', $agora->copy()->startOfWeek())->exists();

        return [
            $this->secao('Pedidos a precificar', $precificar, 'Nenhuma peça a precificar.'),
            $this->secao('Compras do dia', $compras, 'Nenhuma compra pendente.'),
            $this->secao('Entregas ao técnico pendentes', $entregas, 'Nenhuma entrega pendente.'),
            $this->secao('Inventário rotativo semanal', collect($semanaInventario ? [] : [$this->item(0, null, 'ainda não feito nesta semana', 0, null, 'amarelo')]), 'Inventário da semana registrado.'),
        ];
    }

    /**
     * Financeiro: créditos a analisar (30 min), OS prontas a faturar, conciliação, régua e relatório do dia.
     *
     * @return list<array<string, mixed>>
     */
    public function doFinanceiro(): array
    {
        $agora = now();

        $creditos = ServiceOrder::query()->with('vehicle:id,placa')->whereNull('quotes.cor_credito')
            ->whereNotIn('quotes.workshop_status', [ServiceOrder::STAGE_CONCLUIDA, ServiceOrder::STAGE_CANCELADA])->get()
            ->map(fn ($os) => $this->item($os->id, $os->vehicle?->placa, 'análise de crédito', $os->created_at->diffInMinutes($agora), 30));

        $faturar = ServiceOrder::query()->with('vehicle:id,placa')->where('quotes.workshop_status', ServiceOrder::STAGE_FATURAMENTO)->get()
            ->map(fn ($os) => $this->item($os->id, $os->vehicle?->placa, $os->nf_manual_em ? 'aguardando pagamento ou acordo' : 'emitir nota fiscal', $os->enviada_financeiro_em ? $os->enviada_financeiro_em->diffInMinutes($agora) : 0, 480));

        $regua = collect($this->financeiro->reguaPendente())
            ->map(fn ($r) => $this->item($r['os'], null, 'cobrança do dia '.$r['dia_regua'].' (vencida há '.$r['atraso_dias'].' dias)', 0, null, 'amarelo'));

        $relatorio = DB::table('relatorios_financeiro')->whereDate('data', today()->toDateString())->whereNotNull('enviado_em')->exists();
        $caixa = DB::table('caixa_fechamentos')->whereDate('data', today()->toDateString())->exists();

        return [
            $this->secao('Créditos a analisar (30 min)', $creditos, 'Nenhuma análise de crédito pendente.'),
            $this->secao('OS prontas a faturar', $faturar, 'Nenhuma OS no faturamento.'),
            $this->secao('Régua de cobrança', $regua, 'Nenhum contato de cobrança pendente.'),
            $this->secao('Fechamentos de hoje', collect(array_filter([
                $caixa ? null : $this->item(0, null, 'fechar o caixa e conciliar o dia', 0, null, 'amarelo'),
                $relatorio ? null : $this->item(0, null, 'enviar o relatório diário ao dono', 0, null, 'amarelo'),
            ])), 'Caixa e relatório de hoje em dia.'),
        ];
    }

    /**
     * Consultor: orçamentos a enviar (2 h), sem resposta há 48 h, clientes a atualizar e pós-venda do dia.
     *
     * @return list<array<string, mixed>>
     */
    public function doConsultor(?User $user = null): array
    {
        $agora = now();
        $minhas = fn ($q) => $user && $this->ehConsultor($user) ? $q->where('quotes.user_id', $user->id) : $q;

        $enviar = $minhas(ServiceOrder::query()->with('vehicle:id,placa')->whereNotNull('quotes.diagnostico_concluido_em')->whereNull('quotes.orcamento_enviado_em')
            ->whereNotIn('quotes.workshop_status', [ServiceOrder::STAGE_CONCLUIDA, ServiceOrder::STAGE_CANCELADA]))->get()
            ->map(fn ($os) => $this->item($os->id, $os->vehicle?->placa, 'enviar o orçamento', $os->diagnostico_concluido_em->diffInMinutes($agora), 120));

        $semResposta = $minhas(ServiceOrder::query()->with('vehicle:id,placa')->whereNotNull('quotes.orcamento_enviado_em')->whereNull('quotes.aprovacao_em')
            ->where('quotes.orcamento_enviado_em', '<', ModoTeste::antes($agora, 48 * 60)))->get()
            ->map(fn ($os) => $this->item($os->id, $os->vehicle?->placa, 'orçamento sem resposta', $os->orcamento_enviado_em->diffInMinutes($agora), 2880));

        $atualizar = $minhas(ServiceOrder::query()->with('vehicle:id,placa')->where('quotes.workshop_status', ServiceOrder::STAGE_EM_EXECUCAO))->get()
            ->filter(fn ($os) => ! $os->ultima_atualizacao_cliente_em || $os->ultima_atualizacao_cliente_em->lt(ModoTeste::antes($agora, 20 * 60)))
            ->map(fn ($os) => $this->item($os->id, $os->vehicle?->placa, 'atualizar o cliente', ($os->ultima_atualizacao_cliente_em ?? $os->execucao_liberada_em ?? $os->created_at)->diffInMinutes($agora), 1440));

        $posVenda = $minhas(ServiceOrder::query()->with('vehicle:id,placa')->whereNotNull('quotes.saida_em')->where('quotes.saida_em', '<=', ModoTeste::antes($agora, 5 * 1440))
            ->whereNotIn('quotes.id', ServiceOrderEvent::where('tipo_evento', 'posvenda_contato')->select('service_order_id')))->get()
            ->map(fn ($os) => $this->item($os->id, $os->vehicle?->placa, 'contato de pós-venda', $os->saida_em->diffInMinutes($agora), 10080));

        $diarias = $this->diariasDePatio()->map(fn ($d) => $this->item($d['os'], $d['placa'], $d['motivo'].' — '.$d['dias'].' dia(s) de diária', 0, null, 'amarelo'));

        return [
            $this->secao('Orçamentos a enviar (2 h)', $enviar, 'Nenhum orçamento a enviar.'),
            $this->secao('Orçamentos sem resposta há 48 h', $semResposta, 'Nenhum orçamento sem resposta.'),
            $this->secao('Clientes a atualizar', $atualizar, 'Todos os clientes atualizados.'),
            $this->secao('Pós-venda do dia', $posVenda, 'Nenhum contato de pós-venda pendente.'),
            $this->secao('Diária de pátio a cobrar', $diarias, 'Nenhuma diária de pátio a cobrar.'),
        ];
    }

    /**
     * Gerente: vermelhos, fila de alçadas, RH, auditorias e pendências de enquadramento.
     *
     * @return list<array<string, mixed>>
     */
    public function doGerente(): array
    {
        $periodo = now()->format('Y-m');
        $vermelhos = $this->indicators->month($periodo)->filter(fn ($l) => $l['situacao'] === IndicatorStatus::VERMELHO)
            ->map(fn ($l, $codigo) => $this->item(0, null, $codigo.' '.$l['definicao']->nome.' ('.number_format((float) $l['resultado']->realizado, 1, ',', '.').')', 0, null, 'vermelho'))->values();

        $alcadas = AlcadaPedido::abertos()->orderBy('id')->get()->map(fn ($p) => $this->item($p->service_order_id ?: 0, null, $p->tema_nome.' — '.($p->status === 'encaminhado' ? 'com o dono' : 'aguardando você'), $p->created_at->diffInMinutes(now()), null, $p->status === 'encaminhado' ? 'amarelo' : 'vermelho'));

        $rh = Schema::hasTable('colaboradores')
            ? Colaborador::ativos()->get()->flatMap(function ($c) {
                $lista = [];

                foreach (['ferias_vencimento' => 'férias', 'exame_periodico_vencimento' => 'exame periódico', 'epi_vencimento' => 'ficha de EPI'] as $campo => $rotulo) {
                    if ($c->{$campo} && $c->{$campo}->lte(today()->addDays(30))) {
                        $lista[] = $this->item(0, null, $c->nome.': '.$rotulo.($c->{$campo}->isPast() ? ' vencido' : ' vence').' em '.$c->{$campo}->format('d/m/Y'), 0, null, $c->{$campo}->isPast() ? 'vermelho' : 'amarelo');
                    }
                }

                return $lista;
            })
            : collect();

        $combinadas = (int) WorkshopSettings::get('auditorias_amostragem_mes', 4);
        $feitas = MentoriaRegistro::vigentes()->doTipo('auditoria_amostragem')->where('ok', true)->where('ocorrido_em', '>=', now()->startOfMonth())->count();

        $pendencias = Schema::hasTable('cargos_pendentes')
            ? DB::table('cargos_pendentes')->whereNull('resolvido_em')->get()->map(fn ($p) => $this->item(0, null, 'Cargo '.$p->cargo.': '.$p->acao.($p->prazo ? ' até '.Carbon::parse($p->prazo)->format('d/m') : ''), 0, null, $p->prazo && Carbon::parse($p->prazo)->isPast() ? 'vermelho' : 'amarelo'))
            : collect();

        return [
            $this->secao('Indicadores vermelhos do mês', $vermelhos, 'Nenhum indicador vermelho.'),
            $this->secao('Fila de alçadas', $alcadas, 'Nenhum pedido aguardando decisão.'),
            $this->secao('RH a vencer (30 dias)', $rh, 'Nada de RH a vencer.'),
            $this->secao('Auditorias por amostragem', collect($feitas >= $combinadas ? [] : [$this->item(0, null, "{$feitas} de {$combinadas} feitas neste mês", 0, null, 'amarelo')]), 'Auditorias do mês em dia.'),
            $this->secao('Pendências de enquadramento', $pendencias, 'Nenhuma pendência de enquadramento.'),
        ];
    }

    /**
     * Zeladoria: checklists de um toque. Devolve o que já foi feito hoje.
     *
     * @return list<array<string, mixed>>
     */
    public function daZeladoria(): array
    {
        $hoje = MentoriaRegistro::vigentes()->doTipo(['zel_limpeza', 'zel_banheiro', 'zel_cafe'])->where('ocorrido_em', '>=', today())->get()->groupBy('tipo');

        $botoes = [
            'zel_limpeza' => ['Limpeza do dia concluída', 1],
            'zel_banheiro' => ['Banheiros limpos neste horário', (int) WorkshopSettings::get('zel_banheiros_por_dia', 3)],
            'zel_cafe' => ['Café e lanche servidos no horário', 2],
        ];

        return [[
            'titulo' => 'Meus checklists de hoje',
            'vazio' => '',
            'itens' => [],
            'checklists' => collect($botoes)->map(fn ($b, $tipo) => ['tipo' => $tipo, 'rotulo' => $b[0], 'previstos' => $b[1], 'feitos' => ($hoje[$tipo] ?? collect())->where('ok', true)->count()])->values()->all(),
        ]];
    }

    /**
     * Registro de um toque da zeladoria.
     */
    public function registrarChecklist(User $user, string $tipo): MentoriaRegistro
    {
        if (! in_array($tipo, ['zel_limpeza', 'zel_banheiro', 'zel_cafe'], true)) {
            throw ValidationException::withMessages(['tipo' => ['Checklist desconhecido.']]);
        }

        return MentoriaRegistro::record($tipo, ['ok' => true, 'user_id' => $user->id], now(), $user->id);
    }

    // ---- Diária de pátio -------------------------------------------------------------

    /**
     * Veículos que passaram do prazo da Ficha e geram diária: pronto e não
     * retirado, ou orçamento não autorizado.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function diariasDePatio(): Collection
    {
        $valor = (float) (WorkshopSettings::get('diaria_patio_valor') ?? 0);
        $horasPronto = WorkshopSettings::get('diaria_patio_horas_pronto');
        $horasNaoAutorizado = WorkshopSettings::get('diaria_patio_horas_nao_autorizado');
        $agora = now();
        $lista = collect();

        if ($horasPronto !== null) {
            ServiceOrder::query()->with('vehicle:id,placa')->whereNull('quotes.saida_em')->whereNotNull('quotes.checklist_saida_em')
                ->whereNotIn('quotes.workshop_status', [ServiceOrder::STAGE_CONCLUIDA, ServiceOrder::STAGE_CANCELADA])->get()
                ->each(function ($os) use ($lista, $horasPronto, $valor, $agora) {
                    $dias = $this->diasAlemDoPrazo($os->checklist_saida_em, (int) $horasPronto, $agora);

                    if ($dias > 0) {
                        $lista->push(['os' => $os->id, 'placa' => $os->vehicle?->placa, 'motivo' => 'pronto e não retirado', 'dias' => $dias, 'valor' => round($dias * $valor, 2)]);
                    }
                });
        }

        if ($horasNaoAutorizado !== null) {
            ServiceOrder::query()->with('vehicle:id,placa')->whereNotNull('quotes.orcamento_enviado_em')->whereNull('quotes.aprovacao_em')->whereNull('quotes.saida_em')
                ->whereNotIn('quotes.workshop_status', [ServiceOrder::STAGE_CONCLUIDA, ServiceOrder::STAGE_CANCELADA])->get()
                ->each(function ($os) use ($lista, $horasNaoAutorizado, $valor, $agora) {
                    $dias = $this->diasAlemDoPrazo($os->orcamento_enviado_em, (int) $horasNaoAutorizado, $agora);

                    if ($dias > 0) {
                        $lista->push(['os' => $os->id, 'placa' => $os->vehicle?->placa, 'motivo' => 'orçamento não autorizado', 'dias' => $dias, 'valor' => round($dias * $valor, 2)]);
                    }
                });
        }

        return $lista->values();
    }

    /**
     * Lança a diária de pátio na OS como um serviço (só quem vê valores).
     */
    public function lancarDiaria(ServiceOrder $os): ServiceOrderItem
    {
        ValoresFinanceiros::authorize('diária de pátio');

        $diaria = $this->diariasDePatio()->firstWhere('os', $os->id);

        if (! $diaria) {
            throw ValidationException::withMessages(['diaria' => ['Esta OS não tem diária de pátio a cobrar.']]);
        }

        if ($os->items()->where('name', 'like', 'Diária de pátio%')->exists()) {
            throw ValidationException::withMessages(['diaria' => ['A diária de pátio já foi lançada nesta OS.']]);
        }

        return app(ServiceOrderItemService::class)->addItem($os, [
            'tipo' => ServiceOrderItem::TIPO_SERVICO,
            'descricao' => 'Diária de pátio ('.$diaria['dias'].' dia(s), '.$diaria['motivo'].')',
            'preco_unitario' => $diaria['valor'],
            'hours' => 1,
        ]);
    }

    // ---- Agenda --------------------------------------------------------------------------

    /**
     * Capacidade do dia (OS por dia): boxes × 2 turnos, ou o que a oficina definir; 20% fica para encaixes.
     */
    public function capacidadeDoDia(): int
    {
        $definida = (int) (WorkshopSettings::get('agenda_capacidade_dia') ?? 0);

        if ($definida > 0) {
            return $definida;
        }

        $boxes = (int) (WorkshopSettings::get('ficha_boxes') ?? 0);

        return max(2, ($boxes ?: 3) * 2);
    }

    /**
     * Agenda um atendimento. Avisa quando o dia passa de 80% da capacidade
     * (20% é reservado a encaixes); encaixe pode ocupar a reserva.
     *
     * @return array{agendamento: int, ocupacao: float, aviso: ?string}
     */
    public function agendar(array $dados, ?User $user = null): array
    {
        $quando = Carbon::parse($dados['agendado_para']);
        $capacidade = $this->capacidadeDoDia();
        $encaixe = (bool) ($dados['encaixe'] ?? false);

        $ocupadas = DB::table('agendamentos')->whereDate('agendado_para', $quando->toDateString())->whereIn('status', ['agendado', 'chegou'])->count();

        if ($ocupadas >= $capacidade) {
            throw ValidationException::withMessages(['agendado_para' => ['O dia já está com a capacidade cheia ('.$capacidade.' atendimentos).']]);
        }

        $limite80 = (int) floor($capacidade * 0.8);
        $aviso = null;

        // Só um aviso: passou de 80%, o resto do dia fica reservado a encaixes.
        if (! $encaixe && $ocupadas + 1 > $limite80) {
            $aviso = 'O dia passa de 80% da capacidade: os últimos '.($capacidade - $limite80).' lugar(es) ficam reservados a encaixes.';
        }

        $id = DB::table('agendamentos')->insertGetId([
            'agendado_para' => $quando,
            'cliente' => $dados['cliente'],
            'telefone' => $dados['telefone'] ?? null,
            'placa' => isset($dados['placa']) ? strtoupper($dados['placa']) : null,
            'queixa' => $dados['queixa'] ?? null,
            'status' => 'agendado',
            'encaixe' => $encaixe,
            'created_by' => $user?->id ?? auth()->guard('user')->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['agendamento' => $id, 'ocupacao' => round(($ocupadas + 1) / $capacidade * 100, 1), 'aviso' => $aviso];
    }

    // ---- Auxiliares ---------------------------------------------------------------------

    protected function ehConsultor(User $user): bool
    {
        return in_array('consultor', array_map('trim', explode(',', (string) $user->role?->perfil_oficina)), true);
    }

    protected function diasAlemDoPrazo(?Carbon $desde, int $horas, Carbon $agora): int
    {
        if (! $desde) {
            return 0;
        }

        $excedente = $desde->copy()->addHours($horas)->diffInHours($agora, false);

        return $excedente > 0 ? (int) ceil($excedente / 24) : 0;
    }

    /**
     * @return array<string, mixed>
     */
    protected function item(int $os, ?string $placa, string $texto, float $decorrido = 0, ?float $limite = null, ?string $cor = null): array
    {
        // Os chamadores informam o limite REAL; no modo de teste ele vem dividido.
        $limite = $limite !== null ? (float) ModoTeste::minutos($limite) : null;

        return [
            'os' => $os ?: null,
            'placa' => $placa,
            'texto' => $texto,
            'decorrido' => $limite ? $this->duracao((int) $decorrido) : null,
            'limite' => $limite ? $this->duracao((int) round($limite)) : null,
            'cor' => $cor ?? self::cor($decorrido, $limite),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $itens
     * @return array<string, mixed>
     */
    protected function secao(string $titulo, Collection $itens, string $vazio): array
    {
        return ['titulo' => $titulo, 'vazio' => $vazio, 'itens' => $itens->values()->all(), 'checklists' => []];
    }

    protected function duracao(int $minutos): string
    {
        return $minutos >= 2880 ? intdiv($minutos, 1440).' d' : ($minutos >= 120 ? intdiv($minutos, 60).' h '.($minutos % 60).' min' : $minutos.' min');
    }
}
