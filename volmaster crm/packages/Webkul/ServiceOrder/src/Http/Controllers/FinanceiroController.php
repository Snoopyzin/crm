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
use Webkul\ServiceOrder\Models\Lancamento;
use Webkul\ServiceOrder\Models\PlanoConta;
use Webkul\ServiceOrder\Services\Financeiro\DreService;
use Webkul\ServiceOrder\Services\Financeiro\FinanceiroService;
use Webkul\ServiceOrder\Services\Indicators\IndicatorService;
use Webkul\ServiceOrder\Services\WorkshopSettings;
use Webkul\ServiceOrder\Support\BrNumber;

/**
 * Financeiro da oficina: contas a pagar classificadas, caixa do dia,
 * conciliação, régua de cobrança, relatórios diários e a DRE gerencial com
 * preço da hora, ponto de equilíbrio, margem por OS, simulador, caixa
 * projetado, orçamento anual e Marco Zero.
 */
class FinanceiroController extends Controller
{
    public function __construct(
        protected FinanceiroService $financeiro,
        protected DreService $dre,
    ) {}

    // ---- Rotina: contas, caixa, cobrança e relatórios ----------------------------

    public function lancamentos(Request $request): View
    {
        PlanoConta::ensureDefaultsOnce();

        $filtro = $request->query('filtro', 'abertos');

        $lancamentos = Lancamento::with('conta')->orderBy('vencimento')
            ->when($filtro === 'abertos', fn ($q) => $q->emAberto())
            ->when($filtro === 'pagos', fn ($q) => $q->whereNotNull('pago_em')->orderByDesc('pago_em'))
            ->when($filtro === 'sem_conta', fn ($q) => $q->semConta())
            ->limit(200)->get();

        return view('service_order::financeiro.lancamentos', [
            'lancamentos' => $lancamentos,
            'filtro' => $filtro,
            'contas' => PlanoConta::where('ativo', true)->orderBy('codigo')->get(),
            'grupos' => PlanoConta::GRUPOS,
            'formas' => Lancamento::FORMAS,
            'semConta' => Lancamento::semConta()->count(),
        ]);
    }

    public function storeLancamento(Request $request): RedirectResponse
    {
        $dados = $this->validate($request, [
            'descricao' => 'required|string|max:255',
            'valor' => 'required|numeric|min:0.01',
            'vencimento' => 'required|date',
            'competencia' => 'nullable|date',
            'conta_id' => 'nullable|integer|exists:plano_contas,id',
            'parceiro' => 'nullable|string|max:120',
            'service_order_id' => 'nullable|integer',
            'programada' => 'nullable|in:0,1',
            'pagamento_pecas' => 'nullable|in:0,1',
        ]);

        $this->financeiro->criar($dados + ['programada' => $dados['programada'] ?? 1]);

        session()->flash('success', 'Conta a pagar lançada.');

        return redirect()->route('admin.financeiro.lancamentos');
    }

    public function classificar(Request $request, int $id): RedirectResponse
    {
        $this->validate($request, ['conta_id' => 'required|integer']);

        return $this->executar(fn () => $this->financeiro->classificar(Lancamento::findOrFail($id), (int) $request->input('conta_id')), 'Conta escolhida.', 'admin.financeiro.lancamentos');
    }

    public function pagar(Request $request, int $id): RedirectResponse
    {
        $dados = $this->validate($request, [
            'pago_em' => 'nullable|date',
            'forma' => 'nullable|in:'.implode(',', array_keys(Lancamento::FORMAS)),
            'juros_multa' => 'nullable|in:0,1',
            'nf_entrada_conferida' => 'nullable|in:0,1',
        ]);

        return $this->executar(fn () => $this->financeiro->pagar(Lancamento::findOrFail($id), $dados, auth()->guard('user')->user()), 'Pagamento registrado.', 'admin.financeiro.lancamentos');
    }

    public function contas(): View
    {
        PlanoConta::ensureDefaultsOnce();

        return view('service_order::financeiro.contas', [
            'contas' => PlanoConta::orderBy('codigo')->get(),
            'grupos' => PlanoConta::GRUPOS,
        ]);
    }

