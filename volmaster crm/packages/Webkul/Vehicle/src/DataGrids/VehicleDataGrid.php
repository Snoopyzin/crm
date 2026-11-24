<?php

namespace Webkul\Vehicle\DataGrids;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

class VehicleDataGrid extends DataGrid
{
    /**
     * Prepare query builder.
     */
    public function prepareQueryBuilder(): Builder
    {
        $queryBuilder = DB::table('vehicles')
            ->leftJoin('persons', 'vehicles.person_id', '=', 'persons.id')
            ->select(
                'vehicles.id',
                'vehicles.marca',
                'vehicles.modelo',
                'vehicles.placa',
                'vehicles.ano',
                'vehicles.status',
                'vehicles.created_at',
                'persons.name as person_name'
            );

        $this->addFilter('id', 'vehicles.id');
        $this->addFilter('created_at', 'vehicles.created_at');

        return $queryBuilder;
    }

    /**
     * Prepare columns.
     */
    public function prepareColumns(): void
    {
        $this->addColumn([
            'index' => 'placa',
            'label' => trans('vehicle::app.index.datagrid.placa'),
            'type' => 'string',
            'searchable' => true,
            'sortable' => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index' => 'marca',
            'label' => trans('vehicle::app.index.datagrid.marca'),
            'type' => 'string',
            'searchable' => true,
            'sortable' => true,
            'filterable' => true,
            'closure' => function ($row) {
                return trim($row->marca.' '.$row->modelo);
            },
        ]);

        $this->addColumn([
            'index' => 'ano',
            'label' => trans('vehicle::app.index.datagrid.ano'),
            'type' => 'string',
            'sortable' => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index' => 'person_name',
            'label' => trans('vehicle::app.index.datagrid.cliente'),
            'type' => 'string',
            'searchable' => true,
            'sortable' => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index' => 'status',
            'label' => trans('vehicle::app.index.datagrid.status'),
            'type' => 'string',
            'sortable' => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index' => 'created_at',
            'label' => trans('vehicle::app.index.datagrid.created-at'),
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
     * Prepare actions.
     */
    public function prepareActions(): void
    {
        $this->addAction([
            'icon' => 'icon-eye',
            'title' => trans('vehicle::app.index.datagrid.view'),
            'method' => 'GET',
            'url' => function ($row) {
                return route('admin.vehicles.view', $row->id);
            },
        ]);

        if (bouncer()->hasPermission('vehicles.edit')) {
            $this->addAction([
                'icon' => 'icon-edit',
                'title' => trans('vehicle::app.index.datagrid.edit'),
                'method' => 'GET',
                'url' => function ($row) {
                    return route('admin.vehicles.edit', $row->id);
                },
            ]);
        }

        if (bouncer()->hasPermission('vehicles.delete')) {
            $this->addAction([
                'icon' => 'icon-delete',
                'title' => trans('vehicle::app.index.datagrid.delete'),
                'method' => 'DELETE',
                'url' => function ($row) {
                    return route('admin.vehicles.destroy', $row->id);
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
