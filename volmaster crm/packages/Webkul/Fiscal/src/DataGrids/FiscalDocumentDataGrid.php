<?php

namespace Webkul\Fiscal\DataGrids;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

class FiscalDocumentDataGrid extends DataGrid
{
    /**
     * Prepare query builder.
     */
    public function prepareQueryBuilder(): Builder
    {
        $queryBuilder = DB::table('fiscal_documents')
            ->select('id', 'quote_id', 'type', 'focus_ref', 'status', 'numero', 'created_at');

        $this->addFilter('id', 'fiscal_documents.id');

        return $queryBuilder;
    }

    /**
     * Prepare columns.
     */
    public function prepareColumns(): void
    {
        $this->addColumn([
            'index' => 'quote_id',
            'label' => trans('fiscal::app.index.datagrid.service-order'),
            'type' => 'string',
            'sortable' => true,
            'closure' => function ($row) {
                return $row->quote_id ? '#'.$row->quote_id : '-';
            },
        ]);

        $this->addColumn([
            'index' => 'type',
            'label' => trans('fiscal::app.index.datagrid.type'),
            'type' => 'string',
            'sortable' => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index' => 'numero',
            'label' => trans('fiscal::app.index.datagrid.numero'),
            'type' => 'string',
        ]);

        $this->addColumn([
            'index' => 'status',
            'label' => trans('fiscal::app.index.datagrid.status'),
            'type' => 'string',
            'sortable' => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index' => 'created_at',
            'label' => trans('fiscal::app.index.datagrid.created-at'),
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
            'title' => trans('fiscal::app.index.datagrid.view'),
            'method' => 'GET',
            'url' => function ($row) {
                return route('admin.fiscal_documents.view', $row->id);
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
