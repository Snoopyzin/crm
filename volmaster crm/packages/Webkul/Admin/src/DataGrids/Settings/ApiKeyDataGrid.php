<?php

namespace Webkul\Admin\DataGrids\Settings;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

class ApiKeyDataGrid extends DataGrid
{
    /**
     * Prepare query builder.
     */
    public function prepareQueryBuilder(): Builder
    {
        $queryBuilder = DB::table('api_keys')
            ->leftJoin('users', 'api_keys.user_id', '=', 'users.id')
            ->select(
                'api_keys.id',
                'api_keys.nome',
                'api_keys.tipo',
                'api_keys.observacoes',
                'api_keys.created_at',
                'users.name as user_name'
            );

        $this->addFilter('id', 'api_keys.id');
        $this->addFilter('nome', 'api_keys.nome');

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
            'index' => 'tipo',
            'label' => 'Tipo',
            'type' => 'string',
            'sortable' => true,
            'closure' => function ($row) {
                return $row->tipo === 'focusnfe' ? 'Focus NFe' : '-';
            },
        ]);

        $this->addColumn([
            'index' => 'observacoes',
            'label' => 'Observações',
            'type' => 'string',
            'sortable' => false,
            'closure' => function ($row) {
                return $row->observacoes ?: '-';
            },
        ]);

        $this->addColumn([
            'index' => 'user_name',
            'label' => 'Criado por',
            'type' => 'string',
            'sortable' => true,
            'closure' => function ($row) {
                return $row->user_name ?: '-';
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
        if (bouncer()->hasPermission('settings.other_settings.api_keys.edit')) {
            $this->addAction([
                'icon' => 'icon-edit',
                'title' => 'Editar',
                'method' => 'GET',
                'url' => function ($row) {
                    return route('admin.settings.api_keys.edit', $row->id);
                },
            ]);
        }

        if (bouncer()->hasPermission('settings.other_settings.api_keys.delete')) {
            $this->addAction([
                'icon' => 'icon-delete',
                'title' => 'Excluir',
                'method' => 'DELETE',
                'url' => function ($row) {
                    return route('admin.settings.api_keys.destroy', $row->id);
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
