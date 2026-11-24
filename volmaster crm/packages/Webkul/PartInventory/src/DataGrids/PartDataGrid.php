<?php

namespace Webkul\PartInventory\DataGrids;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

class PartDataGrid extends DataGrid
{
    /**
     * Prepare query builder.
     */
    public function prepareQueryBuilder(): Builder
    {
        $queryBuilder = DB::table('parts')
            ->leftJoin('part_categories', 'parts.part_category_id', '=', 'part_categories.id')
            ->leftJoin('part_inventories', 'parts.id', '=', 'part_inventories.part_id')
            ->select(
                'parts.id',
                'parts.codigo',
                'parts.nome',
                'parts.valor_unitario',
                'parts.quantidade_minima',
                'part_categories.name as category_name',
                'parts.created_at'
            )
            ->addSelect(DB::raw('COALESCE(SUM('.DB::getTablePrefix().'part_inventories.in_stock), 0) as total_in_stock'))
            ->groupBy('parts.id', 'parts.codigo', 'parts.nome', 'parts.valor_unitario', 'parts.quantidade_minima', 'part_categories.name', 'parts.created_at');

        $this->addFilter('id', 'parts.id');
        $this->addFilter('created_at', 'parts.created_at');

        return $queryBuilder;
    }

    /**
     * Prepare columns.
     */
    public function prepareColumns(): void
    {
        $this->addColumn([
            'index' => 'codigo',
            'label' => trans('partinventory::app.index.datagrid.codigo'),
            'type' => 'string',
            'searchable' => true,
            'sortable' => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index' => 'nome',
            'label' => trans('partinventory::app.index.datagrid.nome'),
            'type' => 'string',
            'searchable' => true,
            'sortable' => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index' => 'category_name',
            'label' => trans('partinventory::app.index.datagrid.categoria'),
            'type' => 'string',
            'sortable' => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index' => 'valor_unitario',
            'label' => trans('partinventory::app.index.datagrid.valor'),
            'type' => 'string',
            'sortable' => true,
            'closure' => function ($row) {
                return core()->formatBasePrice($row->valor_unitario);
            },
        ]);

        $this->addColumn([
            'index' => 'total_in_stock',
            'label' => trans('partinventory::app.index.datagrid.estoque'),
            'type' => 'string',
            'sortable' => true,
            'closure' => function ($row) {
                $lowStock = $row->total_in_stock <= $row->quantidade_minima;

                $badgeClass = $lowStock ? 'text-red-600 font-semibold' : '';

                return '<span class="'.$badgeClass.'">'.$row->total_in_stock.'</span>';
            },
        ]);

        $this->addColumn([
            'index' => 'created_at',
            'label' => trans('partinventory::app.index.datagrid.created-at'),
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
            'title' => trans('partinventory::app.index.datagrid.view'),
            'method' => 'GET',
            'url' => function ($row) {
                return route('admin.parts.view', $row->id);
            },
        ]);

        if (bouncer()->hasPermission('parts.edit')) {
            $this->addAction([
                'icon' => 'icon-edit',
                'title' => trans('partinventory::app.index.datagrid.edit'),
                'method' => 'GET',
                'url' => function ($row) {
                    return route('admin.parts.edit', $row->id);
                },
            ]);
        }

        if (bouncer()->hasPermission('parts.delete')) {
            $this->addAction([
                'icon' => 'icon-delete',
                'title' => trans('partinventory::app.index.datagrid.delete'),
                'method' => 'DELETE',
                'url' => function ($row) {
                    return route('admin.parts.destroy', $row->id);
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
