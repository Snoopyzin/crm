<?php

namespace Webkul\ServiceOrder\DataGrids;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Services\ClienteContatos;
use Webkul\ServiceOrder\Services\StageGate;
use Webkul\ServiceOrder\Services\ValoresFinanceiros;

class ServiceOrderDataGrid extends DataGrid
{
    /**
     * Prepare query builder.
     */
    public function prepareQueryBuilder(): Builder
    {
        $queryBuilder = DB::table('quotes')
            ->leftJoin('persons', 'quotes.person_id', '=', 'persons.id')
            ->leftJoin('vehicles', 'quotes.vehicle_id', '=', 'vehicles.id')
            ->leftJoin('mechanics', 'quotes.mechanic_id', '=', 'mechanics.id')
            ->where(fn ($query) => $query->whereNotNull('quotes.vehicle_id')->orWhereNotNull('quotes.workshop_status'))
            ->when(request()->route('etapa'), function ($query, $stage) {
                $query->where('quotes.workshop_status', $stage);
            })
            ->when(bouncer()->getAuthorizedUserIds(), function ($query, $authorizedUserIds) {
                // "Individual"/"Grupo" visibility: só quem criou a OS, ou o
                // mecânico vinculado ao usuário logado, vê essa OS -- mesma
                // regra usada pra Leads (Bouncer::getAuthorizedUserIds()),
                // estendida aqui pra também cobrir o mecânico atribuído.
                $mechanicId = auth()->guard('user')->user()?->mechanic_id;

                $query->where(function ($query) use ($authorizedUserIds, $mechanicId) {
                    $query->whereIn('quotes.user_id', $authorizedUserIds);

                    if ($mechanicId) {
                        $query->orWhere('quotes.mechanic_id', $mechanicId);
                    }
                });
            })
            ->select(
                'quotes.id',
                'quotes.subject',
                'quotes.workshop_status',
                'quotes.data_previsao',
                'quotes.created_at',
                'persons.name as person_name',
                'persons.contact_numbers as person_contact_numbers',
                'vehicles.placa as vehicle_placa',
                'mechanics.nome as mechanic_name'
            )
            // Perfil sem permissão de valores (Técnico): o total nem entra na consulta.
            ->when(ValoresFinanceiros::can(), fn ($query) => $query->addSelect('quotes.grand_total'))
            // Semáforo de prazo: a barreira aberta que vence primeiro (piloto ligado).
            ->when(StageGate::active(), function ($query) {
                $aberta = "from barrier_instances bi where bi.service_order_id = quotes.id and bi.status <> 'cumprida' and bi.vence_em is not null order by bi.vence_em asc limit 1";

                $query->selectRaw("(select bi.vence_em {$aberta}) as prazo_vence_em")
                    ->selectRaw("(select bi.aberta_em {$aberta}) as prazo_aberta_em")
                    ->selectRaw("(select max(bn.nivel_atual) from barrier_instances bn where bn.service_order_id = quotes.id and bn.status <> 'cumprida') as prazo_nivel");
            });

        $this->addFilter('id', 'quotes.id');
        $this->addFilter('created_at', 'quotes.created_at');

        return $queryBuilder;
    }

