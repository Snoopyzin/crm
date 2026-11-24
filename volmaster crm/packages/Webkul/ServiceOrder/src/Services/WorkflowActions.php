<?php

namespace Webkul\ServiceOrder\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Models\ServiceOrderItem;
use Webkul\ServiceOrder\Repositories\ServiceOrderRepository;
use Webkul\ServiceOrder\Services\Alcada\AlcadaService;
use Webkul\ServiceOrder\Services\Financeiro\DreService;

/**
 * Passos simples do fluxo que só registram um carimbo (data, hora e usuário)
 * e atualizam um ou dois campos: recepção, atualização ao cliente,
 * precificação e compra de peça, conferências, checklist de saída, pós-venda,
 * reclamação e OS de retorno. Cada passo grava o evento que alimenta as
 * barreiras e os indicadores.
 *
 * A tela da OS monta os formulários a partir de definitions(); o controlador
 * valida pelas regras e chama perform().
 */
class WorkflowActions
{
    public const RETORNO_CAUSAS = [
        'execucao' => 'Falha de execução',
        'peca' => 'Peça',
        'diagnostico' => 'Diagnóstico',
        'uso_cliente' => 'Uso do cliente',
    ];

    public const CANAIS = ['WhatsApp', 'Telefone', 'Presencial', 'E-mail'];

    public function __construct(
        protected OperationService $operations,
        protected ServiceOrderRepository $repository,
    ) {}

