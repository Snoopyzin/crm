<?php

namespace Webkul\ServiceOrder\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Mechanic\Models\Mechanic;
use Webkul\ServiceOrder\Models\IndicatorDefinition;
use Webkul\ServiceOrder\Models\MentoriaRegistro;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Services\Indicators\IndicatorCatalog;
use Webkul\ServiceOrder\Services\Indicators\IndicatorFormatter;
use Webkul\ServiceOrder\Services\Indicators\IndicatorService;
use Webkul\ServiceOrder\Services\Indicators\PainelDonoService;
use Webkul\User\Models\User;

/**
 * Tela dos indicadores (todos os cargos), detalhe com as OS que formam o
 * número, registros manuais do dia a dia (auditorias com nota, ocorrências,
 * checklists...) e a tabela de metas da oficina.
 */
class IndicatorController extends Controller
{
    public function __construct(
        protected IndicatorService $indicators,
        protected PainelDonoService $painel,
    ) {}

    public function index(Request $request): View
    {
        $periodo = $this->periodo($request);
        $cargo = $request->query('cargo');
        $cargo = array_key_exists((string) $cargo, IndicatorCatalog::CARGOS) ? $cargo : 'consultor';

        $codigos = $this->indicators->definitions($cargo)->pluck('codigo')->all();

        return view('service_order::mentoria.indicadores', [
            'periodo' => $periodo,
            'cargo' => $cargo,
            'cargos' => IndicatorCatalog::CARGOS,
            'linhas' => $this->painel->linhas($codigos, $periodo),
        ]);
    }

    public function show(Request $request, string $codigo): View
    {
        $definicao = $this->indicators->definition($codigo);

        abort_unless($definicao, 404);

        $periodo = $this->periodo($request);
        $linha = $this->painel->linha($codigo, $periodo);

        $ordens = ServiceOrder::query()
            ->with(['vehicle:id,placa', 'person:id,name'])
            ->whereIn('quotes.id', array_slice($linha['os_ids'], 0, 200))
            ->orderByDesc('quotes.id')
            ->get(['quotes.id', 'quotes.vehicle_id', 'quotes.person_id', 'quotes.workshop_status']);

        $historico = collect($this->indicators->trend($codigo, Carbon::createFromFormat('Y-m', $periodo)->addMonth()->format('Y-m'), 6))
            ->map(fn ($valor, $ym) => ['periodo' => $ym, 'fmt' => IndicatorFormatter::value($valor, $definicao->unidade)]);

        return view('service_order::mentoria.indicador', [
            'definicao' => $definicao,
            'periodo' => $periodo,
            'linha' => $linha,
            'ordens' => $ordens,
            'historico' => $historico,
            'resultado' => $this->indicators->evaluate($definicao, $periodo)['resultado'],
        ]);
    }

    // ---- Registros do dia a dia -------------------------------------------

    public function registros(): View
    {
        return view('service_order::mentoria.registros', [
            'tipos' => collect(MentoriaRegistro::TIPOS)->reject(fn ($tipo) => $tipo['grupo'] === 'Sistema')->groupBy('grupo'),
            'registros' => MentoriaRegistro::query()->vigentes()->orderByDesc('ocorrido_em')->orderByDesc('id')->limit(60)->get(),
            'usuarios' => User::where('status', 1)->orderBy('name')->get(['id', 'name']),
            'mecanicos' => Mechanic::orderBy('nome')->get(['id', 'nome']),
            'rotulos' => collect(MentoriaRegistro::TIPOS)->map(fn ($tipo) => $tipo['label']),
        ]);
    }

