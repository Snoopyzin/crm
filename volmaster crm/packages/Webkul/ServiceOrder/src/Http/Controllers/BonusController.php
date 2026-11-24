<?php

namespace Webkul\ServiceOrder\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\ServiceOrder\Models\BonusApuracao;
use Webkul\ServiceOrder\Models\BonusTrava;
use Webkul\ServiceOrder\Models\Colaborador;
use Webkul\ServiceOrder\Services\Bonus\BonusCatalog;
use Webkul\ServiceOrder\Services\Bonus\BonusService;
use Webkul\ServiceOrder\Services\Bonus\FechamentoService;
use Webkul\ServiceOrder\Services\Bonus\TravaService;
use Webkul\ServiceOrder\Services\FichaEnquadramento;
use Webkul\ServiceOrder\Services\Indicators\IndicatorService;

/**
 * Bônus mensal (capítulo 6 e fechamento do 9.2): notas por colaborador e
 * cargo, travas, revisão do gerente, aprovação do dono até o 5º dia útil e
 * exportação da folha.
 */
class BonusController extends Controller
{
    public function __construct(
        protected BonusService $bonus,
        protected TravaService $travas,
        protected FechamentoService $fechamento,
    ) {}

    public function index(Request $request): View
    {
        $periodo = $this->periodo($request);

        return view('service_order::bonus.index', [
            'periodo' => $periodo,
            'fase' => FichaEnquadramento::fase($periodo),
            'apuracoes' => BonusApuracao::with('colaborador')->where('periodo', $periodo)->get()->sortBy(fn ($a) => $a->colaborador?->nome.$a->cargo)->values(),
            'cargos' => WorkshopSettingsController::PAPEIS,
            'fechado' => $this->fechamento->fechado($periodo),
            'janela' => $this->bonus->janelaDeAprovacao($periodo),
            'travasPendentes' => BonusTrava::where('periodo', $periodo)->where('status', BonusTrava::PENDENTE)->count(),
        ]);
    }

    public function detalhe(int $id): View
    {
        $apuracao = BonusApuracao::with('colaborador')->findOrFail($id);

        return view('service_order::bonus.detalhe', [
            'apuracao' => $apuracao,
            'cargos' => WorkshopSettingsController::PAPEIS,
            'travas' => BonusTrava::where('periodo', $apuracao->periodo)->where('colaborador_id', $apuracao->colaborador_id)->where('cargo', $apuracao->cargo)->get(),
            'indicadores' => app(IndicatorService::class),
        ]);
    }

    /**
     * Calcula (ou recalcula) as notas do mês: o gerente confere os números antes de revisar.
     */
    public function calcular(Request $request): RedirectResponse
    {
        $periodo = $this->periodo($request);

        $this->travas->detectar($periodo);
        $total = $this->bonus->apurar($periodo)->count();

        session()->flash('success', "Notas calculadas: {$total} apuração(ões).");

        return redirect()->route('admin.mentoria.bonus', ['periodo' => $periodo]);
    }

    public function revisar(Request $request, int $id): RedirectResponse
    {
        $apuracao = BonusApuracao::findOrFail($id);

        return $this->executar($apuracao->periodo, function () use ($apuracao, $request) {
            $this->bonus->revisar($apuracao, auth()->guard('user')->user(), (string) $request->input('feedback'));
        }, 'Nota revisada e feedback registrado.');
    }

    public function aprovar(int $id): RedirectResponse
    {
        $apuracao = BonusApuracao::findOrFail($id);

        return $this->executar($apuracao->periodo, fn () => $this->bonus->aprovar($apuracao, auth()->guard('user')->user()), 'Bônus aprovado.');
    }

    /**
     * Aprova de uma vez todas as notas já revisadas do mês.
     */
    public function aprovarTodos(Request $request): RedirectResponse
    {
        $periodo = $this->periodo($request);

        return $this->executar($periodo, function () use ($periodo) {
            $aprovadas = 0;

            foreach (BonusApuracao::where('periodo', $periodo)->where('status', BonusApuracao::REVISADO)->get() as $apuracao) {
                $this->bonus->aprovar($apuracao, auth()->guard('user')->user());
                $aprovadas++;
            }

            if ($aprovadas === 0) {
                throw ValidationException::withMessages(['bonus' => ['Nenhuma nota revisada aguardando aprovação.']]);
            }
        }, 'Bônus revisados aprovados.');
    }

    public function estornar(Request $request, int $id): RedirectResponse
    {
        $apuracao = BonusApuracao::findOrFail($id);

        return $this->executar($apuracao->periodo, fn () => $this->bonus->estornar($apuracao, auth()->guard('user')->user(), (string) $request->input('justificativa')), 'Estorno registrado. A nota volta para conferência.');
    }

