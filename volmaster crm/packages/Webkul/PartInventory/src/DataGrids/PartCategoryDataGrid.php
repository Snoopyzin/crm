<?php

namespace Webkul\PartInventory\DataGrids;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

class PartCategoryDataGrid extends DataGrid
{
    /**
     * Prepare query builder.
     */
    public function prepareQueryBuilder(): Builder
    {
        $queryBuilder = DB::table('part_categories')
            ->leftJoin('parts', 'parts.part_category_id', '=', 'part_categories.id')
            ->leftJoin('services', 'services.part_category_id', '=', 'part_categories.id')
            ->select(
                'part_categories.id',
                'part_categories.name',
                'part_categories.created_at'
            )
            ->addSelect(DB::raw('COUNT(DISTINCT '.DB::getTablePrefix().'parts.id) as total_parts'))
            ->addSelect(DB::raw('COUNT(DISTINCT '.DB::getTablePrefix().'services.id) as total_services'))
            ->groupBy('part_categories.id', 'part_categories.name', 'part_categories.created_at');

        $this->addFilter('id', 'part_categories.id');

        return $queryBuilder;
    }

    /**
     * Prepare columns.
     */
    public function prepareColumns(): void
    {
        $this->addColumn([
            'index' => 'name',
            'label' => trans('partinventory::app.categories.index.datagrid.name'),
            'type' => 'string',
            'searchable' => true,
            'sortable' => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index' => 'total_parts',
            'label' => trans('partinventory::app.categories.index.datagrid.total-parts'),
            'type' => 'integer',
            'sortable' => true,
        ]);

        $this->addColumn([
            'index' => 'total_services',
            'label' => trans('partinventory::app.categories.index.datagrid.total-services'),
            'type' => 'integer',
            'sortable' => true,
        ]);

        $this->addColumn([
            'index' => 'created_at',
            'label' => trans('partinventory::app.categories.index.datagrid.created-at'),
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
            'icon' => 'icon-delete',
            'title' => trans('partinventory::app.categories.index.datagrid.delete'),
            'method' => 'DELETE',
            'url' => function ($row) {
                return route('admin.part-categories.destroy', $row->id);
            },
        ]);
    }

    /**
     * Prepare mass actions.
     */
    public function prepareMassActions(): void
    {
        //
    }
}
