<?php

namespace Webkul\ServiceOrder\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\ServiceOrder\Services\HistoricoImportService;

/**
 * Importação das planilhas de medição na virada para o sistema (capítulo 9.3).
 */
class HistoricoImportController extends Controller
{
    public function __construct(protected HistoricoImportService $importador) {}

    public function index(): View
    {
        return view('service_order::historico.index', [
            'tipos' => HistoricoImportService::TIPOS,
            'resultado' => session('resultado_importacao'),
        ]);
    }

    public function importar(Request $request)
    {
        $dados = $this->validate($request, [
            'tipo' => 'required|in:'.implode(',', array_keys(HistoricoImportService::TIPOS)),
            'arquivo' => 'required|file|max:10240|mimes:csv,txt,xlsx,xls',
            'simular' => 'nullable|in:0,1',
        ]);

        try {
            $resultado = $this->importador->importar($dados['tipo'], $request->file('arquivo')->getRealPath(), (bool) ($dados['simular'] ?? false));
        } catch (\InvalidArgumentException $exception) {
            session()->flash('error', $exception->getMessage());

            return redirect()->route('admin.historico.index');
        }

        $resultado['simulado'] = (bool) ($dados['simular'] ?? false);
        $resultado['tipo'] = HistoricoImportService::TIPOS[$dados['tipo']]['nome'];

        return redirect()->route('admin.historico.index')->with('resultado_importacao', $resultado);
    }

    public function modelo(string $tipo): Response
    {
        return response($this->importador->modelo($tipo), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="modelo-'.$tipo.'.csv"',
        ]);
    }
}
