<?php

return [
    'menu' => [
        'title' => 'Baias de Trabalho',
    ],

    'acl' => [
        'title' => 'Baias de Trabalho',
        'view' => 'Visualizar',
        'create' => 'Criar',
        'edit' => 'Editar',
        'delete' => 'Excluir',
    ],

    'errors' => [
        'conflict' => 'Esta baia já está reservada nesse período.',
    ],

    'index' => [
        'title' => 'Baias de Trabalho',
        'create-btn' => 'Adicionar Baia',
        'create-success' => 'Baia criada com sucesso.',
        'update-success' => 'Baia atualizada com sucesso.',
        'delete-success' => 'Baia excluída com sucesso.',
        'delete-failed' => 'Falha ao excluir a baia.',

        'datagrid' => [
            'name' => 'Nome',
            'capacity' => 'Capacidade',
            'status' => 'Status',
            'created-at' => 'Criado em',
            'view' => 'Visualizar',
            'edit' => 'Editar',
            'delete' => 'Excluir',
        ],
    ],

    'create' => [
        'title' => 'Adicionar Baia',
        'save-btn' => 'Salvar Baia',
    ],

    'edit' => [
        'title' => 'Editar Baia',
        'save-btn' => 'Salvar Baia',
    ],

    'view' => [
        'title' => 'Baia de Trabalho',
        'assignments' => 'Atribuições',
        'assign-service-order' => 'Atribuir Ordem de Serviço',
        'assign-success' => 'Ordem de serviço atribuída com sucesso.',
        'assignment-cancelled' => 'Atribuição cancelada.',
        'no-assignments' => 'Nenhuma atribuição ativa.',
        'starts-at' => 'Início',
        'ends-at' => 'Fim',
    ],

    'form' => [
        'general' => 'Geral',
        'name' => 'Nome',
        'capacity' => 'Capacidade',
        'status' => 'Status',

        'status-options' => [
            'ativa' => 'Ativa',
            'inativa' => 'Inativa',
        ],
    ],
];