    /**
     * @return array<string, array{label: string, etapas: ?array, campos: array, regras: array, permissao: ?string, valores: bool, ajuda: ?string}>
     */
    public function definitions(): array
    {
        $etapas = fn (string ...$names) => $names;

        return [
            'recepcao' => [
                'label' => 'Registrar recepção do cliente',
                'etapas' => $etapas(ServiceOrder::STAGE_CADASTRO, ServiceOrder::STAGE_ORDEM_SERVICO),
                'campos' => [],
                'ajuda' => 'Marca a hora em que o cliente foi atendido (meta: 10 min depois da chegada).',
            ],
            'definir_tipo' => [
                'label' => 'Tipo de atendimento',
                'etapas' => $etapas(ServiceOrder::STAGE_CADASTRO, ServiceOrder::STAGE_ORDEM_SERVICO),
                'campos' => ['tipo_os' => ['label' => 'Tipo', 'tipo' => 'select', 'opcoes' => ['normal' => 'Normal', 'chamado_externo' => 'Chamado externo', 'preventiva' => 'Preventiva / frota']]],
                'regras' => ['tipo_os' => 'required|in:normal,chamado_externo,preventiva'],
            ],
            'reenviar_ficha' => [
                'label' => 'Reenviar a ficha ao chefe',
                'etapas' => $etapas(ServiceOrder::STAGE_ORDEM_SERVICO),
                'campos' => [],
                'ajuda' => 'Depois de corrigir o que o chefe devolveu.',
            ],
            'devolver_ficha' => [
                'label' => 'Devolver a ficha ao consultor',
                'etapas' => $etapas(ServiceOrder::STAGE_ORDEM_SERVICO),
                'campos' => ['motivo' => ['label' => 'O que precisa corrigir', 'tipo' => 'text']],
                'regras' => ['motivo' => 'required|string|max:255'],
                'auditar' => true,
            ],
            'os_completa' => [
                'label' => 'Enviar a OS completa ao consultor',
                'etapas' => $etapas(ServiceOrder::STAGE_DIAGNOSTICO, ServiceOrder::STAGE_ORCAMENTO),
                'campos' => [],
                'auditar' => true,
                'ajuda' => 'Diagnóstico aprovado e relação de peças entregue (meta: 1 h depois do fim do diagnóstico).',
            ],
            'precificar_peca' => [
                'label' => 'Precificar peça',
                'etapas' => $etapas(ServiceOrder::STAGE_DIAGNOSTICO, ServiceOrder::STAGE_ORCAMENTO),
                'campos' => [
                    'item_id' => ['label' => 'Peça', 'tipo' => 'item_peca'],
                    'preco_unitario' => ['label' => 'Preço de venda (R$)', 'tipo' => 'money'],
                    'custo_unitario' => ['label' => 'Custo (R$)', 'tipo' => 'money'],
                    'origem_preco' => ['label' => 'Origem', 'tipo' => 'select', 'opcoes' => ['estoque' => 'Estoque', 'fornecedor' => 'Fornecedor']],
                    'fornecedor' => ['label' => 'Fornecedor', 'tipo' => 'text', 'opcional' => true],
                ],
                'regras' => [
                    'item_id' => 'required|integer',
                    'preco_unitario' => 'required|numeric|min:0',
                    'custo_unitario' => 'required|numeric|min:0',
                    'origem_preco' => 'required|in:estoque,fornecedor',
                    'fornecedor' => 'nullable|string|max:120',
                ],
                'valores' => true,
            ],
            'corrigir_orcamento' => [
                'label' => 'Registrar correção do orçamento',
                'etapas' => $etapas(ServiceOrder::STAGE_ORCAMENTO, ServiceOrder::STAGE_NEGOCIACAO),
                'campos' => [
                    'quem' => ['label' => 'Pedida por', 'tipo' => 'select', 'opcoes' => ['pecas' => 'Peças', 'consultor' => 'Consultor']],
                    'motivo' => ['label' => 'Motivo', 'tipo' => 'text'],
                ],
                'regras' => ['quem' => 'required|in:pecas,consultor', 'motivo' => 'required|string|max:255'],
            ],
            'comprar_peca' => [
                'label' => 'Registrar compra de peça',
                'etapas' => $etapas(ServiceOrder::STAGE_NEGOCIACAO, ServiceOrder::STAGE_EM_EXECUCAO),
                'campos' => [
                    'item_id' => ['label' => 'Peça', 'tipo' => 'item_peca'],
                    'fornecedor' => ['label' => 'Fornecedor', 'tipo' => 'text', 'opcional' => true],
                    'prazo_entrega' => ['label' => 'Prazo informado de entrega', 'tipo' => 'date', 'opcional' => true],
                ],
                'regras' => ['item_id' => 'required|integer', 'fornecedor' => 'nullable|string|max:120', 'prazo_entrega' => 'nullable|date'],
            ],
            'entregar_peca' => [
                'label' => 'Entregar peça ao técnico',
                'etapas' => $etapas(ServiceOrder::STAGE_EM_EXECUCAO),
                'campos' => ['item_id' => ['label' => 'Peça', 'tipo' => 'item_peca']],
                'regras' => ['item_id' => 'required|integer'],
            ],
            'atualizar_cliente' => [
                'label' => 'Registrar atualização ao cliente',
                'etapas' => $etapas(ServiceOrder::STAGE_EM_EXECUCAO, ServiceOrder::STAGE_VEICULO_TESTE),
                'campos' => [
                    'canal' => ['label' => 'Canal', 'tipo' => 'select', 'opcoes' => array_combine(self::CANAIS, self::CANAIS)],
                    'observacao' => ['label' => 'O que foi informado', 'tipo' => 'text', 'opcional' => true],
                ],
                'regras' => ['canal' => 'required|in:'.implode(',', self::CANAIS), 'observacao' => 'nullable|string|max:255'],
                'ajuda' => 'O cliente precisa ser atualizado pelo menos a cada 24 h.',
            ],
            'recontato_recusa' => [
                'label' => 'Registrar recontato após a recusa',
                'etapas' => null,
                'campos' => [
                    'canal' => ['label' => 'Canal', 'tipo' => 'select', 'opcoes' => array_combine(self::CANAIS, self::CANAIS)],
                    'observacao' => ['label' => 'O que o cliente respondeu', 'tipo' => 'text', 'opcional' => true],
                ],
                'regras' => ['canal' => 'required|in:'.implode(',', self::CANAIS), 'observacao' => 'nullable|string|max:255'],
                'so_recusa' => true,
                'ajuda' => 'O orçamento recusado deve ser recontatado em até 7 dias.',
            ],
            'termo_pecas_velhas' => [
                'label' => 'Termo de peças velhas assinado',
                'etapas' => $etapas(ServiceOrder::STAGE_EM_EXECUCAO, ServiceOrder::STAGE_VEICULO_TESTE),
                'campos' => [],
                'ajuda' => 'Peças separadas, identificadas e com o termo assinado pelo cliente.',
            ],
            'checklist_montagem' => [
                'label' => 'Checklist de montagem concluído',
                'etapas' => $etapas(ServiceOrder::STAGE_EM_EXECUCAO, ServiceOrder::STAGE_VEICULO_TESTE),
                'campos' => [],
                'ajuda' => 'Obrigatório em serviços de motor e transmissão.',
            ],
            'conferencia_final' => [
                'label' => 'Conferência final do chefe',
                'etapas' => $etapas(ServiceOrder::STAGE_VEICULO_TESTE),
                'campos' => [],
                'auditar' => true,
            ],
            'conferir_pecas' => [
                'label' => 'Conferir peças aplicadas × lançadas',
                'etapas' => $etapas(ServiceOrder::STAGE_FATURAMENTO),
                'campos' => [
                    'divergencia' => ['label' => 'Resultado', 'tipo' => 'select', 'opcoes' => ['nao' => 'Sem divergência', 'sim' => 'Com divergência']],
                    'observacao' => ['label' => 'Observação', 'tipo' => 'text', 'opcional' => true],
                ],
                'regras' => ['divergencia' => 'required|in:sim,nao', 'observacao' => 'nullable|string|max:255'],
            ],
            'checklist_saida' => [
                'label' => 'Checklist de saída (horas reais)',
                'etapas' => $etapas(ServiceOrder::STAGE_FATURAMENTO),
                'campos' => [
                    'proxima_revisao_data' => ['label' => 'Próxima revisão (data)', 'tipo' => 'date'],
                    'proxima_revisao_km' => ['label' => 'Próxima revisão (km)', 'tipo' => 'number', 'opcional' => true],
                ],
                'regras' => ['proxima_revisao_data' => 'required|date|after:today', 'proxima_revisao_km' => 'nullable|integer|min:0'],
            ],
            'enviar_financeiro' => [
                'label' => 'Fechar a OS e enviar ao financeiro',
                'etapas' => $etapas(ServiceOrder::STAGE_FATURAMENTO),
                'campos' => [],
                'ajuda' => 'Precisa do checklist de saída. Meta: no mesmo dia.',
            ],
            'nota_cliente' => [
                'label' => 'Registrar nota do cliente (0 a 10)',
                'etapas' => $etapas(ServiceOrder::STAGE_POS_VENDA, ServiceOrder::STAGE_CONCLUIDA),
                'campos' => ['nota' => ['label' => 'Nota', 'tipo' => 'number']],
                'regras' => ['nota' => 'required|integer|min:0|max:10'],
            ],
            'abrir_reclamacao' => [
                'label' => 'Abrir reclamação do cliente',
                'etapas' => $etapas(ServiceOrder::STAGE_POS_VENDA, ServiceOrder::STAGE_CONCLUIDA),
                'campos' => ['descricao' => ['label' => 'Reclamação', 'tipo' => 'text']],
                'regras' => ['descricao' => 'required|string|max:500'],
            ],
            'fechar_reclamacao' => [
                'label' => 'Fechar reclamação',
                'etapas' => $etapas(ServiceOrder::STAGE_POS_VENDA, ServiceOrder::STAGE_CONCLUIDA),
                'campos' => [
                    'reclamacao_id' => ['label' => 'Reclamação', 'tipo' => 'reclamacao'],
                    'solucao' => ['label' => 'Solução', 'tipo' => 'text'],
                ],
                'regras' => ['reclamacao_id' => 'required|integer', 'solucao' => 'required|string|max:500'],
            ],
            'abrir_retorno' => [
                'label' => 'Abrir OS de retorno ou garantia',
                'etapas' => $etapas(ServiceOrder::STAGE_POS_VENDA, ServiceOrder::STAGE_CONCLUIDA),
                'campos' => [
                    'tipo' => ['label' => 'Tipo', 'tipo' => 'select', 'opcoes' => ['retorno' => 'Retorno', 'garantia' => 'Garantia']],
                    'motivo' => ['label' => 'O que o cliente relatou', 'tipo' => 'text'],
                ],
                'regras' => ['tipo' => 'required|in:retorno,garantia', 'motivo' => 'required|string|max:255'],
            ],
            'classificar_retorno' => [
                'label' => 'Classificar a causa do retorno',
                'etapas' => null,
                'campos' => ['causa' => ['label' => 'Causa', 'tipo' => 'select', 'opcoes' => self::RETORNO_CAUSAS]],
                'regras' => ['causa' => 'required|in:'.implode(',', array_keys(self::RETORNO_CAUSAS))],
                'so_tipos' => ['retorno', 'garantia'],
                'auditar' => true,
            ],
            'autorizar_chamado' => [
                'label' => 'Cliente autorizou a saída da viatura',
                'etapas' => $etapas(ServiceOrder::STAGE_CADASTRO, ServiceOrder::STAGE_ORDEM_SERVICO, ServiceOrder::STAGE_DIAGNOSTICO),
                'campos' => [],
                'so_tipos' => ['chamado_externo'],
            ],
            'pre_diagnostico_remoto' => [
                'label' => 'Registrar pré-diagnóstico remoto',
                'etapas' => $etapas(ServiceOrder::STAGE_CADASTRO, ServiceOrder::STAGE_ORDEM_SERVICO, ServiceOrder::STAGE_DIAGNOSTICO),
                'campos' => ['resumo' => ['label' => 'Resumo', 'tipo' => 'text']],
                'regras' => ['resumo' => 'required|string|max:255'],
                'so_tipos' => ['chamado_externo'],
            ],
        ];
    }