    /**
     * Variáveis da folha (CSV) para a contabilidade -- só bônus aprovado.
     */
    public function exportar(Request $request): Response
    {
        $periodo = $this->periodo($request);
        $linhas = $this->bonus->linhasDaFolha($periodo);

        $csv = "colaborador;cpf;cargo;nota;faixa_percentual;trava;valor\n";

        foreach ($linhas as $linha) {
            $csv .= implode(';', [
                str_replace(';', ',', (string) $linha['colaborador']),
                $linha['cpf'],
                $linha['cargo'],
                number_format((float) $linha['nota'], 1, ',', ''),
                $linha['faixa'],
                $linha['trava'],
                number_format((float) $linha['valor'], 2, ',', ''),
            ])."\n";
        }

        return response("\xEF\xBB\xBF".$csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="folha-bonus-'.$periodo.'.csv"',
        ]);
    }

    /**
     * O gerente fecha o Painel do Dono do mês.
     */
    public function consolidarPainel(Request $request): RedirectResponse
    {
        $periodo = $this->periodo($request);

        $this->fechamento->consolidarPainel($periodo, auth()->guard('user')->id());

        session()->flash('success', 'Painel do Dono de '.$periodo.' consolidado.');

        return redirect()->route('admin.mentoria.bonus', ['periodo' => $periodo]);
    }

    // ---- Travas ------------------------------------------------------------

    public function travasIndex(Request $request): View
    {
        $periodo = $this->periodo($request);

        return view('service_order::bonus.travas', [
            'periodo' => $periodo,
            'travas' => BonusTrava::with('colaborador')->where('periodo', $periodo)->orderByRaw("status = 'pendente' desc")->orderByDesc('id')->get(),
            'catalogo' => BonusCatalog::travas(),
            'colaboradores' => Colaborador::ativos()->orderBy('nome')->get(),
            'cargos' => WorkshopSettingsController::PAPEIS,
        ]);
    }

    public function travaStore(Request $request): RedirectResponse
    {
        $dados = $this->validate($request, [
            'periodo' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'colaborador_id' => 'required|integer|exists:colaboradores,id',
            'cargo' => 'required|string|max:20',
            'tipo' => 'required|string|max:60',
            'descricao' => 'required|string|max:500',
            'evidencia' => 'required|string|max:500',
            'service_order_id' => 'nullable|integer',
        ], ['evidencia.required' => 'A trava exige evidência (o que foi visto, onde e quando).']);

        return $this->executar($dados['periodo'], fn () => $this->travas->registrar(
            Colaborador::findOrFail($dados['colaborador_id']), $dados['cargo'], $dados['tipo'], $dados['descricao'], $dados['evidencia'], $dados['periodo'], auth()->guard('user')->user(), $dados['service_order_id'] ?? null
        ), 'Trava registrada. Falta a confirmação do gerente.', 'admin.mentoria.bonus.travas');
    }

    public function travaDecidir(Request $request, int $id): RedirectResponse
    {
        $trava = BonusTrava::findOrFail($id);

        $dados = $this->validate($request, ['decisao' => 'required|in:confirmar,rejeitar', 'comentario' => 'nullable|string|max:500']);

        return $this->executar($trava->periodo, fn () => $this->travas->decidir(
            $trava, auth()->guard('user')->user(), $dados['decisao'] === 'confirmar', $dados['comentario'] ?? null
        ), $dados['decisao'] === 'confirmar' ? 'Trava confirmada: o bônus do colaborador no mês fica zerado.' : 'Trava rejeitada.', 'admin.mentoria.bonus.travas');
    }

    // ---- Meu bônus ------------------------------------------------------------

    public function meu(): View
    {
        $user = auth()->guard('user')->user();
        $colaborador = Colaborador::where('user_id', $user->id)->first();

        return view('service_order::bonus.meu', [
            'colaborador' => $colaborador,
            // O colaborador só vê a nota depois que o gerente revisou.
            'apuracoes' => $colaborador
                ? BonusApuracao::where('colaborador_id', $colaborador->id)->whereIn('status', [BonusApuracao::REVISADO, BonusApuracao::APROVADO])->orderByDesc('periodo')->get()
                : collect(),
            'cargos' => WorkshopSettingsController::PAPEIS,
        ]);
    }

    public function ciencia(int $id): RedirectResponse
    {
        $apuracao = BonusApuracao::findOrFail($id);

        try {
            $this->bonus->registrarCiencia($apuracao, auth()->guard('user')->user());
            session()->flash('success', 'Ciência registrada.');
        } catch (ValidationException $exception) {
            session()->flash('error', collect($exception->errors())->flatten()->first());
        }

        return redirect()->route('admin.mentoria.bonus.meu');
    }

    protected function executar(string $periodo, callable $acao, string $sucesso, string $rota = 'admin.mentoria.bonus'): RedirectResponse
    {
        try {
            $acao();
            session()->flash('success', $sucesso);
        } catch (ValidationException $exception) {
            session()->flash('error', collect($exception->errors())->flatten()->first());
        }

        return redirect()->route($rota, ['periodo' => $periodo]);
    }

    protected function periodo(Request $request): string
    {
        $periodo = (string) ($request->input('periodo') ?? $request->query('periodo', Carbon::now()->subMonthNoOverflow()->format('Y-m')));

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo) ? $periodo : Carbon::now()->subMonthNoOverflow()->format('Y-m');
    }
}