    /**
     * Prepare columns.
     */
    public function prepareColumns(): void
    {
        $this->addColumn([
            'index' => 'id',
            'label' => trans('service_order::app.index.datagrid.numero'),
            'type' => 'integer',
            'sortable' => true,
        ]);

        $this->addColumn([
            'index' => 'subject',
            'label' => trans('service_order::app.index.datagrid.servico'),
            'type' => 'string',
            'searchable' => true,
            'sortable' => true,
            'filterable' => true,
            // A etapa atual já tem coluna própria: o prefixo velho ("Cadastro - ") não aparece aqui.
            'closure' => fn ($row) => ServiceOrder::semPrefixoDeEtapa($row->subject),
        ]);

        $this->addColumn([
            'index' => 'person_name',
            'label' => trans('service_order::app.index.datagrid.cliente'),
            'type' => 'string',
            'searchable' => true,
            'sortable' => true,
            'filterable' => true,
        ]);

        // Cadastro do cliente: o telefone aparece na própria lista (pedido do patrão, 19/11/2026).
        if (request()->route('etapa') === ServiceOrder::STAGE_CADASTRO) {
            $this->addColumn([
                'index' => 'person_contact_numbers',
                'label' => 'Telefone',
                'type' => 'string',
                'closure' => function ($row) {
                    $numeros = is_string($row->person_contact_numbers) ? json_decode($row->person_contact_numbers, true) : $row->person_contact_numbers;

                    return e(ClienteContatos::linhaTelefones(is_array($numeros) ? $numeros : null)) ?: '—';
                },
            ]);
        }

        $this->addColumn([
            'index' => 'vehicle_placa',
            'label' => trans('service_order::app.index.datagrid.veiculo'),
            'type' => 'string',
            'sortable' => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index' => 'mechanic_name',
            'label' => trans('service_order::app.index.datagrid.mecanico'),
            'type' => 'string',
            'sortable' => true,
        ]);

        $this->addColumn([
            'index' => 'workshop_status',
            'label' => trans('service_order::app.index.datagrid.status'),
            'type' => 'string',
            'sortable' => true,
            'filterable' => true,
            // O nome da etapa na tela vem do idioma ("Cadastro" é mostrado como "Cadastro do cliente").
            'closure' => function ($row) {
                $chave = 'service_order::app.stages.'.$row->workshop_status;
                $texto = trans($chave);

                return $texto === $chave ? $row->workshop_status : $texto;
            },
        ]);

        if (StageGate::active()) {
            $this->addColumn([
                'index' => 'prazo_vence_em',
                'label' => 'Prazo',
                'type' => 'string',
                'sortable' => true,
                'closure' => fn ($row) => self::semaforo($row),
            ]);
        }

        // Perfil sem permissão de valores (Técnico) não recebe nem a coluna de total.
        if (ValoresFinanceiros::can()) {
            $this->addColumn([
                'index' => 'grand_total',
                'label' => trans('service_order::app.index.datagrid.total'),
                'type' => 'string',
                'sortable' => true,
                'closure' => function ($row) {
                    return core()->formatBasePrice($row->grand_total);
                },
            ]);
        }

        $this->addColumn([
            'index' => 'created_at',
            'label' => trans('service_order::app.index.datagrid.created-at'),
            'type' => 'date',
            'sortable' => true,
            'filterable' => true,
            'filterable_type' => 'date_range',
            'closure' => function ($row) {
                return core()->formatDate($row->created_at);
            },
        ]);
    }

    /**
     * Semáforo da OS: vencida (vermelho), a vencer (amarelo: faltam menos de
     * 20% do prazo) ou no prazo (verde). Sem barreira aberta, um traço.
     */
    public static function semaforo(object $row): string
    {
        if (empty($row->prazo_vence_em)) {
            return '<span style="color:#90a4ae">—</span>';
        }

        $vence = Carbon::parse($row->prazo_vence_em);
        $aberta = Carbon::parse($row->prazo_aberta_em ?: $row->prazo_vence_em);
        $total = max(1, $aberta->diffInSeconds($vence, false));
        $restante = now()->diffInSeconds($vence, false);

        [$cor, $fundo, $texto] = match (true) {
            (int) $row->prazo_nivel > 0 || $restante <= 0 => ['#991b1b', '#fee2e2', 'Vencida'],
            $restante <= $total * 0.2 => ['#92400e', '#fef3c7', 'A vencer'],
            default => ['#166534', '#dcfce7', 'No prazo'],
        };

        return '<span style="padding:2px 8px;border-radius:999px;font-size:12px;font-weight:600;background:'.$fundo.';color:'.$cor.'">'.$texto.'</span>';
    }

    /**
     * Prepare actions.
     */
    public function prepareActions(): void
    {
        $this->addAction([
            'icon' => 'icon-eye',
            'title' => trans('service_order::app.index.datagrid.view'),
            'method' => 'GET',
            'url' => function ($row) {
                return route('admin.service_orders.view', $row->id);
            },
        ]);

        if (bouncer()->hasPermission('service_orders.delete')) {
            $this->addAction([
                'icon' => 'icon-delete',
                'title' => trans('service_order::app.index.datagrid.delete'),
                'method' => 'DELETE',
                'url' => function ($row) {
                    return route('admin.service_orders.destroy', $row->id);
                },
            ]);
        }
    }

    /**
     * Prepare mass actions.
     */
    public function prepareMassActions(): void
    {
        //
    }
}