    public function storeRegistro(Request $request): RedirectResponse
    {
        $tipos = MentoriaRegistro::TIPOS;
        $permitidos = array_keys(array_filter($tipos, fn ($tipo) => $tipo['grupo'] !== 'Sistema'));

        $dados = $this->validate($request, [
            'tipo' => 'required|in:'.implode(',', $permitidos),
            'ocorrido_em' => 'nullable|date',
            'valor' => 'nullable|numeric',
            'ok' => 'nullable|in:0,1',
            'user_id' => 'nullable|integer|exists:users,id',
            'mechanic_id' => 'nullable|integer|exists:mechanics,id',
            'service_order_id' => 'nullable|integer',
            'observacao' => 'nullable|string|max:500',
            'dados' => 'nullable|array',
            'dados.*' => 'nullable|string|max:120',
        ]);

        $definicao = $tipos[$dados['tipo']];

        if (isset($definicao['valor']) && ! array_key_exists('valor', array_filter($dados, fn ($v) => $v !== null && $v !== ''))) {
            throw ValidationException::withMessages(['valor' => ['Informe: '.$definicao['valor'].'.']]);
        }

        if (str_contains((string) ($definicao['valor'] ?? ''), 'Nota') && isset($dados['valor']) && ($dados['valor'] < 0 || $dados['valor'] > 10)) {
            throw ValidationException::withMessages(['valor' => ['A nota vai de 0 a 10.']]);
        }

        MentoriaRegistro::record($dados['tipo'], [
            'user_id' => $dados['user_id'] ?? null,
            'mechanic_id' => $dados['mechanic_id'] ?? null,
            'service_order_id' => $dados['service_order_id'] ?? null,
            'valor' => $dados['valor'] ?? null,
            'ok' => isset($dados['ok']) ? (bool) $dados['ok'] : null,
            'observacao' => $dados['observacao'] ?? null,
            'dados_json' => array_filter($dados['dados'] ?? [], fn ($v) => $v !== null && $v !== '') ?: null,
        ], ! empty($dados['ocorrido_em']) ? Carbon::parse($dados['ocorrido_em']) : null);

        session()->flash('success', 'Registro salvo.');

        return redirect()->route('admin.mentoria.registros');
    }

    public function correctRegistro(Request $request, int $id): RedirectResponse
    {
        $original = MentoriaRegistro::findOrFail($id);

        $dados = $this->validate($request, [
            'justificativa' => 'required|string|min:5|max:500',
            'valor' => 'nullable|numeric',
            'ok' => 'nullable|in:0,1',
        ], ['justificativa.required' => 'A correção exige uma justificativa.']);

        MentoriaRegistro::correct($original, array_filter([
            'valor' => $dados['valor'] ?? null,
            'ok' => isset($dados['ok']) ? (bool) $dados['ok'] : null,
        ], fn ($v) => $v !== null), $dados['justificativa']);

        session()->flash('success', 'Correção registrada. O registro original fica guardado.');

        return redirect()->route('admin.mentoria.registros');
    }

    // ---- Tabela de metas ----------------------------------------------------

    public function metas(): View
    {
        return view('service_order::mentoria.metas', [
            'definicoes' => $this->indicators->definitions(apenasAtivos: false)->groupBy('cargo'),
            'cargos' => IndicatorCatalog::CARGOS,
        ]);
    }

    public function updateMetas(Request $request): RedirectResponse
    {
        $this->validate($request, [
            'meta' => 'nullable|array',
            'meta.*' => 'nullable|string|max:20',
        ]);

        $codigos = IndicatorDefinition::pluck('codigo')->all();
        $metas = (array) $request->input('meta', []);
        $ativos = (array) $request->input('ativo', []);

        DB::transaction(function () use ($codigos, $metas, $ativos) {
            foreach ($codigos as $codigo) {
                $bruto = trim((string) ($metas[$codigo] ?? ''));
                $valor = $bruto === '' ? null : (float) str_replace(',', '.', $bruto);

                IndicatorDefinition::whereKey($codigo)->update([
                    'meta_oficina' => $valor,
                    'ativo' => array_key_exists($codigo, $ativos),
                ]);
            }
        });

        session()->flash('success', 'Metas salvas. Os indicadores já usam os novos valores.');

        return redirect()->route('admin.mentoria.metas');
    }

    protected function periodo(Request $request): string
    {
        $periodo = (string) $request->query('periodo', IndicatorService::currentPeriod());

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo) ? $periodo : IndicatorService::currentPeriod();
    }
}