    /**
     * Ações que o usuário logado pode fazer nesta OS agora.
     *
     * @return array<string, array>
     */
    public function availableFor(ServiceOrder $serviceOrder): array
    {
        return collect($this->definitions())
            ->filter(fn ($definition, $key) => $this->isAvailable($serviceOrder, $definition))
            ->all();
    }

    public function isAvailable(ServiceOrder $serviceOrder, array $definition): bool
    {
        if (isset($definition['etapas']) && ! in_array($serviceOrder->workshop_status, $definition['etapas'], true)) {
            return false;
        }

        if (! empty($definition['so_tipos']) && ! in_array($serviceOrder->tipo_os, $definition['so_tipos'], true)) {
            return false;
        }

        if (! empty($definition['so_recusa']) && $serviceOrder->aprovacao_tipo !== 'recusa') {
            return false;
        }

        if (! empty($definition['valores']) && ! ValoresFinanceiros::can()) {
            return false;
        }

        if (! empty($definition['auditar']) && ! bouncer()->hasPermission('service_orders.auditar')) {
            return false;
        }

        return true;
    }

    /**
     * Executa a ação e grava o evento. Lança ValidationException quando a
     * regra de negócio não deixa fazer.
     */
    public function perform(ServiceOrder $os, string $acao, array $dados = []): string
    {
        $definition = $this->definitions()[$acao] ?? null;

        if (! $definition) {
            throw ValidationException::withMessages(['acao' => ['Ação desconhecida.']]);
        }

        if (! $this->isAvailable($os, $definition)) {
            throw ValidationException::withMessages(['acao' => ['Essa ação não está disponível agora para esta OS ou para o seu perfil.']]);
        }

        return match ($acao) {
            'recepcao' => $this->simple($os, 'recepcao', [], 'Recepção registrada.'),
            'definir_tipo' => $this->setTipo($os, $dados['tipo_os']),
            'reenviar_ficha' => $this->simple($os, 'ficha_enviada_chefe', ['reenvio' => true], 'Ficha reenviada ao chefe.'),
            'devolver_ficha' => $this->returnFicha($os, $dados['motivo']),
            'os_completa' => $this->osCompleta($os),
            'precificar_peca' => $this->price($os, $dados),
            'corrigir_orcamento' => $this->simple($os, 'orcamento_corrigido', ['quem' => $dados['quem'], 'motivo' => $dados['motivo']], 'Correção do orçamento registrada.'),
            'comprar_peca' => $this->buy($os, $dados),
            'entregar_peca' => $this->deliverPart($os, (int) $dados['item_id']),
            'atualizar_cliente' => $this->updateClient($os, $dados),
            'recontato_recusa' => $this->simple($os, 'recontato_recusa', ['canal' => $dados['canal'], 'observacao' => $dados['observacao'] ?? null], 'Recontato registrado.'),
            'termo_pecas_velhas' => $this->simple($os, 'termo_pecas_velhas', [], 'Termo de peças velhas registrado.'),
            'checklist_montagem' => $this->simple($os, 'checklist_montagem', [], 'Checklist de montagem registrado.'),
            'conferencia_final' => $this->finalCheck($os),
            'conferir_pecas' => $this->checkParts($os, $dados),
            'checklist_saida' => $this->exitChecklist($os, $dados),
            'enviar_financeiro' => $this->sendToFinance($os),
            'nota_cliente' => $this->customerScore($os, (int) $dados['nota']),
            'abrir_reclamacao' => $this->openComplaint($os, $dados['descricao']),
            'fechar_reclamacao' => $this->closeComplaint($os, (int) $dados['reclamacao_id'], $dados['solucao']),
            'abrir_retorno' => $this->openReturn($os, $dados['tipo'], $dados['motivo']),
            'classificar_retorno' => $this->classifyReturn($os, $dados['causa']),
            'autorizar_chamado' => $this->authorizeCall($os),
            'pre_diagnostico_remoto' => $this->preDiagnosis($os, $dados['resumo']),
        };
    }

