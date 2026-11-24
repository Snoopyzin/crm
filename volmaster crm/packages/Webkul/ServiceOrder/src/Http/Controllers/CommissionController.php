<?php

namespace Webkul\ServiceOrder\Http\Controllers;

use Carbon\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\ServiceOrder\Services\CommissionReportService;
use Webkul\ServiceOrder\Services\ValoresFinanceiros;

/**
 * Comissões report: per person, over a billing period (defaults to the
 * current month), with the order/item breakdown and a CSV export.
 */
class CommissionController extends Controller
{
    public function __construct(protected CommissionReportService $report)
    {
        // Comissões são dado financeiro: perfil Técnico não vê, nem por URL direta.
        $this->middleware(function ($request, $next) {
            ValoresFinanceiros::authorize('comissões');

            return $next($request);
        });
    }

    public function index(): View
    {
        [$from, $to] = $this->period();

        return view('service_order::commissions.index', [
            'from' => $from,
            'to' => $to,
            'report' => $this->report->build($from, $to),
        ]);
    }

    public function export(): StreamedResponse
    {
        [$from, $to] = $this->period();

        $report = $this->report->build($from, $to);

        $money = fn ($value) => number_format((float) $value, 2, ',', '');

        return response()->streamDownload(function () use ($report, $money) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Pessoa', 'Função', 'Tipo', '% comissão', 'OS', 'Faturada em', 'Cliente', 'Item', 'Valor cheio', 'Desconto', 'Base (valor final)', 'Comissão'], ';');

            foreach ($report['people'] as $person) {
                foreach ($person['lines'] as $line) {
                    fputcsv($out, [
                        $person['name'],
                        $person['role'] ?? '-',
                        $person['kind'],
                        $person['percent'] !== null ? $money($person['percent']) : 'não definido',
                        $line['os'],
                        $line['date']?->format('d/m/Y'),
                        $line['cliente'],
                        $line['descricao'],
                        $money($line['gross']),
                        $money($line['discount']),
                        $money($line['base']),
                        $money($person['percent'] !== null ? $line['base'] * $person['percent'] / 100 : 0),
                    ], ';');
                }
            }

            fclose($out);
        }, 'comissoes-'.$from->format('Y-m-d').'-a-'.$to->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function period(): array
    {
        try {
            $from = request('de') ? Carbon::createFromFormat('Y-m-d', request('de')) : now()->startOfMonth();
            $to = request('ate') ? Carbon::createFromFormat('Y-m-d', request('ate')) : now();
        } catch (\Throwable $e) {
            [$from, $to] = [now()->startOfMonth(), now()];
        }

        return $from->greaterThan($to) ? [$to, $from] : [$from, $to];
    }
}
