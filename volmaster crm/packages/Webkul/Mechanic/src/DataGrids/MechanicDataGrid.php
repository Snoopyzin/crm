<?php

namespace Webkul\Mechanic\DataGrids;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;
use Webkul\ServiceOrder\Services\CodigoSequencial;

class MechanicDataGrid extends DataGrid
{
    /**
     * Prepare query builder.
     */
    public function prepareQueryBuilder(): Builder
    {
        $queryBuilder = DB::table('mechanics')
            ->select(
                'id',
                'nome',
                'especialidade',
                'years_of_experience',
                'avaliacao',
                'status',
                'created_at'
            )
            // Código sequencial do mecânico (1, 2, 3...): só em bancos já atualizados.
            ->when(CodigoSequencial::disponivel('mechanics'), fn ($query) => $query->addSelect('codigo'));

        $this->addFilter('id', 'mechanics.id');
        $this->addFilter('codigo', 'mechanics.codigo');
        $this->addFilter('created_at', 'mechanics.created_at');

        return $queryBuilder;
    }

    /**
     * Prepare columns.
     */
    public function prepareColumns(): void
    {
        if (CodigoSequencial::disponivel('mechanics')) {
            $this->addColumn([
                'index' => 'codigo',
                'label' => 'Código',
                'type' => 'integer',
                'searchable' => true,
                'sortable' => true,
                'filterable' => true,
            ]);
        }

        $this->addColumn([
            'index' => 'nome',
            'label' => trans('mechanic::app.index.datagrid.nome'),
            'type' => 'string',
            'searchable' => true,
            'sortable' => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index' => 'especialidade',
            'label' => trans('mechanic::app.index.datagrid.especialidade'),
            'type' => 'string',
            'sortable' => true,
            'filterable' => true,
            'closure' => function ($row) {
                return trans('mechanic::app.form.especialidade-options.'.$row->especialidade);
            },
        ]);

        $this->addColumn([
            'index' => 'years_of_experience',
            'label' => trans('mechanic::app.index.datagrid.years'),
            'type' => 'string',
            'sortable' => true,
        ]);

        $this->addColumn([
            'index' => 'avaliacao',
            'label' => trans('mechanic::app.index.datagrid.avaliacao'),
            'type' => 'string',
            'sortable' => true,
        ]);

        $this->addColumn([
            'index' => 'status',
            'label' => trans('mechanic::app.index.datagrid.status'),
            'type' => 'string',
            'sortable' => true,
            'filterable' => true,
            'closure' => function ($row) {
                return $row->status === 'Lenda' ? '⭐ '.trans('mechanic::app.form.status-options.lenda') : trans('mechanic::app.form.status-options.ativo');
            },
        ]);

        $this->addColumn([
            'index' => 'created_at',
            'label' => trans('mechanic::app.index.datagrid.created-at'),
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
            'title' => trans('mechanic::app.index.datagrid.view'),
            'method' => 'GET',
            'url' => function ($row) {
                return route('admin.mechanics.view', $row->id);
            },
        ]);

        if (bouncer()->hasPermission('mechanics.edit')) {
            $this->addAction([
                'icon' => 'icon-edit',
                'title' => trans('mechanic::app.index.datagrid.edit'),
                'method' => 'GET',
                'url' => function ($row) {
                    return route('admin.mechanics.edit', $row->id);
                },
            ]);
        }

        if (bouncer()->hasPermission('mechanics.delete')) {
            $this->addAction([
                'icon' => 'icon-delete',
                'title' => trans('mechanic::app.index.datagrid.delete'),
                'method' => 'DELETE',
                'url' => function ($row) {
                    return route('admin.mechanics.destroy', $row->id);
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
