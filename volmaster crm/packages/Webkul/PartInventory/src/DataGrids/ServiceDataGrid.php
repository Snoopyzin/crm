<?php

namespace Webkul\PartInventory\DataGrids;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

class ServiceDataGrid extends DataGrid
{
    /**
     * Prepare query builder.
     */
    public function prepareQueryBuilder(): Builder
    {
        $queryBuilder = DB::table('services')
            ->leftJoin('part_categories', 'services.part_category_id', '=', 'part_categories.id')
            ->select(
                'services.id',
                'services.nome',
                'services.preco_padrao',
                'part_categories.name as category_name',
                'services.created_at'
            );

        $this->addFilter('id', 'services.id');
        $this->addFilter('created_at', 'services.created_at');

        return $queryBuilder;
    }

    /**
     * Prepare columns.
     */
    public function prepareColumns(): void
    {
        $this->addColumn([
            'index' => 'nome',
            'label' => trans('partinventory::app.services.index.datagrid.nome'),
            'type' => 'string',
            'searchable' => true,
            'sortable' => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index' => 'category_name',
            'label' => trans('partinventory::app.services.index.datagrid.grupo'),
            'type' => 'string',
            'sortable' => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index' => 'preco_padrao',
            'label' => trans('partinventory::app.services.index.datagrid.preco'),
            'type' => 'string',
            'sortable' => true,
            'closure' => function ($row) {
                return core()->formatBasePrice($row->preco_padrao);
            },
        ]);

        $this->addColumn([
            'index' => 'created_at',
            'label' => trans('partinventory::app.services.index.datagrid.created-at'),
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
        if (bouncer()->hasPermission('services.edit')) {
            $this->addAction([
                'icon' => 'icon-edit',
                'title' => trans('partinventory::app.services.index.datagrid.edit'),
                'method' => 'GET',
                'url' => function ($row) {
                    return route('admin.services.edit', $row->id);
                },
            ]);
        }

        if (bouncer()->hasPermission('services.delete')) {
            $this->addAction([
                'icon' => 'icon-delete',
                'title' => trans('partinventory::app.services.index.datagrid.delete'),
                'method' => 'DELETE',
                'url' => function ($row) {
                    return route('admin.services.destroy', $row->id);
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
