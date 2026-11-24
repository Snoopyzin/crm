<?php

namespace Webkul\ServiceOrder\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Services\ValoresFinanceiros;

/**
 * Marketing: which channel ("Por qual meio você chegou à nossa empresa?")
 * brings clients and revenue. OS opened in the period, how many of them were
 * billed, how much they billed and the average ticket -- per channel.
 * The report can be exported (Excel/CSV) to measure the return of each
 * investment (outdoor, social media, ads...).
 */
class MarketingController extends Controller
{
    public const NAO_INFORMADO = 'Não informado';

    public function index(): View
    {
        // Faturamento por canal é dado financeiro: perfil Técnico não vê.
        ValoresFinanceiros::authorize('marketing');

        [$from, $to] = $this->period();

        $orders = $this->orders($from, $to, ['id', 'origem_cliente', 'faturado_em', 'grand_total']);
        $channels = $this->channels($orders);

        return view('service_order::marketing.index', [
            'from' => $from,
            'to' => $to,
            'channels' => $channels,
            'totalOs' => $orders->count(),
            'totalRevenue' => round($channels->sum('faturamento'), 2),
        ]);
    }

    /**
     * Exporta o relatório do período para o Excel.
     *  - tipo=resumo (padrão): uma linha por canal, com a linha de total;
     *  - tipo=detalhe: uma linha por OS, com o canal, para filtrar/somar no Excel.
     */
    public function export(): StreamedResponse
    {
        ValoresFinanceiros::authorize('marketing');

        [$from, $to] = $this->period();
        $detalhe = request('tipo') === 'detalhe';
        $money = fn ($value) => number_format((float) $value, 2, ',', '');
        $nome = 'marketing-'.($detalhe ? 'os-por-canal' : 'resumo').'-'.$from->format('Y-m-d').'-a-'.$to->format('Y-m-d').'.csv';

        $orders = $detalhe
            ? ServiceOrder::query()
                ->with(['person', 'vehicle'])
                ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->where('workshop_status', '!=', ServiceOrder::STAGE_CANCELADA)
                ->orderBy('created_at')
                ->get()
            : $this->orders($from, $to, ['id', 'origem_cliente', 'faturado_em', 'grand_total']);

        $channels = $this->channels($orders);

        return response()->streamDownload(function () use ($detalhe, $orders, $channels, $money) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            if ($detalhe) {
                fputcsv($out, ['OS', 'Aberta em', 'Canal', 'Cliente', 'Placa', 'Etapa atual', 'Faturada em', 'Faturamento'], ';');

                foreach ($orders as $order) {
                    fputcsv($out, [
                        $order->id,
                        $order->created_at?->format('d/m/Y'),
                        $order->origem_cliente ?: self::NAO_INFORMADO,
                        $order->person?->name,
                        $order->vehicle?->placa,
                        $order->workshop_status,
                        $order->faturado_em?->format('d/m/Y'),
                        $money($order->faturado_em ? $order->grand_total : 0),
                    ], ';');
                }
            } else {
                fputcsv($out, ['Canal', 'OS abertas', 'Faturadas', 'Conversão (%)', 'Faturamento', 'Ticket médio'], ';');

                foreach ($channels as $channel => $row) {
                    fputcsv($out, [
                        $channel,
                        $row['os'],
                        $row['faturadas'],
                        $money($row['conversao']),
                        $money($row['faturamento']),
                        $money($row['ticket']),
                    ], ';');
                }

                $faturadas = $channels->sum('faturadas');
                $faturamento = round($channels->sum('faturamento'), 2);

                fputcsv($out, [
                    'TOTAL',
                    $channels->sum('os'),
                    $faturadas,
                    $money($channels->sum('os') ? $faturadas / $channels->sum('os') * 100 : 0),
                    $money($faturamento),
                    $money($faturadas ? $faturamento / $faturadas : 0),
                ], ';');
            }

            fclose($out);
        }, $nome, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function period(): array
    {
        try {
            $from = request('de') ? Carbon::createFromFormat('Y-m-d', request('de')) : now()->startOfMonth();
            $to = request('ate') ? Carbon::createFromFormat('Y-m-d', request('ate')) : now();
        } catch (\Throwable $e) {
            [$from, $to] = [now()->startOfMonth(), now()];
        }

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    /**
     * @param  string[]  $columns
     */
    protected function orders(Carbon $from, Carbon $to, array $columns): Collection
    {
        return ServiceOrder::query()
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->where('workshop_status', '!=', ServiceOrder::STAGE_CANCELADA)
            ->get($columns);
    }

    /**
     * Números de cada canal (as origens do cadastro + "Não informado").
     */
    protected function channels(Collection $orders): Collection
    {
        return collect([...ServiceOrder::ORIGENS, self::NAO_INFORMADO])
            ->mapWithKeys(function ($channel) use ($orders) {
                $group = $orders->filter(fn ($order) => ($order->origem_cliente ?: self::NAO_INFORMADO) === $channel);
                $billed = $group->whereNotNull('faturado_em');
                $revenue = round($billed->sum(fn ($order) => (float) $order->grand_total), 2);

                return [$channel => [
                    'os' => $group->count(),
                    'faturadas' => $billed->count(),
                    'faturamento' => $revenue,
                    'ticket' => $billed->count() ? round($revenue / $billed->count(), 2) : 0.0,
                    'conversao' => $group->count() ? round($billed->count() / $group->count() * 100, 1) : 0.0,
                ]];
            });
    }
}
