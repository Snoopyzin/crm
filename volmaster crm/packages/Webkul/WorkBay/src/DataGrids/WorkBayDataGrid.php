<?php

namespace Webkul\WorkBay\DataGrids;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

class WorkBayDataGrid extends DataGrid
{
    /**
     * Prepare query builder.
     */
    public function prepareQueryBuilder(): Builder
    {
        $queryBuilder = DB::table('work_bays')
            ->select('id', 'name', 'capacity', 'status', 'created_at');

        $this->addFilter('id', 'work_bays.id');

        return $queryBuilder;
    }

    /**
     * Prepare columns.
     */
    public function prepareColumns(): void
    {
        $this->addColumn([
            'index' => 'name',
            'label' => trans('workbay::app.index.datagrid.name'),
            'type' => 'string',
            'searchable' => true,
            'sortable' => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index' => 'capacity',
            'label' => trans('workbay::app.index.datagrid.capacity'),
            'type' => 'integer',
            'sortable' => true,
        ]);

        $this->addColumn([
            'index' => 'status',
            'label' => trans('workbay::app.index.datagrid.status'),
            'type' => 'string',
            'sortable' => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index' => 'created_at',
            'label' => trans('workbay::app.index.datagrid.created-at'),
            'type' => 'date',
            'sortable' => true,
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
            'title' => trans('workbay::app.index.datagrid.view'),
            'method' => 'GET',
            'url' => function ($row) {
                return route('admin.work_bays.view', $row->id);
            },
        ]);

        if (bouncer()->hasPermission('work_bays.edit')) {
            $this->addAction([
                'icon' => 'icon-edit',
                'title' => trans('workbay::app.index.datagrid.edit'),
                'method' => 'GET',
                'url' => function ($row) {
                    return route('admin.work_bays.edit', $row->id);
                },
            ]);
        }

        if (bouncer()->hasPermission('work_bays.delete')) {
            $this->addAction([
                'icon' => 'icon-delete',
                'title' => trans('workbay::app.index.datagrid.delete'),
                'method' => 'DELETE',
                'url' => function ($row) {
                    return route('admin.work_bays.destroy', $row->id);
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
