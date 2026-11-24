<?php

namespace Webkul\RemoteService\DataGrids;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

class RemoteServiceTypeDataGrid extends DataGrid
{
    /**
     * Prepare query builder.
     */
    public function prepareQueryBuilder(): Builder
    {
        $queryBuilder = DB::table('remote_service_types')
            ->leftJoin('groups', 'remote_service_types.group_id', '=', 'groups.id')
            ->select(
                'remote_service_types.id',
                'remote_service_types.nome',
                'remote_service_types.created_at',
                'groups.name as group_name'
            );

        $this->addFilter('id', 'remote_service_types.id');
        $this->addFilter('nome', 'remote_service_types.nome');
        $this->addFilter('group_name', 'groups.name');

        return $queryBuilder;
    }

    /**
     * Prepare columns.
     */
    public function prepareColumns(): void
    {
        $this->addColumn([
            'index' => 'nome',
            'label' => 'Nome',
            'type' => 'string',
            'searchable' => true,
            'sortable' => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index' => 'group_name',
            'label' => 'Grupo',
            'type' => 'string',
            'sortable' => true,
            'filterable' => true,
            'closure' => function ($row) {
                return $row->group_name ?: '-';
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
    }

    /**
     * Prepare actions.
     */
    public function prepareActions(): void
    {
        $this->addAction([
            'icon' => 'icon-edit',
            'title' => 'Editar',
            'method' => 'GET',
            'url' => function ($row) {
                return route('admin.remote_services.types.edit', $row->id);
            },
        ]);

        $this->addAction([
            'icon' => 'icon-delete',
            'title' => 'Excluir',
            'method' => 'DELETE',
            'url' => function ($row) {
                return route('admin.remote_services.types.destroy', $row->id);
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
