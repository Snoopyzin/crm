<?php

namespace Webkul\Portal\DataGrids;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

class ServiceRequestDataGrid extends DataGrid
{
    public function prepareQueryBuilder(): Builder
    {
        $queryBuilder = DB::table('service_requests')
            ->leftJoin('persons', 'service_requests.person_id', '=', 'persons.id')
            ->leftJoin('vehicles', 'service_requests.vehicle_id', '=', 'vehicles.id')
            ->select(
                'service_requests.id',
                'service_requests.descricao',
                'service_requests.data_preferencia',
                'service_requests.status',
                'service_requests.created_at',
                'persons.name as person_name',
                'vehicles.placa as vehicle_placa'
            );

        $this->addFilter('id', 'service_requests.id');
        $this->addFilter('created_at', 'service_requests.created_at');

        return $queryBuilder;
    }

    public function prepareColumns(): void
    {
        $this->addColumn([
            'index' => 'person_name',
            'label' => trans('portal::app.admin.service-requests.index.cliente'),
            'type' => 'string',
            'searchable' => true,
            'sortable' => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index' => 'vehicle_placa',
            'label' => trans('portal::app.admin.service-requests.index.veiculo'),
            'type' => 'string',
            'searchable' => true,
            'sortable' => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index' => 'descricao',
            'label' => trans('portal::app.admin.service-requests.index.descricao'),
            'type' => 'string',
            'searchable' => true,
        ]);

        $this->addColumn([
            'index' => 'data_preferencia',
            'label' => trans('portal::app.admin.service-requests.index.data-preferencia'),
            'type' => 'date',
            'sortable' => true,
        ]);

        $this->addColumn([
            'index' => 'status',
            'label' => trans('portal::app.admin.service-requests.index.status'),
            'type' => 'string',
            'sortable' => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index' => 'created_at',
            'label' => trans('portal::app.admin.service-requests.index.created-at'),
            'type' => 'date',
            'sortable' => true,
            'filterable' => true,
            'filterable_type' => 'date_range',
            'closure' => function ($row) {
                return core()->formatDate($row->created_at);
            },
        ]);
    }

    public function prepareActions(): void
    {
        $this->addAction([
            'icon' => 'icon-eye',
            'title' => trans('portal::app.admin.service-requests.index.view'),
            'method' => 'GET',
            'url' => function ($row) {
                return route('admin.service_requests.view', $row->id);
            },
        ]);
    }

    public function prepareMassActions(): void
    {
        //
    }
}
