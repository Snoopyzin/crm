<?php

namespace Webkul\RemoteService\DataGrids;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

class RemoteServiceDataGrid extends DataGrid
{
    /**
     * Prepare query builder.
     */
    public function prepareQueryBuilder(): Builder
    {
        $queryBuilder = DB::table('remote_services')
            ->leftJoin('persons', 'remote_services.person_id', '=', 'persons.id')
            ->select(
                'remote_services.id',
                'remote_services.cliente_nome_livre',
                'remote_services.nome_oficina',
                'remote_services.whatsapp',
                'remote_services.tipo_atendimento',
                'remote_services.teamviewer_id',
                'remote_services.status',
                'remote_services.valor',
                'remote_services.pago',
                'remote_services.created_at',
                'remote_services.iniciado_em',
                'remote_services.finalizado_em',
                'persons.name as person_name'
            );

        // Visibility here is by TEAM (which group the tipo_atendimento
        // belongs to), not by who created/is assigned to the record --
        // Diagnóstico only sees Diagnóstico work, Programações only sees
        // Programações work. Pending (unapproved) requests are always
        // hidden from non-global users, since they haven't been triaged yet.
        $user = auth()->guard('user')->user();

        if ($user->view_permission !== 'global') {
            $userGroupIds = $user->groups->pluck('id')->toArray();

            $queryBuilder->whereIn('remote_services.grupo_id', $userGroupIds)
                ->where('remote_services.status', '!=', 'Pendente');
        }

        $this->addFilter('id', 'remote_services.id');
        $this->addFilter('person_name', 'persons.name');

        return $queryBuilder;
    }

    /**
     * Prepare columns.
     */
    public function prepareColumns(): void
    {
        $this->addColumn([
            'index' => 'person_name',
            'label' => 'Cliente',
            'type' => 'string',
            'searchable' => true,
            'sortable' => true,
            'filterable' => true,
            'closure' => function ($row) {
                return $row->person_name ?: ($row->cliente_nome_livre ?: '-');
            },
        ]);

        $this->addColumn([
            'index' => 'nome_oficina',
            'label' => 'Oficina',
            'type' => 'string',
            'searchable' => true,
            'sortable' => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index' => 'tipo_atendimento',
            'label' => 'Tipo de atendimento',
            'type' => 'string',
            'sortable' => false,
            'closure' => function ($row) {
                $tipos = json_decode((string) $row->tipo_atendimento, true);

                return is_array($tipos) && count($tipos) ? implode(', ', $tipos) : '-';
            },
        ]);

        $this->addColumn([
            'index' => 'teamviewer_id',
            'label' => 'ID TeamViewer',
            'type' => 'string',
            'sortable' => false,
        ]);

        $this->addColumn([
            'index' => 'status',
            'label' => 'Status',
            'type' => 'string',
            'sortable' => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index' => 'valor',
            'label' => 'Valor',
            'type' => 'float',
            'sortable' => true,
            'closure' => function ($row) {
                return $row->valor !== null ? core()->formatBasePrice($row->valor) : '-';
            },
        ]);

        $this->addColumn([
            'index' => 'pago',
            'label' => 'Pago',
            'type' => 'boolean',
            'sortable' => true,
            'closure' => function ($row) {
                return $row->pago ? 'Sim' : 'Não';
            },
        ]);

        $this->addColumn([
            'index' => 'created_at',
            'label' => 'Criado em',
            'type' => 'date',
            'sortable' => true,
            'closure' => function ($row) {
                return core()->formatDate($row->created_at);
            },
        ]);

        $this->addColumn([
            'index' => 'iniciado_em',
            'label' => 'Tempo',
            'type' => 'string',
            'sortable' => false,
            'closure' => function ($row) {
                $inicio = \Illuminate\Support\Carbon::parse($row->iniciado_em ?? $row->created_at);

                $fim = $row->finalizado_em
                    ? \Illuminate\Support\Carbon::parse($row->finalizado_em)
                    : ($row->iniciado_em ? now() : null);

                if (! $fim) {
                    return '-';
                }

                $minutos = (int) floor($inicio->diffInMinutes($fim));

                if ($minutos < 60) {
                    return $minutos.' min';
                }

                $horas = intdiv($minutos, 60);
                $restoMinutos = $minutos % 60;

                return $restoMinutos > 0 ? "{$horas}h {$restoMinutos}min" : "{$horas}h";
            },
        ]);
    }

    /**
     * Prepare actions.
     */
    public function prepareActions(): void
    {
        $this->addAction([
            'icon' => 'icon-eye',
            'title' => 'Ver',
            'method' => 'GET',
            'url' => function ($row) {
                return route('admin.remote_services.view', $row->id);
            },
        ]);

        if (bouncer()->hasPermission('remote_services.delete')) {
            $this->addAction([
                'icon' => 'icon-delete',
                'title' => 'Excluir',
                'method' => 'DELETE',
                'url' => function ($row) {
                    return route('admin.remote_services.destroy', $row->id);
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