    protected function simple(ServiceOrder $os, string $evento, array $dados, string $mensagem): string
    {
        ServiceOrderEvent::record($os, $evento, $dados);

        return $mensagem;
    }

    protected function setTipo(ServiceOrder $os, string $tipo): string
    {
        $os->forceFill(['tipo_os' => $tipo])->save();

        return 'Tipo de atendimento atualizado.';
    }

    protected function returnFicha(ServiceOrder $os, string $motivo): string
    {
        $os->forceFill(['ficha_devolucoes' => (int) $os->ficha_devolucoes + 1])->save();

        ServiceOrderEvent::record($os, 'ficha_devolvida', ['motivo' => $motivo]);

        return 'Ficha devolvida ao consultor.';
    }

    protected function osCompleta(ServiceOrder $os): string
    {
        $faltando = [];

        if ($os->diagnostico_visto_status !== 'aprovado') {
            $faltando[] = 'visto do chefe no diagnóstico';
        }

        if (! $os->relacao_pecas_em) {
            $faltando[] = 'relação de peças entregue';
        }

        if ($faltando) {
            throw ValidationException::withMessages(['acao' => ['A OS ainda não está completa: falta '.implode(' e ', $faltando).'.']]);
        }

        ServiceOrderEvent::record($os, 'os_completa_consultor');

        return 'OS completa enviada ao consultor.';
    }

