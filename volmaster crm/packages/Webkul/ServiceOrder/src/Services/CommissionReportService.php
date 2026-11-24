<?php

namespace Webkul\ServiceOrder\Services;

use Carbon\Carbon;
use Webkul\Mechanic\Models\Mechanic;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\User\Models\User;

/**
 * Commissions over a period, counted by the date each order was billed
 * (faturado_em), cancelled orders excluded. Each Função (role) has its own
 * comissao_percentual. Two ways to earn:
 *
 * - Execução: whoever executed a line (the item's funcionario, i.e. the
 *   mechanic linked to a user in Configurações > Usuários) earns their %
 *   on that line's FINAL value -- after the discount spread in Negociação.
 * - Venda: the order's responsible user (consultor), when not linked to a
 *   mechanic, earns their % on the order's final total.
 */
class CommissionReportService
{
    public const KIND_EXECUCAO = 'Execução';

    public const KIND_VENDA = 'Venda';

    public function build(Carbon $from, Carbon $to): array
    {
        $orders = ServiceOrder::query()
            ->whereNotNull('faturado_em')
            ->whereBetween('faturado_em', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->where('workshop_status', '!=', ServiceOrder::STAGE_CANCELADA)
            ->with(['items', 'person'])
            ->orderBy('faturado_em')
            ->get();

        $usersByMechanic = User::whereNotNull('mechanic_id')->with('role')->get()->keyBy('mechanic_id');
        $users = User::with('role')->get()->keyBy('id');
        $mechanics = Mechanic::pluck('nome', 'id');

        $people = [];
        $warnings = ['sem_funcionario' => 0, 'sem_usuario' => []];

        foreach ($orders as $order) {
            $cliente = $order->person->name ?? '-';

            foreach ($order->items as $item) {
                // Lines added before "Executado por" existed count for the
                // order's mechanic.
                $item->funcionario_id = $item->funcionario_id ?: $order->mechanic_id;

                if (! $item->funcionario_id) {
                    $warnings['sem_funcionario']++;

                    continue;
                }

                $user = $usersByMechanic->get($item->funcionario_id);

                if (! $user) {
                    $warnings['sem_usuario'][$item->funcionario_id] = $mechanics[$item->funcionario_id] ?? ('#'.$item->funcionario_id);
                }

                $key = $user ? 'u'.$user->id : 'm'.$item->funcionario_id;

                $this->add($people, $key, [
                    'user_id' => $user?->id,
                    'name' => $user->name ?? ($mechanics[$item->funcionario_id] ?? '-'),
                    'role' => $user?->role?->name,
                    'percent' => $user?->role?->comissao_percentual,
                    'kind' => self::KIND_EXECUCAO,
                ], [
                    'os' => $order->id,
                    'date' => $order->faturado_em,
                    'cliente' => $cliente,
                    'descricao' => $item->name,
                    'gross' => $item->gross_total,
                    'discount' => $item->discount_total,
                    'base' => round($item->line_total, 2),
                ]);
            }

            $owner = $users->get($order->user_id);

            if ($owner && ! $owner->mechanic_id && (float) $owner->role?->comissao_percentual > 0) {
                $this->add($people, 'v'.$owner->id, [
                    'user_id' => $owner->id,
                    'name' => $owner->name,
                    'role' => $owner->role?->name,
                    'percent' => $owner->role?->comissao_percentual,
                    'kind' => self::KIND_VENDA,
                ], [
                    'os' => $order->id,
                    'date' => $order->faturado_em,
                    'cliente' => $cliente,
                    'descricao' => 'Total da OS',
                    'gross' => (float) $order->sub_total,
                    'discount' => round((float) $order->sub_total - (float) $order->grand_total, 2),
                    'base' => round((float) $order->grand_total, 2),
                ]);
            }
        }

        foreach ($people as &$person) {
            $person['commission'] = $person['percent'] !== null ? round($person['base'] * $person['percent'] / 100, 2) : 0.0;
        }

        unset($person);

        uasort($people, fn ($a, $b) => $b['commission'] <=> $a['commission'] ?: strcmp($a['name'], $b['name']));

        return [
            'people' => array_values($people),
            'orders' => $orders->count(),
            'total_base' => round(array_sum(array_column($people, 'base')), 2),
            'total_commission' => round(array_sum(array_column($people, 'commission')), 2),
            'warnings' => $warnings,
        ];
    }

    protected function add(array &$people, string $key, array $person, array $line): void
    {
        $people[$key] ??= $person + ['base' => 0.0, 'lines' => []];

        $people[$key]['base'] = round($people[$key]['base'] + $line['base'], 2);
        $people[$key]['lines'][] = $line;
    }
}
