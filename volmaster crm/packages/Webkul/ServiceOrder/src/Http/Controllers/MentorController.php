<?php

namespace Webkul\ServiceOrder\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\ServiceOrder\Services\Indicators\IndicatorCatalog;
use Webkul\ServiceOrder\Services\Indicators\IndicatorService;
use Webkul\ServiceOrder\Services\MentorService;

/**
 * Visão do Mentor: comparativo entre as oficinas (só indicadores em %),
 * evolução contra o Marco Zero e a manutenção do padrão da mentoria.
 */
class MentorController extends Controller
{
    public function __construct(protected MentorService $mentor) {}

    public function index(Request $request): View
    {
        $periodo = (string) $request->query('periodo', IndicatorService::currentPeriod());
        $periodo = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo) ? $periodo : IndicatorService::currentPeriod();
        $cargo = $request->query('cargo');

        $comparativo = $this->mentor->comparativo($periodo);

        if ($cargo && array_key_exists($cargo, IndicatorCatalog::CARGOS)) {
            $comparativo['linhas'] = array_values(array_filter($comparativo['linhas'], fn ($linha) => $linha['cargo'] === $cargo));
        }

        return view('service_order::mentor.index', [
            'periodo' => $periodo,
            'cargo' => $cargo,
            'cargos' => IndicatorCatalog::CARGOS,
            'comparativo' => $comparativo,
            'evolucao' => $this->mentor->evolucao($periodo),
        ]);
    }

    public function replicarMetas(): RedirectResponse
    {
        $quantidade = $this->mentor->replicarMetasPadrao();

        session()->flash('success', "Metas padrão da mentoria replicadas para {$quantidade} oficina(s).");
        $this->avisarFalhas();

        return redirect()->route('admin.mentor.index');
    }

    public function replicarPesos(): RedirectResponse
    {
        $quantidade = $this->mentor->replicarPesos();

        session()->flash('success', "Pesos do bônus replicados para {$quantidade} oficina(s).");
        $this->avisarFalhas();

        return redirect()->route('admin.mentor.index');
    }

    /**
     * Oficinas que não puderam ser atualizadas (sem as migrations, banco fora do ar...).
     */
    protected function avisarFalhas(): void
    {
        if ($this->mentor->falhas) {
            session()->flash('warning', 'Estas oficinas ficaram de fora e precisam ser atualizadas depois: '.implode(', ', $this->mentor->falhas).'.');
        }
    }
}