    protected function item(ServiceOrder $os, int $itemId, ?string $tipo = ServiceOrderItem::TIPO_PECA): ServiceOrderItem
    {
        $query = ServiceOrderItem::where('quote_id', $os->id);

        if ($tipo) {
            $query->where('tipo', $tipo);
        }

        $item = $query->find($itemId);

        if (! $item) {
            throw ValidationException::withMessages(['item_id' => ['Escolha uma peça desta OS.']]);
        }

        return $item;
    }

    protected function price(ServiceOrder $os, array $dados): string
    {
        $item = $this->item($os, (int) $dados['item_id']);

        // Preço abaixo do mínimo (custo ÷ (1 − impostos − despesas − margem da faixa)) vai para a fila de alçadas.
        $minimo = app(DreService::class)->precoMinimoPeca((float) $dados['custo_unitario']);

        if ($minimo !== null && (float) $dados['preco_unitario'] < $minimo) {
            app(AlcadaService::class)->authorize(
                'preco_abaixo_minimo',
                $os,
                null,
                'Peça '.$item->name.' com preço abaixo do mínimo da tabela de margem'
            );
        }

        $item->forceFill([
            'price' => $dados['preco_unitario'],
            'custo_unitario' => $dados['custo_unitario'],
            'origem_preco' => $dados['origem_preco'],
            'fornecedor' => $dados['fornecedor'] ?? null,
            'precificada_em' => now(),
        ])->save();

        $item->forceFill(['total' => $item->line_total])->save();

        $this->repository->recalculateTotals($os);

        // O evento não leva valores em reais: ele aparece na linha do tempo.
        ServiceOrderEvent::record($os, 'peca_precificada', ['item_id' => $item->id, 'peca' => $item->name, 'origem' => $dados['origem_preco']]);

        return 'Peça precificada.';
    }