    public function storeSubconta(Request $request): RedirectResponse
    {
        $dados = $this->validate($request, ['pai_id' => 'required|integer|exists:plano_contas,id', 'nome' => 'required|string|max:255']);

        PlanoConta::criarSubconta(PlanoConta::findOrFail($dados['pai_id']), $dados['nome']);

        session()->flash('success', 'Subconta criada.');

        return redirect()->route('admin.financeiro.contas');
    }

    public function caixa(Request $request): View
    {
        $dia = $this->dia($request);

        return view('service_order::financeiro.caixa', [
            'dia' => $dia,
            'fechamento' => DB::table('caixa_fechamentos')->whereDate('data', $dia->toDateString())->first(),
            'saldoInicial' => $this->financeiro->saldoDoDiaAnterior($dia),
            'saidas' => (float) Lancamento::whereDate('pago_em', $dia->toDateString())->sum('valor'),
            'semConta' => Lancamento::whereDate('pago_em', $dia->toDateString())->whereNull('conta_id')->count(),
            'regua' => $this->financeiro->reguaPendente(),
            'historico' => DB::table('caixa_fechamentos')->orderByDesc('data')->limit(10)->get(),
        ]);
    }

    public function fecharCaixa(Request $request): RedirectResponse
    {
        $dados = $this->validate($request, [
            'data' => 'required|date',
            'saldo_inicial' => 'nullable|numeric',
            'entradas' => 'nullable|array',
            'entradas.*' => 'nullable|numeric|min:0',
            'saldo_contado' => 'required|numeric',
            'justificativa' => 'nullable|string|max:500',
        ]);

        return $this->executar(function () use ($dados) {
            $resultado = $this->financeiro->fecharCaixa($dados, auth()->guard('user')->user());

            if (abs($resultado['diferenca']) >= 0.005) {
                session()->flash('warning', 'Há diferença no caixa: o gerente e o dono foram avisados.');
            }
        }, 'Caixa do dia fechado.', 'admin.financeiro.caixa', ['dia' => $dados['data']]);
    }

    public function conciliar(Request $request): RedirectResponse
    {
        $dia = $this->dia($request);

        return $this->executar(function () use ($dia) {
            $resultado = $this->financeiro->conciliarDia($dia, auth()->guard('user')->user());

            if ($resultado['sem_conta'] > 0) {
                throw ValidationException::withMessages(['conciliacao' => [$resultado['sem_conta'].' pagamento(s) sem conta não entram na conciliação: escolha a conta de cada um.']]);
            }
        }, 'Dia conciliado.', 'admin.financeiro.caixa', ['dia' => $dia->toDateString()]);
    }

    public function contatoCobranca(Request $request): RedirectResponse
    {
        $dados = $this->validate($request, ['boleto_id' => 'required|integer', 'dia' => 'required|integer']);

        return $this->executar(fn () => $this->financeiro->registrarContatoDeCobranca((int) $dados['boleto_id'], (int) $dados['dia'], auth()->guard('user')->user()), 'Contato de cobrança registrado.', 'admin.financeiro.caixa');
    }

    public function relatorios(Request $request): View
    {
        $dia = $this->dia($request);

        return view('service_order::financeiro.relatorios', [
            'dia' => $dia,
            'relatorio' => $this->financeiro->relatorioDoDia($dia),
            'enviado' => DB::table('relatorios_financeiro')->whereDate('data', $dia->toDateString())->whereNotNull('enviado_em')->first(),
            'gerente' => DB::table('relatorios_gerente')->whereDate('data', $dia->toDateString())->first(),
            'horario' => (string) (WorkshopSettings::get('relatorio_financeiro_horario') ?: '17:30'),
        ]);
    }

    public function enviarRelatorio(Request $request): RedirectResponse
    {
        $dados = $this->validate($request, ['data' => 'required|date', 'alertas' => 'nullable|array|max:3', 'alertas.*' => 'nullable|string|max:300']);

        return $this->executar(fn () => $this->financeiro->enviarRelatorio(Carbon::parse($dados['data']), $dados['alertas'] ?? [], auth()->guard('user')->user()), 'Relatório diário enviado ao dono.', 'admin.financeiro.relatorios', ['dia' => $dados['data']]);
    }

