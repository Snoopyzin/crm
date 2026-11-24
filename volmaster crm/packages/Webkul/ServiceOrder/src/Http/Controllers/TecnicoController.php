<?php

namespace Webkul\ServiceOrder\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\PartInventory\Models\Part;
use Webkul\ServiceOrder\Models\DiagnosticoQueixaTipo;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderItem;
use Webkul\ServiceOrder\Services\OperationService;
use Webkul\ServiceOrder\Services\ServiceOrderItemService;
use Webkul\ServiceOrder\Services\TecnicoTasks;
use Webkul\ServiceOrder\Services\WorkflowService;

/**
 * "Minhas tarefas": a tela do técnico no celular ou tablet do box. O técnico
 * só enxerga e mexe nas próprias tarefas, e nada aqui devolve valores em
 * reais (ver TecnicoTasks).
 */
class TecnicoController extends Controller
{
    public function __construct(
        protected TecnicoTasks $tasks,
        protected WorkflowService $workflow,
        protected OperationService $operations,
    ) {}

    public function index(): View
    {
        DiagnosticoQueixaTipo::ensureDefaults();

        return view('service_order::tecnico.index', [
            'mechanicId' => $this->mechanicId(),
            'queixaTipos' => DiagnosticoQueixaTipo::where('ativo', true)->orderBy('nome')->get(['id', 'nome', 'tempo_alvo_min'])
                ->map(fn ($tipo) => ['id' => $tipo->id, 'nome' => $tipo->nome, 'tempo_alvo_min' => $tipo->tempoAlvoEfetivoMin()])->values(),
            'pecas' => Part::orderBy('nome')->get(['id', 'codigo', 'nome']),
            'usuario' => auth()->guard('user')->user()->name,
        ]);
    }

    public function tasks(): JsonResponse
    {
        $mechanicId = $this->mechanicId();

        if (! $mechanicId) {
            return response()->json(['vinculado' => false]);
        }

        return response()->json(['vinculado' => true] + $this->tasks->forMechanic($mechanicId));
    }

    public function startDiagnostico(int $id): JsonResponse
    {
        $os = $this->ownDiagnostic($id);

        return $this->run(fn () => $this->workflow->startDiagnostico($os, request('queixa_tipo_id') ? (int) request('queixa_tipo_id') : $os->queixa_tipo_id));
    }

    public function saveLaudo(int $id): JsonResponse
    {
        $os = $this->ownDiagnostic($id);

        $this->validate(request(), ['diagnostico' => 'nullable|string|max:5000', 'queixa_tipo_id' => 'nullable|integer|exists:diagnostico_queixa_tipos,id']);

        return $this->run(function () use ($os) {
            $this->workflow->saveLaudo($os, request('diagnostico'));

            if (! $os->diagnostico_inicio_em && request('queixa_tipo_id')) {
                $os->forceFill(['queixa_tipo_id' => request('queixa_tipo_id')])->save();
            }
        });
    }

    public function addEvidence(int $id): JsonResponse
    {
        $os = $this->ownDiagnostic($id);

        $this->validate(request(), [
            'tipo' => 'required|in:codigo,medicao,foto',
            'descricao' => 'nullable|string|max:255',
            'valor' => 'nullable|string|max:120',
            'foto' => 'nullable|image|max:8192',
        ]);

        return $this->run(fn () => $this->workflow->addEvidence($os, request('tipo'), request('descricao'), request('valor'), request()->file('foto')));
    }

    /**
     * Inclui uma peça na relação. O preço vem do cadastro da peça, no
     * servidor; o técnico nunca o vê nem o informa.
     */
    public function addPart(int $id, ServiceOrderItemService $items): JsonResponse
    {
        $os = $this->ownDiagnostic($id);

        $this->validate(request(), ['part_id' => 'required|integer|exists:parts,id', 'quantidade' => 'required|integer|min:1|max:999']);

        $part = Part::findOrFail(request('part_id'));

        return $this->run(fn () => $items->addItem($os, [
            'tipo' => ServiceOrderItem::TIPO_PECA,
            'descricao' => $part->nome,
            'preco_unitario' => $part->valor_unitario,
            'quantidade' => (int) request('quantidade'),
            'part_id' => $part->id,
        ]));
    }

    public function deliverParts(int $id): JsonResponse
    {
        $os = $this->ownDiagnostic($id);

        return $this->run(fn () => $this->workflow->deliverParts($os, request()->boolean('sem_pecas')));
    }

    public function finishDiagnostico(int $id): JsonResponse
    {
        $os = $this->ownDiagnostic($id);

        $this->validate(request(), ['causa_atraso' => 'nullable|string|max:60', 'observacao' => 'nullable|string|max:500']);

        return $this->run(fn () => $this->workflow->finishDiagnostico($os, request('causa_atraso'), request('observacao')));
    }

    public function operation(int $itemId, string $acao): JsonResponse
    {
        $item = ServiceOrderItem::where('tipo', ServiceOrderItem::TIPO_SERVICO)->findOrFail($itemId);
        $os = ServiceOrder::findOrFail($item->quote_id);

        $dono = $item->funcionario_id ?: $os->mechanic_id;

        abort_unless($this->mechanicId() && (int) $dono === $this->mechanicId(), 403);

        return $this->run(function () use ($item, $acao) {
            match ($acao) {
                'iniciar' => $this->operations->start($item),
                'pausar' => $this->operations->pause($item, (string) request('motivo')),
                'retomar' => $this->operations->resume($item),
                'concluir' => $this->operations->finish($item, request('motivo_atraso'), request('observacao')),
                default => abort(404),
            };
        });
    }

    protected function mechanicId(): ?int
    {
        return auth()->guard('user')->user()?->mechanic_id ?: null;
    }

    /**
     * Só o técnico designado mexe no diagnóstico da OS.
     */
    protected function ownDiagnostic(int $id): ServiceOrder
    {
        $os = ServiceOrder::findOrFail($id);

        abort_unless($this->mechanicId() && $os->tecnicoDesignadoId() === $this->mechanicId(), 403);

        return $os;
    }

    protected function run(callable $action): JsonResponse
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            return response()->json(['ok' => false, 'mensagem' => collect($exception->errors())->flatten()->first()], 422);
        }

        return response()->json(['ok' => true]);
    }
}
