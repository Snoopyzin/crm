<?php

namespace Webkul\ServiceOrder\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\ServiceOrder\Services\Indicators\IndicatorService;
use Webkul\ServiceOrder\Services\Indicators\PainelDonoService;

/**
 * Painel do Dono: versão do computador (seis blocos do capítulo 8) e versão
 * do celular (PWA), que usa a mesma fonte de dados em JSON.
 */
class PainelDonoController extends Controller
{
    public function __construct(protected PainelDonoService $painel) {}

    public function index(Request $request): View
    {
        $periodo = $this->periodo($request);

        return view('service_order::mentoria.painel', [
            'painel' => $this->painel->build($periodo),
            'periodo' => $periodo,
            'comparar' => $request->boolean('comparar'),
            'anterior' => $request->boolean('comparar') ? $this->painel->build(Carbon::createFromFormat('Y-m', $periodo)->subMonth()->format('Y-m')) : null,
        ]);
    }

    /**
     * PDF do Painel para o encontro de mentoria (depois da aprovação do mês).
     */
    public function pdf(Request $request)
    {
        $periodo = $this->periodo($request);

        return Pdf::loadView('service_order::mentoria.painel-pdf', ['painel' => $this->painel->build($periodo)])
            ->setPaper('a4', 'portrait')
            ->download('painel-do-dono-'.$periodo.'.pdf');
    }

    /**
     * Página do celular: cartões grandes, números com uma casa decimal, sem
     * tabelas largas. Os dados vêm de dados() (JSON) para atualizar sem recarregar.
     */
    public function celular(): View
    {
        return view('service_order::mentoria.painel-celular', [
            'usuario' => auth()->guard('user')->user()?->name,
            'periodo' => IndicatorService::currentPeriod(),
        ]);
    }

    public function dados(Request $request): JsonResponse
    {
        $periodo = $this->periodo($request);
        $aba = $request->query('aba', 'hoje');

        $dados = match ($aba) {
            'alertas' => ['alertas' => $this->painel->alertas()],
            'indicadores' => $this->indicadores($periodo),
            'financeiro' => $this->painel->financeiro($periodo),
            default => $this->painel->hoje(),
        };

        return response()->json(['aba' => $aba, 'periodo' => $periodo] + $dados);
    }

    /**
     * Aba "Indicadores do mês": os 8 passos em verde, amarelo e vermelho, e os
     * blocos de caixa, gestão e equipe.
     *
     * @return array<string, mixed>
     */
    protected function indicadores(string $periodo): array
    {
        $painel = $this->painel->build($periodo);

        return [
            'cartoes' => $painel['cartoes'],
            'passos' => collect($painel['fluxo']['etapas'])->map(fn ($etapa) => [
                'etapa' => $etapa['etapa'],
                'agora' => $etapa['agora'],
                'parados' => $etapa['parados'],
                'situacao' => $this->piorSituacao($etapa['indicadores']),
                'indicadores' => $etapa['indicadores'],
            ])->all(),
            'blocos' => [
                ['titulo' => 'Peças e estoque', 'linhas' => $painel['pecas']['linhas']],
                ['titulo' => 'Caixa e financeiro', 'linhas' => $painel['financeiro']['linhas']],
                ['titulo' => 'Gestão e RH', 'linhas' => $painel['gestao']['linhas']],
                ['titulo' => 'Dono', 'linhas' => $painel['dono']['linhas']],
            ],
        ];
    }

    /**
     * Cor do passo = a pior situação entre os indicadores dele.
     *
     * @param  list<array<string, mixed>>  $linhas
     */
    protected function piorSituacao(array $linhas): string
    {
        $situacoes = collect($linhas)->pluck('situacao');

        return match (true) {
            $situacoes->contains('vermelho') => 'vermelho',
            $situacoes->contains('amarelo') => 'amarelo',
            $situacoes->contains('verde') => 'verde',
            default => 'sem_dado',
        };
    }

    protected function periodo(Request $request): string
    {
        $periodo = (string) $request->query('periodo', IndicatorService::currentPeriod());

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo) ? $periodo : IndicatorService::currentPeriod();
    }
}