    protected function buy(ServiceOrder $os, array $dados): string
    {
        $item = $this->item($os, (int) $dados['item_id']);

        // Peça de uma OS já autorizada pelo cliente: Peças compra sozinha. Sem
        // autorização (reposição de estoque), vale a alçada de compra pelo custo.
        if (! $os->aprovacao_em) {
            app(AlcadaService::class)->authorize(
                'compra_pecas',
                $os,
                (float) $item->custo_unitario * (float) $item->quantity,
                'Compra de '.$item->name.' sem autorização do cliente na OS'
            );
        }

        $item->forceFill([
            'comprada_em' => now(),
            'fornecedor' => $dados['fornecedor'] ?? $item->fornecedor,
            'prazo_entrega_em' => ! empty($dados['prazo_entrega']) ? $dados['prazo_entrega'].' 18:00:00' : null,
            'situacao' => ServiceOrderItem::SITUACAO_DISPONIVEL,
        ])->save();

        ServiceOrderEvent::record($os, 'compra_peca', ['item_id' => $item->id, 'peca' => $item->name, 'fornecedor' => $item->fornecedor]);

        return 'Compra registrada.';
    }

    protected function deliverPart(ServiceOrder $os, int $itemId): string
    {
        $item = $this->item($os, $itemId);

        if ($item->entregue_tecnico_em) {
            throw ValidationException::withMessages(['item_id' => ['Essa peça já foi entregue ao técnico.']]);
        }

        $item->forceFill(['entregue_tecnico_em' => now()])->save();

        ServiceOrderEvent::record($os, 'peca_entregue_tecnico', [
            'item_id' => $item->id,
            'peca' => $item->name,
            'prazo_entrega_em' => $item->prazo_entrega_em?->toDateTimeString(),
        ]);

        return 'Entrega da peça ao técnico registrada.';
    }

    protected function updateClient(ServiceOrder $os, array $dados): string
    {
        $os->forceFill(['ultima_atualizacao_cliente_em' => now()])->save();

        ServiceOrderEvent::record($os, 'cliente_atualizado', ['canal' => $dados['canal'], 'observacao' => $dados['observacao'] ?? null]);

        return 'Atualização ao cliente registrada.';
    }

    protected function finalCheck(ServiceOrder $os): string
    {
        if (! $os->teste_finalizado_em) {
            throw ValidationException::withMessages(['acao' => ['Finalize o test drive antes da conferência final.']]);
        }

        $os->forceFill(['conferencia_final_em' => now()])->save();

        ServiceOrderEvent::record($os, 'conferencia_final');

        return 'Conferência final registrada.';
    }

    protected function checkParts(ServiceOrder $os, array $dados): string
    {
        $os->forceFill(['pecas_conferidas_em' => now()])->save();

        ServiceOrderEvent::record($os, 'pecas_conferidas', [
            'divergencia' => $dados['divergencia'] === 'sim',
            'observacao' => $dados['observacao'] ?? null,
        ]);

        return 'Conferência de peças registrada.';
    }

    protected function exitChecklist(ServiceOrder $os, array $dados): string
    {
        $horasPadrao = 0.0;
        $segundosReais = 0;

        foreach ($os->operations()->get() as $operacao) {
            $horasPadrao += $this->operations->padraoSeconds($operacao) / 3600;
            $segundosReais += $this->operations->workedSeconds($operacao);
        }

        $horasReais = round($segundosReais / 3600, 2);

        $os->forceFill([
            'checklist_saida_em' => now(),
            'horas_reais_saida' => $horasReais,
            'proxima_revisao_data' => $dados['proxima_revisao_data'],
            'proxima_revisao_km' => $dados['proxima_revisao_km'] ?? null,
        ])->save();

        ServiceOrderEvent::record($os, 'checklist_saida', [
            'horas_reais' => $horasReais,
            'horas_padrao' => round($horasPadrao, 2),
            'proxima_revisao_data' => $dados['proxima_revisao_data'],
            'proxima_revisao_km' => $dados['proxima_revisao_km'] ?? null,
            'pecas_conferidas' => (bool) $os->pecas_conferidas_em,
        ]);

        return 'Checklist de saída registrado.';
    }