    public function salvarRelatorioGerente(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'data' => 'required|date',
            'destaques' => 'nullable|string|max:1000', 'problemas' => 'nullable|string|max:1000', 'decisoes_tomadas' => 'nullable|string|max:1000',
            'decisoes_pendentes' => 'nullable|string|max:1000', 'equipe' => 'nullable|string|max:1000',
        ]);

        return $this->executar(fn () => $this->financeiro->salvarRelatorioGerente(Carbon::parse($dados['data']), $dados, auth()->guard('user')->user()), 'Relatório de 5 linhas salvo.', 'admin.financeiro.relatorios', ['dia' => $dados['data']]);
    }

    // ---- DRE e precificação ------------------------------------------------------------

    public function dre(Request $request): View
    {
        $periodo = $this->periodo($request);

        return view('service_order::financeiro.dre', [
            'periodo' => $periodo,
            'linhas' => $this->dre->linhas($periodo),
            'equilibrio' => $this->dre->pontoEquilibrio($periodo),
            'hora' => $this->dre->precoMinimoHora($periodo),
            'dre' => $this->dre->compute($periodo),
            'congelada' => $this->dre->congelada($periodo),
            'semConta' => Lancamento::semConta()->count(),
        ]);
    }

    public function congelar(Request $request): RedirectResponse
    {
        $periodo = $this->periodo($request);

        $this->dre->congelar($periodo);

        session()->flash('success', 'DRE de '.$periodo.' congelada. Só reabre com justificativa e a aprovação do dono.');

        return redirect()->route('admin.financeiro.dre', ['periodo' => $periodo]);
    }

    public function reabrir(Request $request): RedirectResponse
    {
        $periodo = $this->periodo($request);

        return $this->executar(fn () => $this->dre->reabrir($periodo, (string) $request->input('justificativa'), auth()->guard('user')->user()), 'DRE reaberta.', 'admin.financeiro.dre', ['periodo' => $periodo]);
    }

    public function analises(Request $request): View
    {
        $periodo = $this->periodo($request);
        $dre = $this->dre->compute($periodo, false);
        $media = $this->dre->media3($periodo);
        $parametros = $this->dre->parametros();

        return view('service_order::financeiro.analises', [
            'periodo' => $periodo,
            'margens' => $this->dre->margemPorOs($periodo),
            'clientes' => $this->dre->rankingClientes($periodo),
            'caixa' => $this->dre->caixaProjetado(),
            // Base do simulador "e se": nada é gravado.
            'simulador' => [
                'base' => [
                    'preco_hora' => $parametros['hora_cobrada'],
                    'produtividade' => $parametros['produtividade'] * 100,
                    'tecnicos' => max(1, Mechanic::where('status', 'Ativo')->count()),
                    'horas_mes' => $parametros['horas_mes'],
                    'margem_pecas' => $dre['receita_pecas'] > 0 ? round(($dre['receita_pecas'] - $dre['custo_pecas']) / $dre['receita_pecas'] * 100, 1) : $parametros['margem_pecas'] * 100,
                    'custo_pecas' => $media['custo_pecas'] ?: $dre['custo_pecas'],
                    'receita_pecas' => $media['receita_pecas'] ?: $dre['receita_pecas'],
                    'terceiros' => $media['terceiros'] ?: $dre['terceiros'],
                    'pessoal' => $media['pessoal'] ?: $dre['pessoal'],
                    'despesas_fixas' => $media['despesas_fixas'] ?: $dre['despesas_fixas'],
                    'impostos_pct' => $parametros['impostos'] * 100,
                    'comissoes_pct' => $parametros['comissoes'] * 100,
                    'desp_var_pecas_pct' => $parametros['desp_var_pecas'] * 100,
                ],
            ],
        ]);
    }

    public function orcamento(Request $request): View
    {
        $ano = (int) $request->query('ano', now()->year);

        $linhas = [
            'faturamento' => 'Faturamento', 'receita_mo' => 'Receita de mão de obra', 'receita_pecas' => 'Receita de peças',
            'margem_contribuicao' => 'Margem de contribuição', 'pessoal' => 'Pessoal', 'despesas_fixas' => 'Despesas fixas',
            'resultado_operacional' => 'Resultado operacional', 'resultado_final' => 'Resultado final',
        ];

        $metas = DB::table('orcamento_anual')->where('ano', $ano)->get()->groupBy('linha')->map(fn ($g) => $g->pluck('valor', 'mes'));

        $real = collect(range(1, 12))->mapWithKeys(fn ($mes) => [$mes => $mes <= now()->month || $ano < now()->year ? $this->dre->compute(sprintf('%d-%02d', $ano, $mes)) : null]);

        return view('service_order::financeiro.orcamento', [
            'ano' => $ano,
            'linhas' => $linhas,
            'metas' => $metas,
            'real' => $real,
            'marcoZero' => DB::table('marco_zero')->orderByDesc('id')->first(),
            'atual' => [
                'dre' => $this->dre->compute(now()->format('Y-m'), false),
                'hora' => $this->dre->precoMinimoHora(now()->format('Y-m')),
                'indicadores' => [
                    'produtividade' => app(IndicatorService::class)->evaluate('CHE-12', now()->format('Y-m'))['resultado']->realizado,
                    'retrabalho' => app(IndicatorService::class)->evaluate('CHE-10', now()->format('Y-m'))['resultado']->realizado,
                ],
            ],
        ]);
    }

    public function salvarOrcamento(Request $request): RedirectResponse
    {
        $this->validate($request, ['ano' => 'required|integer|min:2020|max:2100', 'meta' => 'nullable|array']);

        $ano = (int) $request->input('ano');

        foreach ((array) $request->input('meta', []) as $linha => $meses) {
            foreach ((array) $meses as $mes => $valor) {
                $valor = trim((string) $valor);

                if ($valor === '') {
                    DB::table('orcamento_anual')->where(['ano' => $ano, 'mes' => (int) $mes, 'linha' => $linha])->delete();

                    continue;
                }

                $numero = BrNumber::parse($valor) ?? 0.0;

                DB::table('orcamento_anual')->updateOrInsert(['ano' => $ano, 'mes' => (int) $mes, 'linha' => $linha], ['valor' => $numero, 'updated_at' => now(), 'created_at' => now()]);
            }
        }

        session()->flash('success', 'Orçamento anual salvo.');

        return redirect()->route('admin.financeiro.orcamento', ['ano' => $ano]);
    }

    public function salvarMarcoZero(Request $request): RedirectResponse
    {
        $dados = $this->validate($request, [
            'data' => 'required|date',
            'faturamento' => 'nullable|numeric', 'resultado' => 'nullable|numeric', 'hora_cobrada' => 'nullable|numeric|min:0',
            'preco_minimo_hora' => 'nullable|numeric|min:0', 'margem_pecas' => 'nullable|numeric', 'produtividade' => 'nullable|numeric|min:0|max:100',
            'retrabalho' => 'nullable|numeric|min:0|max:100', 'observacao' => 'nullable|string|max:500',
        ]);

        DB::table('marco_zero')->insert($dados + ['registrado_por' => auth()->guard('user')->id(), 'created_at' => now(), 'updated_at' => now()]);

        session()->flash('success', 'Marco Zero registrado.');

        return redirect()->route('admin.financeiro.orcamento');
    }

    protected function executar(callable $acao, string $sucesso, string $rota, array $params = []): RedirectResponse
    {
        try {
            $acao();
            session()->flash('success', $sucesso);
        } catch (ValidationException $exception) {
            session()->flash('error', collect($exception->errors())->flatten()->first());
        }

        return redirect()->route($rota, $params);
    }

    protected function dia(Request $request): Carbon
    {
        try {
            return Carbon::parse($request->input('dia', $request->query('dia', today()->toDateString())))->startOfDay();
        } catch (\Throwable) {
            return today();
        }
    }

    protected function periodo(Request $request): string
    {
        $periodo = (string) $request->query('periodo', now()->format('Y-m'));

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo) ? $periodo : now()->format('Y-m');
    }
}
