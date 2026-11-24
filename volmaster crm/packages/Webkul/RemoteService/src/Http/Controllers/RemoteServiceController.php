<?php

namespace Webkul\RemoteService\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Contact\Models\Person;
use Webkul\RemoteService\DataGrids\RemoteServiceDataGrid;
use Webkul\RemoteService\Models\RemoteService;
use Webkul\RemoteService\Models\RemoteServiceType;
use Webkul\ServiceOrder\Repositories\ServiceOrderRepository;
use Webkul\Vehicle\Models\Vehicle;

class RemoteServiceController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        protected ServiceOrderRepository $serviceOrderRepository
    ) {}

    /**
     * Every configured "tipo de atendimento", grouped by team (Diagnóstico,
     * Programações, ...) for the checkbox picker.
     */
    protected function tiposPorGrupo()
    {
        return RemoteServiceType::with('group')
            ->orderBy('group_id')
            ->orderBy('nome')
            ->get()
            ->groupBy(fn ($tipo) => $tipo->group->name ?? 'Sem grupo');
    }

    /**
     * Which team (Diagnóstico/Programações) this occurrence belongs to,
     * derived from its selected tipo_atendimento -- this is what actually
     * controls who can see it, not who created/is assigned to it.
     */
    protected function deriveGrupoId(array $tipos): ?int
    {
        if (empty($tipos)) {
            return null;
        }

        return RemoteServiceType::whereIn('nome', $tipos)->whereNotNull('group_id')->value('group_id');
    }

    /**
     * Blocks access to a record whose team doesn't match the current
     * user's own group -- "global" view_permission (e.g. Paulo) always
     * passes. Pending (unapproved) requests are always Paulo-only,
     * regardless of team, since they haven't been triaged yet.
     */
    protected function preventUnauthorizedGroupAccess(RemoteService $remoteService): void
    {
        $user = auth()->guard('user')->user();

        if ($user->view_permission === 'global') {
            return;
        }

        if ($remoteService->status === RemoteService::STATUS_PENDENTE) {
            abort(401, trans('admin::app.errors.unauthorized'));
        }

        $userGroupIds = $user->groups->pluck('id')->toArray();

        if (! $remoteService->grupo_id || ! in_array($remoteService->grupo_id, $userGroupIds)) {
            abort(401, trans('admin::app.errors.unauthorized'));
        }
    }

    /**
     * Display a listing of the resource.
     */
    public function index(): View|JsonResponse
    {
        if (request()->ajax()) {
            return datagrid(RemoteServiceDataGrid::class)->process();
        }

        return view('remote_service::index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        return view('remote_service::create', [
            'tiposPorGrupo' => $this->tiposPorGrupo(),
        ]);
    }

    /**
     * Search existing clients by name (used by the create form's
     * free-typing autocomplete -- picking a suggestion links a real
     * person_id, otherwise the typed name is kept as free text).
     */
    public function searchPersons(): JsonResponse
    {
        $query = trim((string) request('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json([]);
        }

        // Fetching full rows (not a restricted column select) -- Person's
        // CustomAttribute trait treats any attribute missing from a
        // partially-hydrated model as a custom EAV field lookup, which
        // silently nulls out real columns like "name" when the query only
        // selects a subset of columns.
        $persons = Person::where('name', 'like', "%{$query}%")
            ->orderBy('name')
            ->limit(10)
            ->get();

        return response()->json(
            $persons->map(fn ($person) => ['id' => $person->id, 'name' => $person->name])
        );
    }

    /**
     * Return the vehicles that belong to a given person (used by the create form).
     */
    public function vehiclesByPerson(int $personId): JsonResponse
    {
        return response()->json(
            Vehicle::where('person_id', $personId)->get(['id', 'placa', 'marca', 'modelo'])
        );
    }

    /**
     * Look up the most recent past occurrence with this same TeamViewer ID --
     * repeat clients (their machine/installation keeps the same ID across
     * visits) so the rest of the form can be auto-filled instead of
     * retyped every time.
     */
    public function lookupByTeamviewer(string $teamviewerId): JsonResponse
    {
        $normalized = preg_replace('/\D/', '', $teamviewerId);

        if (! $normalized) {
            return response()->json(['found' => false]);
        }

        $match = RemoteService::whereNotNull('teamviewer_id')
            ->whereRaw("REPLACE(REPLACE(REPLACE(teamviewer_id, ' ', ''), '-', ''), '.', '') = ?", [$normalized])
            ->with('person')
            ->orderByDesc('created_at')
            ->first();

        if (! $match) {
            return response()->json(['found' => false]);
        }

        return response()->json([
            'found' => true,
            'person_id' => $match->person_id,
            'person_name' => $match->person->name ?? null,
            'cliente_nome_livre' => $match->cliente_nome_livre,
            'nome_oficina' => $match->nome_oficina,
            'whatsapp' => $match->whatsapp,
            'tipo_atendimento' => $match->tipo_atendimento,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(): RedirectResponse
    {
        $this->validate(request(), [
            'person_id' => 'nullable|integer|exists:persons,id',
            'cliente_nome_livre' => 'required_without:person_id|nullable|string|max:255',
            'nome_oficina' => 'nullable|string|max:255',
            'whatsapp' => 'nullable|string|max:30',
            'vehicle_id' => 'nullable|integer|exists:vehicles,id',
            'veiculo_descricao_livre' => 'nullable|string|max:255',
            'teamviewer_id' => 'nullable|string|max:255',
            'teamviewer_password' => 'nullable|string|max:255',
            'tipo_atendimento' => 'nullable|array',
            'tipo_atendimento.*' => 'string|max:255',
            'descricao_problema' => 'required|string',
        ]);

        $remoteService = RemoteService::create([
            ...request()->only([
                'person_id',
                'cliente_nome_livre',
                'nome_oficina',
                'whatsapp',
                'vehicle_id',
                'veiculo_descricao_livre',
                'teamviewer_id',
                'teamviewer_password',
                'tipo_atendimento',
                'descricao_problema',
            ]),
            'grupo_id' => $this->deriveGrupoId(request('tipo_atendimento', [])),
            'consultor_id' => auth()->id(),
        ]);

        session()->flash('success', 'Atendimento registrado.');

        return redirect()->route('admin.remote_services.view', $remoteService->id);
    }

    /**
     * Display the specified resource.
     */
    public function view(int $id): View
    {
        $remoteService = RemoteService::with(['person', 'vehicle', 'consultor', 'tecnico', 'serviceOrder', 'grupo'])->findOrFail($id);

        $this->preventUnauthorizedGroupAccess($remoteService);

        return view('remote_service::view', [
            'remoteService' => $remoteService,
            'tiposPorGrupo' => $this->tiposPorGrupo(),
        ]);
    }

    /**
     * Approve a pending request that arrived through the public scheduling
     * link (status "Pendente"): confirm/correct the tipo_atendimento and
     * release it as a real, actionable occurrence.
     */
    public function approve(int $id): RedirectResponse
    {
        $remoteService = RemoteService::findOrFail($id);

        $this->preventUnauthorizedGroupAccess($remoteService);

        $this->validate(request(), [
            'tipo_atendimento' => 'required|array',
            'tipo_atendimento.*' => 'string|max:255',
        ]);

        $remoteService->update([
            'tipo_atendimento' => request('tipo_atendimento'),
            'grupo_id' => $this->deriveGrupoId(request('tipo_atendimento', [])),
            'status' => RemoteService::STATUS_ABERTO,
            'consultor_id' => $remoteService->consultor_id ?: auth()->id(),
            'iniciado_em' => $remoteService->iniciado_em ?: now(),
        ]);

        session()->flash('success', 'Atendimento aprovado e liberado.');

        return redirect()->route('admin.remote_services.view', $id);
    }

    /**
     * Starts the timer for this occurrence -- stamps "iniciado_em" and, if
     * nobody has claimed it as technician yet, assigns whoever started it.
     */
    public function start(int $id): RedirectResponse
    {
        $remoteService = RemoteService::findOrFail($id);

        $this->preventUnauthorizedGroupAccess($remoteService);

        if (! $remoteService->iniciado_em) {
            $remoteService->update([
                'iniciado_em' => now(),
                'tecnico_id' => $remoteService->tecnico_id ?: auth()->id(),
            ]);
        }

        return redirect()->route('admin.remote_services.view', $id);
    }

    /**
     * Update just the TeamViewer ID/password -- kept separate from a full
     * edit form since this is the one thing that commonly changes after
     * the occurrence is first logged (the TeamViewer password rotates).
     */
    public function updateTeamviewer(int $id): RedirectResponse
    {
        $remoteService = RemoteService::findOrFail($id);

        $this->preventUnauthorizedGroupAccess($remoteService);

        $this->validate(request(), [
            'teamviewer_id' => 'nullable|string|max:255',
            'teamviewer_password' => 'nullable|string|max:255',
        ]);

        $remoteService->update(request()->only(['teamviewer_id', 'teamviewer_password']));

        session()->flash('success', 'Dados do TeamViewer atualizados.');

        return redirect()->route('admin.remote_services.view', $id);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        $remoteService = RemoteService::findOrFail($id);

        $this->preventUnauthorizedGroupAccess($remoteService);

        $remoteService->delete();

        return response()->json([
            'message' => 'Atendimento excluído com sucesso.',
        ], 200);
    }

    /**
     * Mark the occurrence as resolved and auto-generate a real Service Order
     * from it. If there's no registered Person yet (only a free-typed
     * name), a minimal Person is created first. Same for Vehicle -- if
     * there's no registered Vehicle yet (only free-text), a minimal Vehicle
     * (marca/modelo/placa) is created so the Service Order can be created
     * for real.
     */
    public function finish(int $id): RedirectResponse
    {
        $remoteService = RemoteService::findOrFail($id);

        $this->preventUnauthorizedGroupAccess($remoteService);

        $rules = [
            'descricao_solucao' => 'required|string',
            'valor' => 'nullable|numeric|min:0',
            'pago' => 'nullable|boolean',
            'faturado' => 'nullable|boolean',
            'forma_pagamento' => 'nullable|string|max:255',
        ];

        if (! $remoteService->vehicle_id) {
            $rules['marca'] = 'nullable|string|max:255';
            $rules['modelo'] = 'nullable|string|max:255';
            $rules['placa'] = 'nullable|string|max:255|unique:vehicles,placa';
        }

        $this->validate(request(), $rules);

        if (! $remoteService->person_id) {
            $person = Person::create([
                'name' => $remoteService->cliente_nome_livre ?: 'Cliente não identificado',
                'emails' => [],
            ]);

            $remoteService->person_id = $person->id;
        }

        if (! $remoteService->vehicle_id) {
            // Marca/modelo/placa are optional -- if left blank, a
            // placeholder vehicle is created instead so the Service Order
            // (which always needs a real vehicle_id) can still be
            // generated without forcing the technician to fill these in.
            $vehicle = Vehicle::create([
                'person_id' => $remoteService->person_id,
                'marca' => request('marca') ?: 'Não informado',
                'modelo' => request('modelo') ?: 'Não informado',
                'placa' => request('placa') ?: 'REMOTO-'.$remoteService->id.'-'.now()->format('YmdHis'),
            ]);

            $remoteService->vehicle_id = $vehicle->id;
        }

        $serviceOrder = $this->serviceOrderRepository->create([
            'person_id' => $remoteService->person_id,
            'vehicle_id' => $remoteService->vehicle_id,
            'servico' => $remoteService->tipo_atendimento_label !== '-' ? $remoteService->tipo_atendimento_label : 'Atendimento remoto',
            'description' => request('descricao_solucao'),
        ]);

        $remoteService->update([
            'person_id' => $remoteService->person_id,
            'vehicle_id' => $remoteService->vehicle_id,
            'descricao_solucao' => request('descricao_solucao'),
            'valor' => request('valor'),
            'pago' => request()->boolean('pago'),
            'faturado' => request()->boolean('faturado'),
            'forma_pagamento' => request('forma_pagamento'),
            'status' => RemoteService::STATUS_FINALIZADO,
            'tecnico_id' => $remoteService->tecnico_id ?: auth()->id(),
            'finalizado_em' => now(),
            'service_order_id' => $serviceOrder->id,
        ]);

        session()->flash('success', 'Atendimento finalizado. Ordem de Serviço #'.$serviceOrder->id.' gerada.');

        return redirect()->route('admin.remote_services.view', $id);
    }
}