    protected function sendToFinance(ServiceOrder $os): string
    {
        if (! $os->checklist_saida_em) {
            throw ValidationException::withMessages(['acao' => ['Faça o checklist de saída antes de enviar a OS ao financeiro.']]);
        }

        $os->forceFill(['enviada_financeiro_em' => now()])->save();

        ServiceOrderEvent::record($os, 'os_enviada_financeiro');

        return 'OS enviada ao financeiro.';
    }

    protected function customerScore(ServiceOrder $os, int $nota): string
    {
        $os->forceFill(['nota_recomendacao' => $nota])->save();

        ServiceOrderEvent::record($os, 'nota_cliente', ['nota' => $nota]);

        return 'Nota do cliente registrada.';
    }

    protected function openComplaint(ServiceOrder $os, string $descricao): string
    {
        $id = DB::table('service_order_reclamacoes')->insertGetId([
            'quote_id' => $os->id,
            'aberta_em' => now(),
            'descricao' => $descricao,
            'aberta_por' => auth()->guard('user')->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ServiceOrderEvent::record($os, 'reclamacao_aberta', ['referencia_id' => $id, 'descricao' => $descricao]);

        return 'Reclamação aberta. O gerente tem 48 h para resolver.';
    }

    protected function closeComplaint(ServiceOrder $os, int $id, string $solucao): string
    {
        $reclamacao = DB::table('service_order_reclamacoes')->where('quote_id', $os->id)->whereNull('fechada_em')->find($id);

        if (! $reclamacao) {
            throw ValidationException::withMessages(['reclamacao_id' => ['Escolha uma reclamação aberta desta OS.']]);
        }

        DB::table('service_order_reclamacoes')->where('id', $id)->update([
            'fechada_em' => now(),
            'solucao' => $solucao,
            'fechada_por' => auth()->guard('user')->id(),
            'updated_at' => now(),
        ]);

        ServiceOrderEvent::record($os, 'reclamacao_fechada', ['referencia_id' => $id, 'solucao' => $solucao]);

        return 'Reclamação fechada.';
    }

    /**
     * Cria a OS de retorno/garantia, vinculada à de origem.
     */
    protected function openReturn(ServiceOrder $os, string $tipo, string $motivo): string
    {
        $retorno = $this->repository->create([
            'person_id' => $os->person_id,
            'vehicle_id' => $os->vehicle_id,
            'subject' => ucfirst($tipo).' da OS #'.$os->id.' - '.($os->vehicle?->placa ?? $os->person?->name),
            'workshop_status' => ServiceOrder::STAGE_CADASTRO,
            'user_id' => $os->user_id,
            'mechanic_id' => $os->mechanic_id,
            'description' => $motivo,
        ]);

        $retorno->forceFill(['tipo_os' => $tipo, 'os_origem_id' => $os->id])->save();

        // O observador de criação roda antes de tipo_os existir: grava o aviso aqui.
        ServiceOrderEvent::record($retorno, 'retorno_aberto', ['tipo' => $tipo, 'os_origem_id' => $os->id, 'motivo' => $motivo]);

        session()->flash('retorno_id', $retorno->id);

        return 'OS de '.$tipo.' #'.$retorno->id.' aberta e vinculada a esta OS.';
    }

    protected function classifyReturn(ServiceOrder $os, string $causa): string
    {
        $os->forceFill(['retorno_causa' => $causa])->save();

        ServiceOrderEvent::record($os, 'retorno_classificado', ['causa' => $causa, 'os_origem_id' => $os->os_origem_id]);

        return 'Causa do retorno classificada.';
    }

    protected function authorizeCall(ServiceOrder $os): string
    {
        $os->forceFill(['chamado_autorizado_em' => now()])->save();

        ServiceOrderEvent::record($os, 'chamado_autorizado');

        return 'Autorização do cliente registrada.';
    }

    protected function preDiagnosis(ServiceOrder $os, string $resumo): string
    {
        $os->forceFill(['pre_diagnostico_em' => now()])->save();

        ServiceOrderEvent::record($os, 'pre_diagnostico_remoto', ['resumo' => $resumo]);

        return 'Pré-diagnóstico remoto registrado.';
    }
}
